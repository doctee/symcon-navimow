<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;
use JsonException;

final class MqttPathSegmenter
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';
    private const MAX_INPUT_POINTS = 2048;
    private const MAX_RETAINED_POINTS = 512;
    private const MAX_SEGMENTS = 64;
    private const MAX_SERIALIZED_BYTES = 131072;

    public static function build(
        array $points,
        array $passes,
        array $options = []
    ): array {
        if (!array_is_list($points) || count($points) > self::MAX_INPUT_POINTS) {
            throw new InvalidArgumentException('Position input is not bounded.');
        }

        $policy = self::policy($options);
        $normalizedPasses = self::passes($passes);
        $segments = [];
        $latest = null;
        $previous = null;
        $retainedPointCount = 0;
        $downsampledPointCount = 0;

        foreach ($points as $point) {
            $current = self::point($point);
            $correlation = self::correlate(
                $current['receivedAt'],
                $normalizedPasses,
                $policy['joinWindowSeconds']
            );
            $current['passSequence'] = $correlation['passSequence'];
            $current['areaKey'] = $correlation['areaKey'];

            $breakReason = self::breakReason(
                $previous,
                $current,
                $policy
            );
            if ($breakReason !== null) {
                $segments[] = self::segment(
                    count($segments) + 1,
                    $current,
                    $breakReason
                );
                $retainedPointCount++;
            } else {
                $lastIndex = count($segments) - 1;
                $lastPoint = $segments[$lastIndex]['points'][
                    count($segments[$lastIndex]['points']) - 1
                ];
                $distance = self::distance($lastPoint, $current);
                $age = $current['receivedAt'] - $lastPoint['receivedAt'];
                if (
                    $age < $policy['minimumRetainSeconds']
                    && $distance < $policy['minimumRetainDistanceLocal']
                ) {
                    $downsampledPointCount++;
                } else {
                    $segments[$lastIndex]['points'][] = $current;
                    $segments[$lastIndex]['endedAt'] = $current['receivedAt'];
                    $segments[$lastIndex]['pathLengthLocal'] += $distance;
                    $retainedPointCount++;
                }
            }

            $latest = $current;
            $previous = $current;
        }

        [$segments, $evictedSegments, $evictedPoints] = self::fitRetention(
            $segments,
            $retainedPointCount
        );
        $result = [
            'formatVersion' => 1,
            'authority' => 'mqtt-inference',
            'coordinateFrame' => 'uncalibrated-local',
            'latest' => $latest,
            'segments' => $segments,
            'counters' => [
                'receivedPointCount' => count($points),
                'retainedPointCount' => $retainedPointCount - $evictedPoints,
                'downsampledPointCount' => $downsampledPointCount,
                'evictedPointCount' => $evictedPoints,
                'evictedSegmentCount' => $evictedSegments,
            ],
            'policy' => $policy,
        ];

        try {
            $encoded = json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Path projection cannot be encoded.',
                0,
                $exception
            );
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException(
                'Path projection exceeds the byte limit.'
            );
        }

        return $result;
    }

    private static function policy(array $options): array
    {
        $policy = [
            'joinWindowSeconds' => $options['joinWindowSeconds'] ?? 120,
            'maximumGapSeconds' => $options['maximumGapSeconds'] ?? 120,
            'maximumStepDistanceLocal' =>
                $options['maximumStepDistanceLocal'] ?? 50.0,
            'minimumRetainSeconds' =>
                $options['minimumRetainSeconds'] ?? 5,
            'minimumRetainDistanceLocal' =>
                $options['minimumRetainDistanceLocal'] ?? 0.5,
        ];
        if (
            !is_int($policy['joinWindowSeconds'])
            || $policy['joinWindowSeconds'] < 0
            || $policy['joinWindowSeconds'] > 900
            || !is_int($policy['maximumGapSeconds'])
            || $policy['maximumGapSeconds'] < 1
            || $policy['maximumGapSeconds'] > 3600
            || !is_int($policy['minimumRetainSeconds'])
            || $policy['minimumRetainSeconds'] < 0
            || $policy['minimumRetainSeconds'] > 300
            || !self::positiveFinite(
                $policy['maximumStepDistanceLocal'],
                10000000.0
            )
            || !self::positiveFinite(
                $policy['minimumRetainDistanceLocal'],
                1000000.0
            )
        ) {
            throw new InvalidArgumentException('Path policy is invalid.');
        }
        $policy['maximumStepDistanceLocal'] =
            (float) $policy['maximumStepDistanceLocal'];
        $policy['minimumRetainDistanceLocal'] =
            (float) $policy['minimumRetainDistanceLocal'];

        return $policy;
    }

    private static function passes(array $passes): array
    {
        if (!array_is_list($passes) || count($passes) > 64) {
            throw new InvalidArgumentException('Pass input is not bounded.');
        }
        $result = [];
        foreach ($passes as $pass) {
            if (
                !is_array($pass)
                || !is_int($pass['sequence'] ?? null)
                || !is_int($pass['startedAt'] ?? null)
                || !is_int($pass['lastObservedAt'] ?? null)
                || $pass['startedAt'] <= 0
                || $pass['lastObservedAt'] < $pass['startedAt']
            ) {
                throw new InvalidArgumentException('Pass window is invalid.');
            }
            $areaKey = $pass['partitionKey'] ?? $pass['boundaryKey'] ?? null;
            if (
                $areaKey !== null
                && (!is_string($areaKey)
                    || preg_match(self::HASH_PATTERN, $areaKey) !== 1)
            ) {
                throw new InvalidArgumentException('Area key is invalid.');
            }
            $result[] = [
                'sequence' => $pass['sequence'],
                'startedAt' => $pass['startedAt'],
                'lastObservedAt' => $pass['lastObservedAt'],
                'areaKey' => $areaKey,
            ];
        }

        return $result;
    }

    private static function point(mixed $point): array
    {
        if (!is_array($point)) {
            throw new InvalidArgumentException('Position point is invalid.');
        }
        $sessionSequence = $point['sessionSequence'] ?? 0;
        foreach (['localX', 'localY', 'orientation'] as $field) {
            if (!self::finiteNumber($point[$field] ?? null)) {
                throw new InvalidArgumentException('Position value is invalid.');
            }
        }
        foreach (
            ['sourceTimestamp', 'receivedAt', 'vehicleStateCode'] as $field
        ) {
            if (!is_int($point[$field] ?? null) || $point[$field] < 0) {
                throw new InvalidArgumentException('Position time is invalid.');
            }
        }
        if (!is_int($sessionSequence) || $sessionSequence < 0) {
            throw new InvalidArgumentException(
                'Position session sequence is invalid.'
            );
        }
        if ($point['sourceTimestamp'] <= 0 || $point['receivedAt'] <= 0) {
            throw new InvalidArgumentException('Position time is invalid.');
        }

        return [
            'localX' => (float) $point['localX'],
            'localY' => (float) $point['localY'],
            'orientation' => (float) $point['orientation'],
            'sourceTimestamp' => $point['sourceTimestamp'],
            'receivedAt' => $point['receivedAt'],
            'vehicleStateCode' => $point['vehicleStateCode'],
            'sessionSequence' => $sessionSequence,
        ];
    }

    private static function correlate(
        int $timestamp,
        array $passes,
        int $window
    ): array {
        $best = null;
        foreach ($passes as $pass) {
            $distance = $timestamp < $pass['startedAt']
                ? $pass['startedAt'] - $timestamp
                : max(0, $timestamp - $pass['lastObservedAt']);
            if ($distance > $window) {
                continue;
            }
            if (
                $best === null
                || $distance < $best['distance']
                || (
                    $distance === $best['distance']
                    && $pass['startedAt'] > $best['startedAt']
                )
            ) {
                $best = $pass + ['distance' => $distance];
            }
        }

        return [
            'passSequence' => $best['sequence'] ?? null,
            'areaKey' => $best['areaKey'] ?? null,
        ];
    }

    private static function breakReason(
        ?array $previous,
        array $current,
        array $policy
    ): ?string {
        if ($previous === null) {
            return 'first-point';
        }
        if ($current['sourceTimestamp'] < $previous['sourceTimestamp']) {
            return 'source-time-regression';
        }
        if ($current['receivedAt'] <= $previous['receivedAt']) {
            return 'receive-order-regression';
        }
        if (
            $current['receivedAt'] - $previous['receivedAt']
                > $policy['maximumGapSeconds']
        ) {
            return 'time-gap';
        }
        if ($current['sessionSequence'] !== $previous['sessionSequence']) {
            return 'transport-session-change';
        }
        if ($current['areaKey'] !== $previous['areaKey']) {
            return 'area-correlation-change';
        }
        if (
            $current['vehicleStateCode']
                !== $previous['vehicleStateCode']
        ) {
            return 'vehicle-state-change';
        }
        if (
            self::distance($previous, $current)
                > $policy['maximumStepDistanceLocal']
        ) {
            return 'coordinate-discontinuity';
        }

        return null;
    }

    private static function segment(
        int $sequence,
        array $point,
        string $reason
    ): array {
        return [
            'sequence' => $sequence,
            'passSequence' => $point['passSequence'],
            'areaKey' => $point['areaKey'],
            'sessionSequence' => $point['sessionSequence'],
            'vehicleStateCode' => $point['vehicleStateCode'],
            'startedAt' => $point['receivedAt'],
            'endedAt' => $point['receivedAt'],
            'breakReason' => $reason,
            'pathLengthLocal' => 0.0,
            'points' => [$point],
        ];
    }

    private static function fitRetention(
        array $segments,
        int $pointCount
    ): array {
        $evictedSegments = 0;
        $evictedPoints = 0;
        while (
            count($segments) > self::MAX_SEGMENTS
            || $pointCount - $evictedPoints > self::MAX_RETAINED_POINTS
        ) {
            $removed = array_shift($segments);
            if (!is_array($removed)) {
                break;
            }
            $evictedSegments++;
            $evictedPoints += count($removed['points']);
        }

        return [$segments, $evictedSegments, $evictedPoints];
    }

    private static function distance(array $left, array $right): float
    {
        return hypot(
            $right['localX'] - $left['localX'],
            $right['localY'] - $left['localY']
        );
    }

    private static function finiteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value);
    }

    private static function positiveFinite(mixed $value, float $maximum): bool
    {
        return self::finiteNumber($value)
            && (float) $value > 0.0
            && (float) $value <= $maximum;
    }
}
