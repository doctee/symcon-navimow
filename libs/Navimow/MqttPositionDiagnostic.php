<?php

declare(strict_types=1);

namespace Navimow;

use JsonException;

final class MqttPositionDiagnostic
{
    private const FORMAT_VERSION = 1;
    private const MAX_SAMPLES = 512;
    private const MIN_RETAIN_INTERVAL_SECONDS = 5;
    private const MAX_SERIALIZED_BYTES = 131072;
    private const MAX_ABSOLUTE_LOCAL_COORDINATE = 10000000.0;
    private const MAX_ABSOLUTE_ORIENTATION = M_PI;

    public static function initialState(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'sampleSequence' => 0,
            'latest' => null,
            'track' => [],
            'lastSourceTimestamp' => null,
            'lastRetainedAt' => 0,
            'lastVehicleStateCode' => null,
            'firstReceivedAt' => 0,
            'coordinateChangeCount' => 0,
            'pathLengthLocal' => 0.0,
            'maximumStepDistanceLocal' => 0.0,
            'maximumPositiveSourceGapMilliseconds' => 0,
            'bounds' => null,
            'outOfOrderTimestampCount' => 0,
            'downsampledCount' => 0,
            'evictedCount' => 0,
        ];
    }

    public static function reduce(
        array $previous,
        array $pose,
        int $receivedAt
    ): array {
        self::assertState($previous);
        $validated = self::validatedPose($pose, $receivedAt);

        $next = $previous;
        $next['sampleSequence']++;
        $sample = $validated + [
            'sampleSequence' => $next['sampleSequence'],
        ];

        $lastSourceTimestamp = $previous['lastSourceTimestamp'];
        if (
            $lastSourceTimestamp !== null
            && $sample['sourceTimestamp'] < $lastSourceTimestamp
        ) {
            $next['outOfOrderTimestampCount']++;
        }
        if (
            $lastSourceTimestamp !== null
            && $sample['sourceTimestamp'] > $lastSourceTimestamp
        ) {
            $next['maximumPositiveSourceGapMilliseconds'] = max(
                $previous['maximumPositiveSourceGapMilliseconds'],
                $sample['sourceTimestamp'] - $lastSourceTimestamp
            );
        }

        if ($previous['latest'] === null) {
            $next['firstReceivedAt'] = $receivedAt;
            $next['bounds'] = [
                'minimumX' => $sample['localX'],
                'maximumX' => $sample['localX'],
                'minimumY' => $sample['localY'],
                'maximumY' => $sample['localY'],
            ];
        } else {
            $deltaX = $sample['localX'] - $previous['latest']['localX'];
            $deltaY = $sample['localY'] - $previous['latest']['localY'];
            $distance = hypot($deltaX, $deltaY);
            if ($distance > 0.0) {
                $next['coordinateChangeCount']++;
                $next['pathLengthLocal'] += $distance;
                $next['maximumStepDistanceLocal'] = max(
                    $previous['maximumStepDistanceLocal'],
                    $distance
                );
            }
            $next['bounds'] = [
                'minimumX' => min(
                    $previous['bounds']['minimumX'],
                    $sample['localX']
                ),
                'maximumX' => max(
                    $previous['bounds']['maximumX'],
                    $sample['localX']
                ),
                'minimumY' => min(
                    $previous['bounds']['minimumY'],
                    $sample['localY']
                ),
                'maximumY' => max(
                    $previous['bounds']['maximumY'],
                    $sample['localY']
                ),
            ];
        }

        $stateChanged = $previous['lastVehicleStateCode'] !== null
            && $sample['vehicleStateCode']
                !== $previous['lastVehicleStateCode'];
        $retain = $previous['latest'] === null
            || $stateChanged
            || ($receivedAt - $previous['lastRetainedAt'])
                >= self::MIN_RETAIN_INTERVAL_SECONDS;

        $next['latest'] = $sample;
        $next['lastSourceTimestamp'] = $sample['sourceTimestamp'];
        $next['lastVehicleStateCode'] = $sample['vehicleStateCode'];

        if ($retain) {
            $next['track'][] = $sample;
            $next['lastRetainedAt'] = $receivedAt;
            if (count($next['track']) > self::MAX_SAMPLES) {
                array_shift($next['track']);
                $next['evictedCount']++;
            }
        } else {
            $next['downsampledCount']++;
        }

        self::assertState($next);
        self::serializeState($next);

        return $next;
    }

    public static function serializeState(array $state): string
    {
        self::assertState($state);

        try {
            $serialized = json_encode(
                $state,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'MQTT position diagnostic cannot be serialized.',
                0,
                $exception
            );
        }

        if (strlen($serialized) > self::MAX_SERIALIZED_BYTES) {
            throw new MqttPayloadException(
                'MQTT position diagnostic exceeds the serialization limit.'
            );
        }

        return $serialized;
    }

    public static function restoreState(string $serialized): array
    {
        if (strlen($serialized) > self::MAX_SERIALIZED_BYTES) {
            throw new MqttPayloadException(
                'MQTT persisted position diagnostic exceeds the limit.'
            );
        }

        try {
            $decoded = json_decode(
                $serialized,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new MqttPayloadException(
                'MQTT persisted position diagnostic is invalid.',
                0,
                $exception
            );
        }
        if (!is_array($decoded)) {
            throw new MqttPayloadException(
                'MQTT persisted position diagnostic must be an object.'
            );
        }
        self::assertState($decoded);

        return $decoded;
    }

    public static function project(array $state, int $now): array
    {
        self::assertState($state);
        if ($now <= 0) {
            throw new MqttPayloadException(
                'MQTT position diagnostic timestamp is invalid.'
            );
        }

        $latest = $state['latest'];
        return [
            'availability' => $latest === null ? 'unavailable' : 'available',
            'latest' => $latest === null ? null : $latest + [
                'ageSeconds' => max(0, $now - $latest['receivedAt']),
            ],
            'track' => $state['track'],
            'trackSummary' => [
                'firstReceivedAt' => $state['firstReceivedAt'],
                'lastReceivedAt' => $latest['receivedAt'] ?? 0,
                'coordinateChangeCount' =>
                    $state['coordinateChangeCount'],
                'pathLengthLocal' => $state['pathLengthLocal'],
                'maximumStepDistanceLocal' =>
                    $state['maximumStepDistanceLocal'],
                'maximumPositiveSourceGapMilliseconds' =>
                    $state['maximumPositiveSourceGapMilliseconds'],
                'bounds' => $state['bounds'],
            ],
            'counters' => [
                'receivedSampleCount' => $state['sampleSequence'],
                'retainedSampleCount' => count($state['track']),
                'droppedSampleCount' =>
                    $state['downsampledCount'] + $state['evictedCount'],
                'downsampledSampleCount' => $state['downsampledCount'],
                'evictedSampleCount' => $state['evictedCount'],
                'outOfOrderTimestampCount' =>
                    $state['outOfOrderTimestampCount'],
            ],
        ];
    }

    private static function validatedPose(array $pose, int $receivedAt): array
    {
        if ($receivedAt <= 0) {
            throw new MqttPayloadException(
                'MQTT position receipt timestamp is invalid.'
            );
        }

        $localX = $pose['localX'] ?? null;
        $localY = $pose['localY'] ?? null;
        $orientation = $pose['orientation'] ?? null;
        $sourceTimestamp = $pose['sourceTimestamp'] ?? null;
        $vehicleStateCode = $pose['vehicleStateCode'] ?? null;
        if (
            !self::boundedNumber(
                $localX,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || !self::boundedNumber(
                $localY,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || !self::boundedNumber(
                $orientation,
                self::MAX_ABSOLUTE_ORIENTATION
            )
            || !is_int($sourceTimestamp)
            || $sourceTimestamp <= 0
            || !is_int($vehicleStateCode)
            || $vehicleStateCode < 0
        ) {
            throw new MqttPayloadException(
                'MQTT position sample is malformed or outside bounds.'
            );
        }

        return [
            'localX' => (float) $localX,
            'localY' => (float) $localY,
            'orientation' => (float) $orientation,
            'sourceTimestamp' => $sourceTimestamp,
            'receivedAt' => $receivedAt,
            'vehicleStateCode' => $vehicleStateCode,
        ];
    }

    private static function assertState(array $state): void
    {
        if (
            ($state['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || !is_int($state['sampleSequence'] ?? null)
            || $state['sampleSequence'] < 0
            || !array_key_exists('latest', $state)
            || !is_array($state['track'] ?? null)
            || count($state['track']) > self::MAX_SAMPLES
            || !array_key_exists('lastSourceTimestamp', $state)
            || (
                $state['lastSourceTimestamp'] !== null
                && !is_int($state['lastSourceTimestamp'])
            )
            || !is_int($state['lastRetainedAt'] ?? null)
            || !array_key_exists('lastVehicleStateCode', $state)
            || (
                $state['lastVehicleStateCode'] !== null
                && !is_int($state['lastVehicleStateCode'])
            )
            || !self::nonNegativeInteger($state['firstReceivedAt'] ?? null)
            || !self::nonNegativeInteger(
                $state['coordinateChangeCount'] ?? null
            )
            || !self::nonNegativeFiniteNumber(
                $state['pathLengthLocal'] ?? null
            )
            || !self::nonNegativeFiniteNumber(
                $state['maximumStepDistanceLocal'] ?? null
            )
            || !self::nonNegativeInteger(
                $state['maximumPositiveSourceGapMilliseconds'] ?? null
            )
            || !array_key_exists('bounds', $state)
            || !self::nonNegativeInteger(
                $state['outOfOrderTimestampCount'] ?? null
            )
            || !self::nonNegativeInteger(
                $state['downsampledCount'] ?? null
            )
            || !self::nonNegativeInteger($state['evictedCount'] ?? null)
        ) {
            throw new MqttPayloadException(
                'MQTT position diagnostic state is malformed.'
            );
        }

        if ($state['bounds'] !== null) {
            self::assertBounds($state['bounds']);
        }
        if (
            ($state['latest'] === null && $state['bounds'] !== null)
            || ($state['latest'] !== null && $state['bounds'] === null)
            || ($state['latest'] === null && $state['firstReceivedAt'] !== 0)
            || ($state['latest'] !== null && $state['firstReceivedAt'] <= 0)
        ) {
            throw new MqttPayloadException(
                'MQTT position diagnostic summary is inconsistent.'
            );
        }

        if ($state['latest'] !== null) {
            self::assertSample($state['latest']);
        }
        foreach ($state['track'] as $sample) {
            if (!is_array($sample)) {
                throw new MqttPayloadException(
                    'MQTT position diagnostic track is malformed.'
                );
            }
            self::assertSample($sample);
        }
    }

    private static function assertSample(array $sample): void
    {
        if (
            count($sample) !== 7
            || !self::boundedNumber(
                $sample['localX'] ?? null,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || !self::boundedNumber(
                $sample['localY'] ?? null,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || !self::boundedNumber(
                $sample['orientation'] ?? null,
                self::MAX_ABSOLUTE_ORIENTATION
            )
            || !is_int($sample['sourceTimestamp'] ?? null)
            || $sample['sourceTimestamp'] <= 0
            || !is_int($sample['receivedAt'] ?? null)
            || $sample['receivedAt'] <= 0
            || !is_int($sample['vehicleStateCode'] ?? null)
            || $sample['vehicleStateCode'] < 0
            || !is_int($sample['sampleSequence'] ?? null)
            || $sample['sampleSequence'] <= 0
        ) {
            throw new MqttPayloadException(
                'MQTT position diagnostic sample is malformed.'
            );
        }
    }

    private static function boundedNumber(mixed $value, float $bound): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && abs((float) $value) <= $bound;
    }

    private static function assertBounds(mixed $bounds): void
    {
        if (
            !is_array($bounds)
            || count($bounds) !== 4
            || !self::boundedNumber(
                $bounds['minimumX'] ?? null,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || !self::boundedNumber(
                $bounds['maximumX'] ?? null,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || !self::boundedNumber(
                $bounds['minimumY'] ?? null,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || !self::boundedNumber(
                $bounds['maximumY'] ?? null,
                self::MAX_ABSOLUTE_LOCAL_COORDINATE
            )
            || $bounds['minimumX'] > $bounds['maximumX']
            || $bounds['minimumY'] > $bounds['maximumY']
        ) {
            throw new MqttPayloadException(
                'MQTT position diagnostic bounds are malformed.'
            );
        }
    }

    private static function nonNegativeFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && (float) $value >= 0.0;
    }

    private static function nonNegativeInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
