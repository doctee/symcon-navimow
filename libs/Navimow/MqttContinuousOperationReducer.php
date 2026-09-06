<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;
use JsonException;

final class MqttContinuousOperationReducer
{
    public const LEASE_DURATION_SECONDS = 259200;
    public const RENEWAL_LEAD_SECONDS = 86400;
    public const RENEWAL_RECHECK_SECONDS = 300;
    public const PROBE_DEADLINE_SECONDS = 180;
    public const RECOVERY_CONFIRMATION_SECONDS = 900;
    public const MAX_PROBES_PER_LEASE = 4;

    private const FORMAT_VERSION = 1;
    private const MAX_SERIALIZED_BYTES = 16384;
    private const MAX_COUNTER = 2147483647;
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';
    private const STATES = [
        'Inactive',
        'Starting',
        'Active',
        'Degraded',
        'CircuitOpen',
        'HalfOpen',
        'RecoveryConfirming',
        'Suspended',
        'Stopping',
        'CredentialsCleared',
        'Stopped',
    ];
    private const EFFECTS = [
        'none',
        'schedule-initial-connect',
        'renew-lease',
        'schedule-half-open',
        'start-half-open',
        'clear-credentials',
        'finalize-stop',
    ];
    private const STOP_REASONS = [
        'operator-disabled',
        'operator-suspended',
        'lease-expired',
        'authentication-unavailable',
        'reauthentication-required',
        'configuration-invalid',
        'ownership-invalid',
        'mode-changed',
        'registry-invalid',
        'half-open-exhausted',
        'update-incompatible',
    ];
    private const SUSPENDING_STOP_REASONS = [
        'operator-suspended',
        'lease-expired',
        'authentication-unavailable',
        'reauthentication-required',
        'half-open-exhausted',
    ];
    private const PROBE_COOLDOWNS_SECONDS = [
        1800,
        7200,
        21600,
        86400,
    ];

