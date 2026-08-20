<?php

declare(strict_types=1);

namespace Navimow;

use JsonException;

final class MqttPayloadParser
{
    private const MAX_PAYLOAD_BYTES = 32768;
    private const MAX_JSON_DEPTH = 32;
    private const MAX_LOCATION_ENTRIES = 64;
    private const MAX_FIELDS_PER_ENTRY = 64;
    private const MAX_PARTITION_IDS = 64;
    private const MAX_TASK_CODE = 1000000;
    private const MAX_TASK_PROGRESS = 10000;
    private const MAX_AREA_VALUE = 1000000000.0;
    private const CHANNELS = [
        'state',
        'event',
        'attributes',
        'location',
    ];
    private const GEOMETRY_FIELDS = [
        'postureTheta',
        'postureX',
        'postureY',
    ];
    private const MAX_ABSOLUTE_LOCAL_COORDINATE = 10000000.0;
    private const MAX_ABSOLUTE_ORIENTATION = M_PI;

    public static function parse(
        string $topic,
        string $payload,
        string $expectedDeviceId,
        int $receivedAt
    ): array {
        if ($receivedAt <= 0) {
            throw new MqttPayloadException(
                'MQTT local receipt timestamp is invalid.'
            );
        }

        $channel = self::parseTopic($topic, $expectedDeviceId);
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new MqttPayloadException(
                'MQTT payload exceeds the 32,768-byte limit.'
            );
        }

        try {
            $decoded = json_decode(
                $payload,
                true,
                self::MAX_JSON_DEPTH,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'MQTT payload is not valid UTF-8 JSON.',
                0,
                $exception
            );
        }

        if ($channel === 'location') {
            $patches = self::parseLocation($decoded, $receivedAt);
        } elseif ($channel === 'state') {
            $patches = [
                self::parseState($decoded, $expectedDeviceId),
            ];
        } else {
            throw new MqttPayloadException(
                sprintf(
                    'MQTT %s payload contract is not fixture-backed.',
                    $channel
                )
            );
        }

