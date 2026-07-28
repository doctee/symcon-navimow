<?php

declare(strict_types=1);

namespace Navimow;

use JsonException;

final class MqttPartialStateAccumulator
{
    private const FORMAT_VERSION = 1;
    private const MAX_SERIALIZED_BYTES = 4096;
    private const ALLOWED_FIELDS = [
        'batteryLevel',
        'locationType',
        'locationVehicleStateCode',
        'mowingPercentage',
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
        if (
            !is_array($fields)
            || ($sourceTimestamp !== null && !is_int($sourceTimestamp))
            || !is_string($classification)
            || !is_bool($reconciliationHint)
        ) {
            throw new MqttPayloadException(
                'MQTT semantic patch is malformed.'
            );
        }

        foreach ($fields as $name => $value) {
            if (
                !is_string($name)
                || !in_array($name, self::ALLOWED_FIELDS, true)
                || (!is_int($value) && !is_float($value))
            ) {
                throw new MqttPayloadException(
                    'MQTT semantic patch contains an unsupported field.'
                );
            }
        }

        if ($sourceTimestamp === null) {
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
        if ($lastTimestamp !== null && $sourceTimestamp < $lastTimestamp) {
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
        $next['lastSourceTimestamp'] = $sourceTimestamp;
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
                || (!is_int($value) && !is_float($value))
            ) {
                throw new MqttPayloadException(
                    'MQTT shadow state contains an unsupported field.'
                );
            }
        }
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