    /** @return array<string, int|string> */
    public static function initialState(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'state' => 'Inactive',
            'sessionSequence' => 0,
            'startedAt' => 0,
            'configurationHash' => '',
            'leaseStartedAt' => 0,
            'leaseExpiresAt' => 0,
            'renewalEligibleAt' => 0,
            'lastLeaseEvaluationAt' => 0,
            'lastLeaseRenewedAt' => 0,
            'renewalCount' => 0,
            'circuitOpenedAt' => 0,
            'circuitReason' => '',
            'halfOpenProbeCount' => 0,
            'nextProbeAt' => 0,
            'probeStartedAt' => 0,
            'probeDeadlineAt' => 0,
            'recoveryHealthySince' => 0,
            'stopReason' => '',
            'stopRequestedAt' => 0,
            'credentialsClearedAt' => 0,
            'stoppedAt' => 0,
        ];
    }

    /** @return array<string, int|string> */
    public static function restore(string $encoded): array
    {
        if ($encoded === '' || strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry is empty or oversized.'
            );
        }
        if ($encoded === '{}') {
            return self::initialState();
        }

        try {
            $decoded = json_decode(
                $encoded,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry JSON is invalid.',
                0,
                $exception
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry root is invalid.'
            );
        }

        return self::validate($decoded);
    }

    /** @param array<string, mixed> $state */
    public static function serialize(array $state): string
    {
        try {
            $encoded = json_encode(
                self::validate($state),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry cannot be serialized.',
                0,
                $exception
            );
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry exceeds the size limit.'
            );
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function start(
        array $state,
        int $now,
        string $configurationHash
    ): array {
        $state = self::validate($state);
        self::positiveTimestamp($now);
        if (preg_match(self::HASH_PATTERN, $configurationHash) !== 1) {
            throw new InvalidArgumentException(
                'Continuous MQTT configuration hash is invalid.'
            );
        }
        if (!in_array($state['state'], ['Inactive', 'Stopped', 'Suspended'], true)) {
            throw new InvalidArgumentException(
                'Continuous MQTT operation cannot start from this state.'
            );
        }

        $sessionSequence = self::increment($state['sessionSequence']);
        $state = self::initialState();
        $state['state'] = 'Starting';
        $state['sessionSequence'] = $sessionSequence;
        $state['startedAt'] = $now;
        $state['configurationHash'] = $configurationHash;
        $state['leaseStartedAt'] = $now;
        $state['leaseExpiresAt'] = $now + self::LEASE_DURATION_SECONDS;
        $state['renewalEligibleAt'] = $state['leaseExpiresAt']
            - self::RENEWAL_LEAD_SECONDS;

        return self::decision($state, 'schedule-initial-connect');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function leaseDecision(
        array $state,
        int $now,
        bool $renewalEligible
    ): array {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if (
            !in_array(
                $state['state'],
                ['Starting', 'Active', 'Degraded', 'RecoveryConfirming', 'CircuitOpen'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Continuous MQTT lease cannot be evaluated in this state.'
            );
        }
        if ($now >= $state['leaseExpiresAt']) {
            return self::requestStop($state, $now, 'lease-expired');
        }

        $state['lastLeaseEvaluationAt'] = $now;
        if ($now < $state['renewalEligibleAt'] || !$renewalEligible) {
            return self::decision($state, 'none');
        }
        if ($state['state'] !== 'Active') {
            return self::decision($state, 'none');
        }

        $state['leaseStartedAt'] = $now;
        $state['leaseExpiresAt'] = $now + self::LEASE_DURATION_SECONDS;
        $state['renewalEligibleAt'] = $state['leaseExpiresAt']
            - self::RENEWAL_LEAD_SECONDS;
        $state['lastLeaseRenewedAt'] = $now;
        $state['renewalCount'] = self::increment($state['renewalCount']);

        return self::decision($state, 'renew-lease');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function openCircuit(
        array $state,
        int $now,
        string $reason
    ): array {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if (
            !in_array(
                $state['state'],
                ['Starting', 'Active', 'Degraded', 'RecoveryConfirming'],
                true
            ) || !self::reason($reason)
        ) {
            throw new InvalidArgumentException(
                'Continuous MQTT circuit-open transition is invalid.'
            );
        }
        if ($now >= $state['leaseExpiresAt']) {
            return self::requestStop($state, $now, 'lease-expired');
        }

        $state['state'] = 'CircuitOpen';
        $state['circuitOpenedAt'] = $now;
        $state['circuitReason'] = $reason;
        $state['nextProbeAt'] = min(
            $state['leaseExpiresAt'],
            $now + self::PROBE_COOLDOWNS_SECONDS[0]
        );
        $state['probeStartedAt'] = 0;
        $state['probeDeadlineAt'] = 0;
        $state['recoveryHealthySince'] = 0;

        return self::decision($state, 'schedule-half-open');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function halfOpenDecision(
        array $state,
        int $now,
        bool $prerequisitesReady
    ): array {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if ($state['state'] !== 'CircuitOpen') {
            throw new InvalidArgumentException(
                'Continuous MQTT half-open decision requires CircuitOpen.'
            );
        }
        if ($now >= $state['leaseExpiresAt']) {
            return self::requestStop($state, $now, 'lease-expired');
        }
        if ($now < $state['nextProbeAt']) {
            return self::decision($state, 'schedule-half-open');
        }
        if (!$prerequisitesReady) {
            $state['nextProbeAt'] = min(
                $state['leaseExpiresAt'],
                $now + self::RENEWAL_RECHECK_SECONDS
            );
            return self::decision($state, 'schedule-half-open');
        }
        if ($state['halfOpenProbeCount'] >= self::MAX_PROBES_PER_LEASE) {
            return self::requestStop($state, $now, 'half-open-exhausted');
        }

        $state['state'] = 'HalfOpen';
        $state['halfOpenProbeCount'] = self::increment(
            $state['halfOpenProbeCount']
        );
        $state['nextProbeAt'] = 0;
        $state['probeStartedAt'] = $now;
        $state['probeDeadlineAt'] = min(
            $state['leaseExpiresAt'],
            $now + self::PROBE_DEADLINE_SECONDS
        );
        $state['recoveryHealthySince'] = 0;

        return self::decision($state, 'start-half-open');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function halfOpenConnected(array $state, int $now): array
    {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if ($state['state'] !== 'HalfOpen' || $now > $state['probeDeadlineAt']) {
            throw new InvalidArgumentException(
                'Continuous MQTT probe cannot enter recovery confirmation.'
            );
        }
        $state['state'] = 'RecoveryConfirming';
        $state['recoveryHealthySince'] = $now;

        return self::decision($state, 'none');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function halfOpenFailed(
        array $state,
        int $now,
        string $reason
    ): array {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if (
            !in_array($state['state'], ['HalfOpen', 'RecoveryConfirming'], true)
            || !self::reason($reason)
        ) {
            throw new InvalidArgumentException(
                'Continuous MQTT half-open failure is invalid.'
            );
        }
        if ($now >= $state['leaseExpiresAt']) {
            return self::requestStop($state, $now, 'lease-expired');
        }
        if ($state['halfOpenProbeCount'] >= self::MAX_PROBES_PER_LEASE) {
            return self::requestStop($state, $now, 'half-open-exhausted');
        }

        $cooldown = self::PROBE_COOLDOWNS_SECONDS[
            $state['halfOpenProbeCount']
        ];
        $state['state'] = 'CircuitOpen';
        $state['circuitOpenedAt'] = $now;
        $state['circuitReason'] = $reason;
        $state['nextProbeAt'] = min(
            $state['leaseExpiresAt'],
            $now + $cooldown
        );
        $state['probeStartedAt'] = 0;
        $state['probeDeadlineAt'] = 0;
        $state['recoveryHealthySince'] = 0;

        return self::decision($state, 'schedule-half-open');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function observeRecoveryHealth(
        array $state,
        int $now,
        bool $healthy
    ): array {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if ($state['state'] === 'Starting') {
            if (!$healthy) {
                $state['state'] = 'Degraded';
                return self::decision($state, 'none');
            }
            $state['state'] = 'Active';
            return self::decision($state, 'none');
        }
        if ($state['state'] !== 'RecoveryConfirming') {
            throw new InvalidArgumentException(
                'Continuous MQTT recovery observation requires confirmation.'
            );
        }
        if (!$healthy) {
            return self::halfOpenFailed($state, $now, 'recovery-unhealthy');
        }
        if ($now >= $state['leaseExpiresAt']) {
            return self::requestStop($state, $now, 'lease-expired');
        }
        if (
            $now - $state['recoveryHealthySince']
                < self::RECOVERY_CONFIRMATION_SECONDS
        ) {
            return self::decision($state, 'none');
        }

        $state['state'] = 'Active';
        $state['circuitOpenedAt'] = 0;
        $state['circuitReason'] = '';
        $state['halfOpenProbeCount'] = 0;
        $state['nextProbeAt'] = 0;
        $state['probeStartedAt'] = 0;
        $state['probeDeadlineAt'] = 0;
        $state['recoveryHealthySince'] = 0;

        return self::decision($state, 'none');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function requestStop(
        array $state,
        int $now,
        string $reason
    ): array {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if (!in_array($reason, self::STOP_REASONS, true)) {
            throw new InvalidArgumentException(
                'Continuous MQTT stop reason is invalid.'
            );
        }
        if (in_array($state['state'], ['CredentialsCleared', 'Stopped'], true)) {
            throw new InvalidArgumentException(
                'Continuous MQTT stop cannot be requested in this state.'
            );
        }
        if ($state['state'] !== 'Stopping') {
            $state['state'] = 'Stopping';
            $state['stopReason'] = $reason;
            $state['stopRequestedAt'] = $now;
        }

        return self::decision($state, 'clear-credentials');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function credentialsCleared(array $state, int $now): array
    {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if ($state['state'] !== 'Stopping') {
            throw new InvalidArgumentException(
                'Continuous MQTT credentials can only clear while stopping.'
            );
        }
        $state['state'] = 'CredentialsCleared';
        $state['credentialsClearedAt'] = $now;

        return self::decision($state, 'finalize-stop');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    public static function stopped(array $state, int $now): array
    {
        $state = self::validate($state);
        self::monotonicTimestamp($state, $now);
        if ($state['state'] !== 'CredentialsCleared') {
            throw new InvalidArgumentException(
                'Continuous MQTT stop can only finalize after cleanup.'
            );
        }
        $state['state'] = in_array(
            $state['stopReason'],
            self::SUSPENDING_STOP_REASONS,
            true
        ) ? 'Suspended' : 'Stopped';
        $state['stoppedAt'] = $now;
        $state['leaseStartedAt'] = 0;
        $state['leaseExpiresAt'] = 0;
        $state['renewalEligibleAt'] = 0;
        $state['nextProbeAt'] = 0;
        $state['probeStartedAt'] = 0;
        $state['probeDeadlineAt'] = 0;
        $state['recoveryHealthySince'] = 0;

        return self::decision($state, 'none');
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, int|string>
     */
    public static function project(array $state, int $now): array
    {
        $state = self::validate($state);
        self::positiveTimestamp($now);

        return $state + [
            'leaseRemainingSeconds' => $state['leaseExpiresAt'] > 0
                ? max(0, $state['leaseExpiresAt'] - $now)
                : 0,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, int|string>
     */
    private static function validate(array $state): array
    {
        $canonical = self::initialState();
        if (array_keys($state) !== array_keys($canonical)) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry keys are invalid.'
            );
        }
        if (
            ($state['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || !is_string($state['state'] ?? null)
            || !in_array($state['state'], self::STATES, true)
            || !is_string($state['configurationHash'] ?? null)
            || ($state['configurationHash'] !== ''
                && preg_match(
                    self::HASH_PATTERN,
                    $state['configurationHash']
                ) !== 1)
            || !is_string($state['circuitReason'] ?? null)
            || !self::reason($state['circuitReason'], true)
            || !is_string($state['stopReason'] ?? null)
            || ($state['stopReason'] !== ''
                && !in_array($state['stopReason'], self::STOP_REASONS, true))
        ) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry values are invalid.'
            );
        }
        foreach ($canonical as $key => $default) {
            if (
                is_int($default)
                && (!is_int($state[$key]) || $state[$key] < 0)
            ) {
                throw new InvalidArgumentException(
                    'Continuous MQTT registry timestamp or counter is invalid.'
                );
            }
        }
        if (
            $state['halfOpenProbeCount'] > self::MAX_PROBES_PER_LEASE
            || ($state['leaseStartedAt'] > 0
                && $state['leaseStartedAt'] < $state['startedAt'])
            || ($state['leaseExpiresAt'] > 0
                && $state['leaseExpiresAt'] < $state['leaseStartedAt'])
            || ($state['leaseStartedAt'] > 0
                && $state['renewalEligibleAt'] > 0
                && ($state['renewalEligibleAt'] < $state['leaseStartedAt']
                    || $state['renewalEligibleAt'] > $state['leaseExpiresAt']))
            || ($state['lastLeaseRenewedAt'] > 0
                && $state['lastLeaseRenewedAt'] < $state['startedAt'])
            || ($state['probeDeadlineAt'] > 0
                && $state['probeDeadlineAt'] < $state['probeStartedAt'])
            || ($state['credentialsClearedAt'] > 0
                && $state['credentialsClearedAt'] < $state['stopRequestedAt'])
            || ($state['stoppedAt'] > 0
                && $state['stoppedAt'] < $state['credentialsClearedAt'])
        ) {
            throw new InvalidArgumentException(
                'Continuous MQTT registry chronology is invalid.'
            );
        }

        /** @var array<string, int|string> $state */
        return $state;
    }

    /**
     * @param array<string, int|string> $state
     *
     * @return array{registry: array<string, int|string>, effect: string}
     */
    private static function decision(array $state, string $effect): array
    {
        if (!in_array($effect, self::EFFECTS, true)) {
            throw new InvalidArgumentException(
                'Continuous MQTT effect is invalid.'
            );
        }

        return ['registry' => self::validate($state), 'effect' => $effect];
    }

    /** @param array<string, int|string> $state */
    private static function monotonicTimestamp(array $state, int $now): void
    {
        self::positiveTimestamp($now);
        $latest = max(
            $state['startedAt'],
            $state['lastLeaseEvaluationAt'],
            $state['lastLeaseRenewedAt'],
            $state['circuitOpenedAt'],
            $state['probeStartedAt'],
            $state['recoveryHealthySince'],
            $state['stopRequestedAt'],
            $state['credentialsClearedAt'],
            $state['stoppedAt']
        );
        if ($now < $latest) {
            throw new InvalidArgumentException(
                'Continuous MQTT timestamp moved backwards.'
            );
        }
    }

    private static function positiveTimestamp(int $timestamp): void
    {
        if ($timestamp <= 0) {
            throw new InvalidArgumentException(
                'Continuous MQTT timestamp must be positive.'
            );
        }
    }

    private static function increment(int $value): int
    {
        return min(self::MAX_COUNTER, max(0, $value) + 1);
    }

    private static function reason(string $reason, bool $allowEmpty = false): bool
    {
        return ($allowEmpty && $reason === '')
            || preg_match('/^[a-z0-9-]{1,64}$/D', $reason) === 1;
    }
}