        return [
            'channel' => $channel,
            'deviceId' => $expectedDeviceId,
            'receivedAt' => $receivedAt,
            'patches' => $patches,
        ];
    }

    private static function parseTopic(
        string $topic,
        string $expectedDeviceId
    ): string {
        if (
            $expectedDeviceId === ''
            || strlen($expectedDeviceId) > 128
            || strpbrk($expectedDeviceId, '/#+') !== false
        ) {
            throw new MqttPayloadException(
                'Expected MQTT device ID is invalid.'
            );
        }

        foreach (self::CHANNELS as $channel) {
            $expectedTopic = sprintf(
                '/downlink/vehicle/%s/realtimeDate/%s',
                $expectedDeviceId,
                $channel
            );
            if (hash_equals($expectedTopic, $topic)) {
                return $channel;
            }
        }

        throw new MqttPayloadException(
            'MQTT topic is outside the exact per-device allowlist.'
        );
    }

    private static function parseLocation(
        mixed $decoded,
        int $receivedAt
    ): array {
        if (
            !is_array($decoded)
            || !array_is_list($decoded)
            || $decoded === []
        ) {
            throw new MqttPayloadException(
                'MQTT location payload must be a non-empty JSON array.'
            );
        }
        if (count($decoded) > self::MAX_LOCATION_ENTRIES) {
            throw new MqttPayloadException(
                'MQTT location payload contains too many entries.'
            );
        }

        $patches = [];
        foreach ($decoded as $entry) {
            if (
                !is_array($entry)
                || $entry === []
                || array_is_list($entry)
                || count($entry) > self::MAX_FIELDS_PER_ENTRY
            ) {
                throw new MqttPayloadException(
                    'MQTT location entry must be a bounded non-empty object.'
                );
            }
            $patches[] = self::parseLocationEntry($entry, $receivedAt);
        }

        return $patches;
    }

    private static function parseLocationEntry(
        array $entry,
        int $receivedAt
    ): array {
        $fields = [];
        $areaIdentity = [];
        $sourceTimestamp = null;
        $nullFieldCount = 0;
        $unknownFieldCount = 0;
        $geometryPresent = false;
        $geometry = [];
        $taskTelemetryPresent = false;
        $receiptTimestampAllowed = false;

        foreach ($entry as $name => $value) {
            if ($value === null) {
                $nullFieldCount++;
                if (in_array($name, self::GEOMETRY_FIELDS, true)) {
                    $geometryPresent = true;
                }
                continue;
            }

            if (in_array($name, self::GEOMETRY_FIELDS, true)) {
                $number = self::finiteNumber('geometry', $value);
                $bound = $name === 'postureTheta'
                    ? self::MAX_ABSOLUTE_ORIENTATION
                    : self::MAX_ABSOLUTE_LOCAL_COORDINATE;
                if (abs($number) > $bound) {
                    throw new MqttPayloadException(
                        'MQTT geometry field is outside the allowed bounds.'
                    );
                }
                $geometry[$name] = $number;
                $geometryPresent = true;
                continue;
            }

            if ($name === 'time') {
                if (!is_int($value)) {
                    throw new MqttPayloadException(
                        'MQTT location timestamp must be an integer.'
                    );
                }
                $sourceTimestamp = $value;
                continue;
            }

            if ($name === 'type') {
                if (!is_int($value)) {
                    throw new MqttPayloadException(
                        'MQTT location type must be an integer.'
                    );
                }
                $fields['locationType'] = $value;
                continue;
            }

            if ($name === 'vehicleState') {
                if (!is_int($value)) {
                    throw new MqttPayloadException(
                        'MQTT numeric vehicle state must be an integer.'
                    );
                }
                $fields['locationVehicleStateCode'] = $value;
                continue;
            }

            if ($name === 'mowingPercentage') {
                $percentage = self::finiteNumber(
                    'mowing percentage',
                    $value
                );
                if ($percentage < 0 || $percentage > 100) {
                    throw new MqttPayloadException(
                        'MQTT mowing percentage is outside the allowed bounds.'
                    );
                }
                $fields['mowingPercentage'] = $percentage;
                $taskTelemetryPresent = true;
                continue;
            }

            if ($name === 'action' || $name === 'subAction') {
                $fields[$name] = self::boundedInteger(
                    'task action',
                    $value,
                    -1,
                    self::MAX_TASK_CODE
                );
                $taskTelemetryPresent = true;
                continue;
            }

            if ($name === 'mowStartType') {
                $fields['mowStartType'] = self::boundedInteger(
                    'mow start type',
                    $value,
                    0,
                    self::MAX_TASK_CODE
                );
                $taskTelemetryPresent = true;
                continue;
            }

            if ($name === 'currentMowProgress') {
                $fields['currentMowProgress'] = self::boundedInteger(
                    'current mow progress',
                    $value,
                    0,
                    self::MAX_TASK_PROGRESS
                );
                $taskTelemetryPresent = true;
                continue;
            }

            if ($name === 'subtotalArea' || $name === 'mowingWeekArea') {
                $area = self::finiteNumber('task area', $value);
                if ($area < 0 || $area > self::MAX_AREA_VALUE) {
                    throw new MqttPayloadException(
                        'MQTT task area is outside the allowed bounds.'
                    );
                }
                $fields[$name] = $area;
                $taskTelemetryPresent = true;
                continue;
            }

            if ($name === 'currentMowBoundary') {
                $areaIdentity['boundaryId'] = self::boundedInteger(
                    'mow boundary identifier',
                    $value,
                    0,
                    self::MAX_TASK_CODE
                );
                $taskTelemetryPresent = true;
                continue;
            }

            if ($name === 'partitionIds') {
                if (
                    !is_array($value)
                    || !array_is_list($value)
                    || $value === []
                    || count($value) > self::MAX_PARTITION_IDS
                ) {
                    throw new MqttPayloadException(
                        'MQTT partition identifiers must be a bounded non-empty list.'
                    );
                }
                $partitionIds = [];
                foreach ($value as $partitionId) {
                    $partitionIds[] = self::boundedInteger(
                        'partition identifier',
                        $partitionId,
                        0,
                        self::MAX_TASK_CODE
                    );
                }
                $areaIdentity['partitionIds'] = $partitionIds;
                $taskTelemetryPresent = true;
                continue;
            }

            if ($name === 'taskDelay') {
                if (!is_bool($value)) {
                    throw new MqttPayloadException(
                        'MQTT task delay must be boolean.'
                    );
                }
                $fields['taskDelay'] = $value;
                $taskTelemetryPresent = true;
                $receiptTimestampAllowed = true;
                continue;
            }

            if ($name === 'mapWorkPosition') {
                if (
                    !is_string($value)
                    || preg_match('/^[a-fA-F0-9]{128}$/D', $value) !== 1
                ) {
                    throw new MqttPayloadException(
                        'MQTT opaque work position is invalid.'
                    );
                }
                $taskTelemetryPresent = true;
                continue;
            }

            $unknownFieldCount++;
        }

        if ($taskTelemetryPresent) {
            $fields['taskTelemetryReceivedAt'] = $receivedAt;
        }

        $pose = null;
        if (
            $sourceTimestamp !== null
            && isset(
                $geometry['postureX'],
                $geometry['postureY'],
                $geometry['postureTheta']
            )
            && is_int($fields['locationVehicleStateCode'] ?? null)
        ) {
            $pose = [
                'localX' => $geometry['postureX'],
                'localY' => $geometry['postureY'],
                'orientation' => $geometry['postureTheta'],
                'sourceTimestamp' => $sourceTimestamp,
                'vehicleStateCode' =>
                    $fields['locationVehicleStateCode'],
            ];
        }

        return [
            'fields' => $fields,
            'sourceTimestamp' => $sourceTimestamp,
            'classification' => $sourceTimestamp === null
                ? ($receiptTimestampAllowed
                    ? 'receipt-timestamped-task'
                    : 'missing-timestamp')
                : 'location',
            'reconciliationHint' => true,
            'receiptTimestampAllowed' => $receiptTimestampAllowed,
            'areaIdentity' => $areaIdentity === []
                ? null
                : $areaIdentity,
            'nullFieldCount' => $nullFieldCount,
            'unknownFieldCount' => $unknownFieldCount,
            'geometryPresent' => $geometryPresent,
            'pose' => $pose,
        ];
    }

    private static function parseState(
        mixed $decoded,
        string $expectedDeviceId
    ): array {
        if (
            !is_array($decoded)
            || $decoded === []
            || array_is_list($decoded)
            || count($decoded) > self::MAX_FIELDS_PER_ENTRY
        ) {
            throw new MqttPayloadException(
                'MQTT state payload must be a bounded non-empty JSON object.'
            );
        }

        $deviceId = $decoded['device_id'] ?? null;
        $state = $decoded['state'] ?? null;
        $battery = $decoded['battery'] ?? null;
        $timestamp = $decoded['timestamp'] ?? null;
        if (
            !is_string($deviceId)
            || !hash_equals($expectedDeviceId, $deviceId)
        ) {
            throw new MqttPayloadException(
                'MQTT state payload device ID does not match the topic.'
            );
        }
        if (
            !is_string($state)
            || preg_match('/^is[A-Za-z0-9]{1,62}$/D', $state) !== 1
        ) {
            throw new MqttPayloadException(
                'MQTT state payload state is invalid.'
            );
        }
        if (!is_int($battery) || $battery < 0 || $battery > 100) {
            throw new MqttPayloadException(
                'MQTT state payload battery is invalid.'
            );
        }
        if (!is_int($timestamp)) {
            throw new MqttPayloadException(
                'MQTT state payload timestamp is invalid.'
            );
        }

        $vehicleState = PayloadMapper::mapVehicleStateName($state);
        $fields = ['batteryLevel' => $battery];
        $classification = 'unknown-state';
        if ($vehicleState !== PayloadMapper::VEHICLE_STATE_UNKNOWN) {
            $fields['vehicleState'] = $vehicleState;
            $classification = 'known-state';
        }

        $knownFields = ['battery', 'device_id', 'state', 'timestamp'];

        return [
            'fields' => $fields,
            'sourceTimestamp' => $timestamp,
            'classification' => $classification,
            'reconciliationHint' => true,
            'nullFieldCount' => 0,
            'unknownFieldCount' => count(
                array_diff(array_keys($decoded), $knownFields)
            ),
            'geometryPresent' => false,
        ];
    }

    private static function finiteNumber(
        string $fieldClass,
        mixed $value
    ): float {
        if (
            !is_int($value)
            && !is_float($value)
            && !(
                is_string($value)
                && preg_match(
                    '/^-?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/D',
                    $value
                ) === 1
            )
        ) {
            throw new MqttPayloadException(
                sprintf('MQTT %s field must be numeric.', $fieldClass)
            );
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            throw new MqttPayloadException(
                sprintf('MQTT %s field must be finite.', $fieldClass)
            );
        }

        return $number;
    }

    private static function boundedInteger(
        string $fieldClass,
        mixed $value,
        int $minimum,
        int $maximum
    ): int {
        if (
            !is_int($value)
            || $value < $minimum
            || $value > $maximum
        ) {
            throw new MqttPayloadException(
                sprintf(
                    'MQTT %s field is outside the allowed bounds.',
                    $fieldClass
                )
            );
        }

        return $value;
    }
}
