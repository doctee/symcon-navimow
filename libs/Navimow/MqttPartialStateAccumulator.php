<?php

declare(strict_types=1);

namespace Navimow;

use JsonException;

final class MqttPartialStateAccumulator
{
    private const FORMAT_VERSION = 1;
    private const MAX_SERIALIZED_BYTES = 4096;
    private const MAX_TASK_CODE = 1000000;
    private const MAX_TASK_PROGRESS = 10000;
    private const MAX_AREA_VALUE = 1000000000.0;
    private const ALLOWED_FIELDS = [
        'action',
        'batteryLevel',
        'boundaryKey',
        'currentMowProgress',
        'locationType',
        'locationVehicleStateCode',
        'mowingWeekArea',
        'mowingPercentage',
        'mowStartType',
        'partitionCount',
        'partitionKey',
        'subAction',
        'subtotalArea',
        'taskDelay',
        'taskTelemetryReceivedAt',
        'vehicleState',
    ];

    public static function initialState(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'fields' => [],
            'lastSourceTimestamp' => null,
            'lastReceivedAt' => 0,
        ];
    }

    public static function reduce(
        array $previous,
        array $patch,
        int $receivedAt
    ): array {
        self::assertState($previous);
        if ($receivedAt <= 0) {
            throw new MqttPayloadException(
                'MQTT reducer receipt timestamp is invalid.'
            );
        }

        $fields = $patch['fields'] ?? null;
        $sourceTimestamp = $patch['sourceTimestamp'] ?? null;
        $classification = $patch['classification'] ?? null;
        $reconciliationHint = $patch['reconciliationHint'] ?? null;
        $receiptTimestampAllowed =
            $patch['receiptTimestampAllowed'] ?? false;
        if (
            !is_array($fields)
            || ($sourceTimestamp !== null && !is_int($sourceTimestamp))
            || !is_string($classification)
            || !is_bool($reconciliationHint)
            || !is_bool($receiptTimestampAllowed)
        ) {
            throw new MqttPayloadException(
                'MQTT semantic patch is malformed.'
            );
        }

        foreach ($fields as $name => $value) {
            if (
                !is_string($name)
                || !in_array($name, self::ALLOWED_FIELDS, true)
                || !self::validFieldValue($name, $value)
            ) {
                throw new MqttPayloadException(
                    'MQTT semantic patch contains an unsupported field.'
                );
            }
        }

        if ($sourceTimestamp === null && !$receiptTimestampAllowed) {
            return self::result(
                false,
                'missing-timestamp',
                $previous,
                [],
                true,
                ['rejected' => 1, 'unknownState' => 0]
            );
        }

        $lastTimestamp = $previous['lastSourceTimestamp'];
        if (
            $sourceTimestamp !== null
            && $lastTimestamp !== null
            && $sourceTimestamp < $lastTimestamp
        ) {
            return self::result(
                false,
                'out-of-order',
                $previous,
                [],
                $reconciliationHint,
                ['rejected' => 1, 'unknownState' => 0]
            );
        }

        $next = $previous;
        $changedFields = [];
        foreach ($fields as $name => $value) {
            if (
                !array_key_exists($name, $next['fields'])
                || $next['fields'][$name] !== $value
            ) {
                $next['fields'][$name] = $value;
                $changedFields[] = $name;
            }
        }
        ksort($next['fields']);
        sort($changedFields);
        if ($sourceTimestamp !== null) {
            $next['lastSourceTimestamp'] = $sourceTimestamp;
        }
        $next['lastReceivedAt'] = $receivedAt;

        return self::result(
            true,
            $classification === 'unknown-state'
                ? 'unknown-state'
                : 'applied',
            $next,
            $changedFields,
            $reconciliationHint,
            [
                'rejected' => 0,
                'unknownState' => $classification === 'unknown-state' ? 1 : 0,
            ]
        );
    }

    public static function serializeState(array $state): string
    {
        self::assertState($state);

        try {
            $serialized = json_encode(
                $state,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'MQTT shadow state cannot be serialized.',
                0,
                $exception
            );
        }

        if (strlen($serialized) > self::MAX_SERIALIZED_BYTES) {
            throw new MqttPayloadException(
                'MQTT shadow state exceeds the serialization limit.'
            );
        }

        return $serialized;
    }

    public static function restoreAfterRestart(string $serialized): array
    {
        if (strlen($serialized) > self::MAX_SERIALIZED_BYTES) {
            throw new MqttPayloadException(
                'MQTT persisted state exceeds the serialization limit.'
            );
        }

        try {
            $decoded = json_decode(
                $serialized,
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'MQTT persisted state is invalid.',
                0,
                $exception
            );
        }
        if (!is_array($decoded)) {
            throw new MqttPayloadException(
                'MQTT persisted state must be an object.'
            );
        }
        self::assertState($decoded);

        return self::initialState();
    }

    private static function assertState(array $state): void
    {
        if (
            ($state['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || !is_array($state['fields'] ?? null)
            || !array_key_exists('lastSourceTimestamp', $state)
            || (
                $state['lastSourceTimestamp'] !== null
                && !is_int($state['lastSourceTimestamp'])
            )
            || !is_int($state['lastReceivedAt'] ?? null)
        ) {
            throw new MqttPayloadException(
                'MQTT shadow state is malformed.'
            );
        }

        foreach ($state['fields'] as $name => $value) {
            if (
                !is_string($name)
                || !in_array($name, self::ALLOWED_FIELDS, true)
                || !self::validFieldValue($name, $value)
            ) {
                throw new MqttPayloadException(
                    'MQTT shadow state contains an unsupported field.'
                );
            }
        }
    }

    private static function validFieldValue(
        string $name,
        mixed $value
    ): bool {
        if ($name === 'boundaryKey' || $name === 'partitionKey') {
            return is_string($value)
                && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
        }
        if ($name === 'taskDelay') {
            return is_bool($value);
        }
        if ($name === 'taskTelemetryReceivedAt') {
            return is_int($value) && $value > 0;
        }
        if ($name === 'partitionCount') {
            return is_int($value) && $value >= 0 && $value <= 64;
        }
        if ($name === 'currentMowProgress') {
            return is_int($value)
                && $value >= 0
                && $value <= self::MAX_TASK_PROGRESS;
        }
        if ($name === 'action' || $name === 'subAction') {
            return is_int($value)
                && $value >= -1
                && $value <= self::MAX_TASK_CODE;
        }
        if ($name === 'mowStartType') {
            return is_int($value)
                && $value >= 0
                && $value <= self::MAX_TASK_CODE;
        }
        if ($name === 'subtotalArea' || $name === 'mowingWeekArea') {
            return (is_int($value) || is_float($value))
                && is_finite((float) $value)
                && $value >= 0
                && $value <= self::MAX_AREA_VALUE;
        }

        return is_int($value) || is_float($value);
    }

    private static function result(
        bool $accepted,
        string $reason,
        array $state,
        array $changedFields,
        bool $reconciliationHint,
        array $diagnosticDeltas
    ): array {
        return [
            'accepted' => $accepted,
            'reason' => $reason,
            'state' => $state,
            'changedSemanticFields' => $changedFields,
            'reconciliationHint' => $reconciliationHint,
            'diagnosticDeltas' => $diagnosticDeltas,
        ];
    }
}
