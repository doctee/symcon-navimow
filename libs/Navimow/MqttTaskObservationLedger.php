<?php

declare(strict_types=1);

namespace Navimow;

use JsonException;

final class MqttTaskObservationLedger
{
    private const FORMAT_VERSION = 1;
    private const MAX_PASSES = 32;
    private const MAX_TRANSITIONS = 64;
    private const MAX_SERIALIZED_BYTES = 65536;
    private const PROGRESS_WRAP_HIGH = 9000;
    private const PROGRESS_WRAP_LOW = 1000;
    private const COMPLETION_PROGRESS = 9900;
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';

    private const TASK_FIELDS = [
        'action',
        'boundaryKey',
        'currentMowProgress',
        'mowingPercentage',
        'mowingWeekArea',
        'mowStartType',
        'partitionCount',
        'partitionKey',
        'subAction',
        'subtotalArea',
        'taskDelay',
        'taskTelemetryReceivedAt',
    ];

    public static function initialLedger(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'nextPassSequence' => 1,
            'nextTransitionSequence' => 1,
            'passes' => [],
            'transitions' => [],
        ];
    }

    public static function reduce(
        array $previous,
        array $fields,
        string $deviceKey,
        int $receivedAt,
        int $sessionSequence
    ): array {
        self::assertLedger($previous);
        self::assertObservation(
            $fields,
            $deviceKey,
            $receivedAt,
            $sessionSequence
        );

        $taskFields = array_intersect_key(
            $fields,
            array_fill_keys(self::TASK_FIELDS, true)
        );
        if (!isset($taskFields['taskTelemetryReceivedAt'])) {
            return $previous;
        }

        $ledger = $previous;
        $passes = $ledger['passes'];
        $lastIndex = count($passes) - 1;
        $current = $lastIndex >= 0 ? $passes[$lastIndex] : null;
        $newPassReason = self::newPassReason(
            $current,
            $taskFields,
            $deviceKey
        );

        if ($newPassReason !== null) {
            $current = self::newPass(
                $ledger['nextPassSequence'],
                $taskFields,
                $deviceKey,
                $receivedAt,
                $sessionSequence
            );
            $ledger['nextPassSequence']++;
            $passes[] = $current;
            $lastIndex = count($passes) - 1;
            self::appendTransition(
                $ledger,
                $current,
                $receivedAt,
                $newPassReason,
                $taskFields
            );
        } else {
            if ($current['lastSessionSequence'] !== $sessionSequence) {
                $transitionPass = $current;
                $transitionPass['lastSessionSequence'] = $sessionSequence;
                self::appendTransition(
                    $ledger,
                    $transitionPass,
                    $receivedAt,
                    'transport-session-change',
                    $taskFields
                );
            }
            if (self::phaseChanged($current, $taskFields)) {
                self::appendTransition(
                    $ledger,
                    $current,
                    $receivedAt,
                    'phase-change',
                    $taskFields
                );
            }
            if (self::delayChanged($current, $taskFields)) {
                self::appendTransition(
                    $ledger,
                    $current,
                    $receivedAt,
                    'delay-change',
                    $taskFields
                );
            }
            $current = self::updatePass(
                $current,
                $taskFields,
                $receivedAt,
                $sessionSequence
            );
            $passes[$lastIndex] = $current;
        }

        if (
            $current['completionObservedAt'] === null
            && self::completionObserved($taskFields)
        ) {
            $current['completionObservedAt'] = $receivedAt;
            $passes[$lastIndex] = $current;
            self::appendTransition(
                $ledger,
                $current,
                $receivedAt,
                'completion-observed',
                $taskFields
            );
        }

        $ledger['passes'] = array_slice($passes, -self::MAX_PASSES);
        $ledger['transitions'] = array_slice(
            $ledger['transitions'],
            -self::MAX_TRANSITIONS
        );

        return self::fitSerializedLimit($ledger);
    }

    public static function serializeLedger(array $ledger): string
    {
        self::assertLedger($ledger);

        try {
            $encoded = json_encode(
                $ledger,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'MQTT task observation ledger cannot be serialized.',
                0,
                $exception
            );
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new MqttPayloadException(
                'MQTT task observation ledger exceeds the size limit.'
            );
        }

        return $encoded;
    }

    public static function restore(string $encoded): array
    {
        if ($encoded === '' || $encoded === '{}') {
            return self::initialLedger();
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new MqttPayloadException(
                'Persisted MQTT task ledger exceeds the size limit.'
            );
        }

        try {
            $ledger = json_decode(
                $encoded,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'Persisted MQTT task ledger is invalid.',
                0,
                $exception
            );
        }
        if (!is_array($ledger)) {
            throw new MqttPayloadException(
                'Persisted MQTT task ledger must be an object.'
            );
        }
        self::assertLedger($ledger);

        return $ledger;
    }

    public static function project(array $ledger): array
    {
        self::assertLedger($ledger);
        $passes = array_map(
            static fn (array $pass): array => [
                'sequence' => $pass['sequence'],
                'firstSessionSequence' => $pass['firstSessionSequence'],
                'lastSessionSequence' => $pass['lastSessionSequence'],
                'startedAt' => $pass['startedAt'],
                'lastObservedAt' => $pass['lastObservedAt'],
                'completionObservedAt' => $pass['completionObservedAt'],
                'boundaryKey' => $pass['boundaryKey'],
                'partitionKey' => $pass['partitionKey'],
                'partitionCount' => $pass['partitionCount'],
                'firstProgress' => $pass['firstProgress'],
                'lastProgress' => $pass['lastProgress'],
                'maxProgress' => $pass['maxProgress'],
                'firstSubtotalArea' => $pass['firstSubtotalArea'],
                'lastSubtotalArea' => $pass['lastSubtotalArea'],
                'maxSubtotalArea' => $pass['maxSubtotalArea'],
                'firstWeekArea' => $pass['firstWeekArea'],
                'lastWeekArea' => $pass['lastWeekArea'],
                'maxWeekArea' => $pass['maxWeekArea'],
                'observationCount' => $pass['observationCount'],
            ],
            $ledger['passes']
        );

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'authority' => 'mqtt-inference',
            'semanticUnit' => 'correlated-zone-pass',
            'status' => $passes === [] ? 'unavailable' : 'available',
            'retainedPassCount' => count($passes),
            'retainedTransitionCount' => count($ledger['transitions']),
            'passes' => $passes,
            'transitions' => $ledger['transitions'],
        ];
    }

    private static function newPassReason(
        ?array $current,
        array $fields,
        string $deviceKey
    ): ?string {
        if ($current === null) {
            return 'first-observation';
        }
        if (!hash_equals($current['deviceKey'], $deviceKey)) {
            return 'device-change';
        }
        foreach (['boundaryKey', 'partitionKey'] as $key) {
            if (
                isset($current[$key], $fields[$key])
                && !hash_equals($current[$key], $fields[$key])
            ) {
                return 'area-correlation-change';
            }
        }
        $previousProgress = $current['lastProgress'];
        $nextProgress = $fields['currentMowProgress'] ?? null;
        if (
            is_int($previousProgress)
            && is_int($nextProgress)
            && $previousProgress >= self::PROGRESS_WRAP_HIGH
            && $nextProgress <= self::PROGRESS_WRAP_LOW
        ) {
            return 'progress-wrap';
        }

        return null;
    }

    private static function newPass(
        int $sequence,
        array $fields,
        string $deviceKey,
        int $receivedAt,
        int $sessionSequence
    ): array {
        $progress = self::integerOrNull($fields['currentMowProgress'] ?? null);
        $subtotal = self::numberOrNull($fields['subtotalArea'] ?? null);
        $week = self::numberOrNull($fields['mowingWeekArea'] ?? null);

        return [
            'sequence' => $sequence,
            'deviceKey' => $deviceKey,
            'firstSessionSequence' => $sessionSequence,
            'lastSessionSequence' => $sessionSequence,
            'startedAt' => $receivedAt,
            'lastObservedAt' => $receivedAt,
            'completionObservedAt' => null,
            'boundaryKey' => self::hashOrNull($fields['boundaryKey'] ?? null),
            'partitionKey' => self::hashOrNull($fields['partitionKey'] ?? null),
            'partitionCount' => self::integerOrNull($fields['partitionCount'] ?? null),
            'firstProgress' => $progress,
            'lastProgress' => $progress,
            'maxProgress' => $progress,
            'firstSubtotalArea' => $subtotal,
            'lastSubtotalArea' => $subtotal,
            'maxSubtotalArea' => $subtotal,
            'firstWeekArea' => $week,
            'lastWeekArea' => $week,
            'maxWeekArea' => $week,
            'lastAction' => self::integerOrNull($fields['action'] ?? null),
            'lastSubAction' => self::integerOrNull($fields['subAction'] ?? null),
            'lastMowStartType' => self::integerOrNull($fields['mowStartType'] ?? null),
            'lastTaskDelay' => self::boolOrNull($fields['taskDelay'] ?? null),
            'observationCount' => 1,
        ];
    }

    private static function updatePass(
        array $pass,
        array $fields,
        int $receivedAt,
        int $sessionSequence
    ): array {
        $pass['lastSessionSequence'] = $sessionSequence;
        $pass['lastObservedAt'] = $receivedAt;
        $pass['observationCount']++;
        foreach (['boundaryKey', 'partitionKey', 'partitionCount'] as $key) {
            if ($pass[$key] === null && array_key_exists($key, $fields)) {
                $pass[$key] = $fields[$key];
            }
        }
        self::updateRange($pass, 'Progress', $fields['currentMowProgress'] ?? null);
        self::updateRange($pass, 'SubtotalArea', $fields['subtotalArea'] ?? null);
        self::updateRange($pass, 'WeekArea', $fields['mowingWeekArea'] ?? null);
        foreach (
            [
                'lastAction' => 'action',
                'lastSubAction' => 'subAction',
                'lastMowStartType' => 'mowStartType',
                'lastTaskDelay' => 'taskDelay',
            ] as $target => $source
        ) {
            if (array_key_exists($source, $fields)) {
                $pass[$target] = $fields[$source];
            }
        }

        return $pass;
    }

    private static function updateRange(
        array &$pass,
        string $suffix,
        mixed $value
    ): void {
        if (!is_int($value) && !is_float($value)) {
            return;
        }
        $first = 'first' . $suffix;
        $last = 'last' . $suffix;
        $max = 'max' . $suffix;
        $pass[$first] ??= $value;
        $pass[$last] = $value;
        $pass[$max] = $pass[$max] === null
            ? $value
            : max($pass[$max], $value);
    }

    private static function phaseChanged(array $pass, array $fields): bool
    {
        foreach (
            [
                'lastAction' => 'action',
                'lastSubAction' => 'subAction',
                'lastMowStartType' => 'mowStartType',
            ] as $current => $incoming
        ) {
            if (
                array_key_exists($incoming, $fields)
                && $pass[$current] !== null
                && $pass[$current] !== $fields[$incoming]
            ) {
                return true;
            }
        }

        return false;
    }

    private static function delayChanged(array $pass, array $fields): bool
    {
        return array_key_exists('taskDelay', $fields)
            && $pass['lastTaskDelay'] !== null
            && $pass['lastTaskDelay'] !== $fields['taskDelay'];
    }

    private static function completionObserved(array $fields): bool
    {
        $progress = $fields['currentMowProgress'] ?? null;
        if (is_int($progress) && $progress >= self::COMPLETION_PROGRESS) {
            return true;
        }

        return ($fields['mowingPercentage'] ?? -1) >= 100
            && ($progress === null || $progress >= self::PROGRESS_WRAP_HIGH);
    }

    private static function appendTransition(
        array &$ledger,
        array $pass,
        int $receivedAt,
        string $type,
        array $fields
    ): void {
        $ledger['transitions'][] = [
            'sequence' => $ledger['nextTransitionSequence'],
            'passSequence' => $pass['sequence'],
            'sessionSequence' => $pass['lastSessionSequence'],
            'occurredAt' => $receivedAt,
            'type' => $type,
            'action' => self::integerOrNull($fields['action'] ?? null),
            'subAction' => self::integerOrNull($fields['subAction'] ?? null),
            'taskDelay' => self::boolOrNull($fields['taskDelay'] ?? null),
            'progress' => self::integerOrNull($fields['currentMowProgress'] ?? null),
        ];
        $ledger['nextTransitionSequence']++;
    }

    private static function fitSerializedLimit(array $ledger): array
    {
        while (strlen(self::encodeUnchecked($ledger)) > self::MAX_SERIALIZED_BYTES) {
            if (count($ledger['transitions']) > 1) {
                array_shift($ledger['transitions']);
                continue;
            }
            if (count($ledger['passes']) > 1) {
                array_shift($ledger['passes']);
                continue;
            }
            throw new MqttPayloadException(
                'MQTT task ledger cannot fit the serialization limit.'
            );
        }

        return $ledger;
    }

    private static function encodeUnchecked(array $ledger): string
    {
        try {
            return json_encode(
                $ledger,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'MQTT task ledger cannot be encoded.',
                0,
                $exception
            );
        }
    }

    private static function assertObservation(
        array $fields,
        string $deviceKey,
        int $receivedAt,
        int $sessionSequence
    ): void {
        if (
            preg_match(self::HASH_PATTERN, $deviceKey) !== 1
            || $receivedAt <= 0
            || $sessionSequence < 0
            || (isset($fields['taskTelemetryReceivedAt'])
                && (!is_int($fields['taskTelemetryReceivedAt'])
                    || $fields['taskTelemetryReceivedAt'] <= 0))
        ) {
            throw new MqttPayloadException(
                'MQTT task ledger observation is invalid.'
            );
        }
        foreach ($fields as $name => $value) {
            if (
                in_array($name, ['boundaryKey', 'partitionKey'], true)
                && !self::validNullableHash($value)
            ) {
                throw new MqttPayloadException(
                    'MQTT task ledger correlation is invalid.'
                );
            }
            if (
                in_array(
                    $name,
                    [
                        'action',
                        'currentMowProgress',
                        'mowStartType',
                        'partitionCount',
                        'subAction',
                        'taskTelemetryReceivedAt',
                    ],
                    true
                )
                && !is_int($value)
            ) {
                throw new MqttPayloadException(
                    'MQTT task ledger integer field is invalid.'
                );
            }
            if (
                in_array(
                    $name,
                    ['mowingPercentage', 'mowingWeekArea', 'subtotalArea'],
                    true
                )
                && !is_int($value)
                && !is_float($value)
            ) {
                throw new MqttPayloadException(
                    'MQTT task ledger numeric field is invalid.'
                );
            }
            if ($name === 'taskDelay' && !is_bool($value)) {
                throw new MqttPayloadException(
                    'MQTT task ledger delay field is invalid.'
                );
            }
        }
    }

    private static function assertLedger(array $ledger): void
    {
        if (
            ($ledger['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || !is_int($ledger['nextPassSequence'] ?? null)
            || $ledger['nextPassSequence'] < 1
            || !is_int($ledger['nextTransitionSequence'] ?? null)
            || $ledger['nextTransitionSequence'] < 1
            || !is_array($ledger['passes'] ?? null)
            || !array_is_list($ledger['passes'])
            || count($ledger['passes']) > self::MAX_PASSES
            || !is_array($ledger['transitions'] ?? null)
            || !array_is_list($ledger['transitions'])
            || count($ledger['transitions']) > self::MAX_TRANSITIONS
        ) {
            throw new MqttPayloadException(
                'MQTT task observation ledger is malformed.'
            );
        }
        foreach ($ledger['passes'] as $pass) {
            if (!self::validPass($pass)) {
                throw new MqttPayloadException(
                    'MQTT task observation pass is malformed.'
                );
            }
        }
        foreach ($ledger['transitions'] as $transition) {
            if (!self::validTransition($transition)) {
                throw new MqttPayloadException(
                    'MQTT task observation transition is malformed.'
                );
            }
        }
    }

    private static function validPass(mixed $pass): bool
    {
        return is_array($pass)
            && array_keys($pass) === [
                'sequence',
                'deviceKey',
                'firstSessionSequence',
                'lastSessionSequence',
                'startedAt',
                'lastObservedAt',
                'completionObservedAt',
                'boundaryKey',
                'partitionKey',
                'partitionCount',
                'firstProgress',
                'lastProgress',
                'maxProgress',
                'firstSubtotalArea',
                'lastSubtotalArea',
                'maxSubtotalArea',
                'firstWeekArea',
                'lastWeekArea',
                'maxWeekArea',
                'lastAction',
                'lastSubAction',
                'lastMowStartType',
                'lastTaskDelay',
                'observationCount',
            ]
            && is_int($pass['sequence'] ?? null)
            && preg_match(self::HASH_PATTERN, $pass['deviceKey'] ?? '') === 1
            && is_int($pass['firstSessionSequence'] ?? null)
            && is_int($pass['lastSessionSequence'] ?? null)
            && is_int($pass['startedAt'] ?? null)
            && is_int($pass['lastObservedAt'] ?? null)
            && ($pass['completionObservedAt'] === null
                || is_int($pass['completionObservedAt']))
            && self::validNullableHash($pass['boundaryKey'] ?? null)
            && self::validNullableHash($pass['partitionKey'] ?? null)
            && self::validNullableInteger($pass['partitionCount'])
            && self::validNullableNumber($pass['firstProgress'])
            && self::validNullableNumber($pass['lastProgress'])
            && self::validNullableNumber($pass['maxProgress'])
            && self::validNullableNumber($pass['firstSubtotalArea'])
            && self::validNullableNumber($pass['lastSubtotalArea'])
            && self::validNullableNumber($pass['maxSubtotalArea'])
            && self::validNullableNumber($pass['firstWeekArea'])
            && self::validNullableNumber($pass['lastWeekArea'])
            && self::validNullableNumber($pass['maxWeekArea'])
            && self::validNullableInteger($pass['lastAction'])
            && self::validNullableInteger($pass['lastSubAction'])
            && self::validNullableInteger($pass['lastMowStartType'])
            && ($pass['lastTaskDelay'] === null
                || is_bool($pass['lastTaskDelay']))
            && is_int($pass['observationCount'] ?? null)
            && $pass['observationCount'] >= 1;
    }

    private static function validTransition(mixed $transition): bool
    {
        return is_array($transition)
            && array_keys($transition) === [
                'sequence',
                'passSequence',
                'sessionSequence',
                'occurredAt',
                'type',
                'action',
                'subAction',
                'taskDelay',
                'progress',
            ]
            && is_int($transition['sequence'] ?? null)
            && is_int($transition['passSequence'] ?? null)
            && is_int($transition['sessionSequence'] ?? null)
            && is_int($transition['occurredAt'] ?? null)
            && is_string($transition['type'] ?? null)
            && self::validNullableInteger($transition['action'])
            && self::validNullableInteger($transition['subAction'])
            && ($transition['taskDelay'] === null
                || is_bool($transition['taskDelay']))
            && self::validNullableInteger($transition['progress']);
    }

    private static function validNullableHash(mixed $value): bool
    {
        return $value === null
            || (is_string($value)
                && preg_match(self::HASH_PATTERN, $value) === 1);
    }

    private static function validNullableInteger(mixed $value): bool
    {
        return $value === null || is_int($value);
    }

    private static function validNullableNumber(mixed $value): bool
    {
        return $value === null || is_int($value) || is_float($value);
    }

    private static function hashOrNull(mixed $value): ?string
    {
        return self::validNullableHash($value) && is_string($value)
            ? $value
            : null;
    }

    private static function integerOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function numberOrNull(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
