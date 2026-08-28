<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;
use JsonException;

final class RevisionBoundedTrackStore
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';
    private const MAX_REVISIONS = 4;
    private const MAX_SEGMENTS = 64;
    private const MAX_POINTS = 2048;
    private const MAX_SERIALIZED_BYTES = 512 * 1024;

    /** @return array<string, mixed> */
    public static function initialState(): array
    {
        return [
            'formatVersion' => 1,
            'segments' => [],
            'counters' => [
                'ingestedPointCount' => 0,
                'duplicatePointCount' => 0,
                'evictedPointCount' => 0,
                'evictedSegmentCount' => 0,
                'evictedRevisionCount' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $scene
     *
     * @return array<string, mixed>
     */
    public static function ingestScene(array $state, array $scene): array
    {
        $state = self::state($state);
        $revision = $scene['revision'] ?? null;
        $path = $scene['path'] ?? null;
        if (
            !is_array($revision)
            || !is_string($revision['geometryKey'] ?? null)
            || preg_match(
                self::HASH_PATTERN,
                $revision['geometryKey']
            ) !== 1
            || ($revision['state'] ?? null) !== 'accepted'
            || ($revision['pathCompatible'] ?? null) !== true
            || !is_array($path)
            || ($path['status'] ?? null) !== 'included'
            || !is_array($path['segments'] ?? null)
            || !array_is_list($path['segments'])
            || count($path['segments']) > self::MAX_SEGMENTS
        ) {
            throw new InvalidArgumentException(
                'Scene is not eligible for track retention.'
            );
        }

        $known = [];
        foreach ($state['segments'] as $segment) {
            foreach ($segment['points'] as $point) {
                $known[self::pointKey($segment['geometryKey'], $point)] = true;
            }
        }

        foreach ($path['segments'] as $sourceSegment) {
            if (!is_array($sourceSegment)) {
                throw new InvalidArgumentException(
                    'Scene path segment is invalid.'
                );
            }
            $points = $sourceSegment['points'] ?? null;
            if (!is_array($points) || !array_is_list($points)) {
                throw new InvalidArgumentException(
                    'Scene path points are invalid.'
                );
            }
            $retained = [];
            foreach ($points as $point) {
                $normalized = self::sourcePoint($point);
                $key = self::pointKey(
                    $revision['geometryKey'],
                    $normalized
                );
                if (isset($known[$key])) {
                    ++$state['counters']['duplicatePointCount'];
                    continue;
                }
                $known[$key] = true;
                $retained[] = $normalized;
                ++$state['counters']['ingestedPointCount'];
            }
            if ($retained === []) {
                continue;
            }
            $taskZoneKey = $sourceSegment['taskZoneKey'] ?? null;
            if (
                $taskZoneKey !== null
                && (!is_string($taskZoneKey)
                    || preg_match(self::HASH_PATTERN, $taskZoneKey) !== 1)
            ) {
                throw new InvalidArgumentException(
                    'Scene task-zone key is invalid.'
                );
            }
            $state['segments'][] = [
                'geometryKey' => $revision['geometryKey'],
                'sourceSequence' => is_int(
                    $sourceSegment['sequence'] ?? null
                ) ? $sourceSegment['sequence'] : null,
                'breakReason' => self::boundedText(
                    $sourceSegment['breakReason'] ?? 'unknown',
                    64
                ),
                'taskZoneKey' => $taskZoneKey,
                'startedAt' => $retained[0]['receivedAt'],
                'endedAt' => $retained[array_key_last($retained)]['receivedAt'],
                'points' => $retained,
            ];
        }

        $state = self::fit($state);
        self::encoded($state);

        return $state;
    }

    /** @param array<string, mixed> $state */
    public static function serializeState(array $state): string
    {
        return self::encoded(self::state($state));
    }

    /** @return array<string, mixed> */
    public static function restoreState(string $encoded): array
    {
        if ($encoded === '' || strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException(
                'Track state exceeds the serialized limit.'
            );
        }
        try {
            $state = json_decode(
                $encoded,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Track state is not valid JSON.',
                0,
                $exception
            );
        }
        if (!is_array($state)) {
            throw new InvalidArgumentException('Track state is invalid.');
        }

        return self::state($state);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public static function pruneBefore(array $state, int $cutoff): array
    {
        if ($cutoff <= 0) {
            throw new InvalidArgumentException(
                'Track-retention cutoff is invalid.'
            );
        }
        $state = self::state($state);
        $beforeRevisions = self::revisionOrder($state['segments']);
        $segments = [];
        foreach ($state['segments'] as $segment) {
            $points = array_values(array_filter(
                $segment['points'],
                static fn (array $point): bool =>
                    $point['receivedAt'] >= $cutoff
            ));
            $removed = count($segment['points']) - count($points);
            $state['counters']['evictedPointCount'] += $removed;
            if ($points === []) {
                ++$state['counters']['evictedSegmentCount'];
                continue;
            }
            $segment['points'] = $points;
            $segment['startedAt'] = $points[0]['receivedAt'];
            $segment['endedAt'] = $points[array_key_last($points)]
                ['receivedAt'];
            $segments[] = $segment;
        }
        $state['segments'] = $segments;
        $afterRevisions = self::revisionOrder($segments);
        $state['counters']['evictedRevisionCount'] += count(array_diff(
            $beforeRevisions,
            $afterRevisions
        ));
        self::encoded($state);

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public static function scenePath(
        array $state,
        string $geometryKey
    ): array {
        if (preg_match(self::HASH_PATTERN, $geometryKey) !== 1) {
            throw new InvalidArgumentException(
                'Track projection geometry key is invalid.'
            );
        }
        $state = self::state($state);
        $segments = [];
        $counters = [
            'includedPointCount' => 0,
            'taskAttributedPointCount' => 0,
            'geometryFallbackPointCount' => 0,
            'ambiguousPointCount' => 0,
            'outsidePointCount' => 0,
            'unknownTaskZonePointCount' => 0,
        ];
        foreach ($state['segments'] as $segment) {
            if (!hash_equals($geometryKey, $segment['geometryKey'])) {
                continue;
            }
            $points = [];
            foreach ($segment['points'] as $point) {
                $source = $point['attributionSource'];
                $counter = match ($source) {
                    'task' => 'taskAttributedPointCount',
                    'geometry-fallback' =>
                        'geometryFallbackPointCount',
                    'ambiguous' => 'ambiguousPointCount',
                    'outside' => 'outsidePointCount',
                    'unknown-task-zone' =>
                        'unknownTaskZonePointCount',
                    default => throw new InvalidArgumentException(
                        'Retained track attribution is unsupported.'
                    ),
                };
                ++$counters[$counter];
                ++$counters['includedPointCount'];
                $points[] = [
                    'localX' => $point['localX'],
                    'localY' => $point['localY'],
                    'orientation' => $point['orientation'],
                    'receivedAt' => $point['receivedAt'],
                    'attribution' => [
                        'source' => $source,
                        'zoneKey' => $point['zoneKey'],
                        'geometryCandidateCount' => 0,
                        'geometryPlausible' => $point['zoneKey'] !== null,
                    ],
                ];
            }
            $segments[] = [
                'sequence' => count($segments) + 1,
                'breakReason' => $segment['breakReason'],
                'taskZoneKey' => $segment['taskZoneKey'],
                'points' => $points,
            ];
        }

        return [
            'status' => 'included',
            'segments' => $segments,
            'counters' => $counters,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public static function project(array $state): array
    {
        $state = self::state($state);
        $revisions = [];
        foreach ($state['segments'] as $segment) {
            $key = $segment['geometryKey'];
            $revisions[$key] ??= [
                'geometryKey' => $key,
                'firstReceivedAt' => PHP_INT_MAX,
                'lastReceivedAt' => 0,
                'segmentCount' => 0,
                'pointCount' => 0,
            ];
            $revisions[$key]['firstReceivedAt'] = min(
                $revisions[$key]['firstReceivedAt'],
                $segment['startedAt']
            );
            $revisions[$key]['lastReceivedAt'] = max(
                $revisions[$key]['lastReceivedAt'],
                $segment['endedAt']
            );
            ++$revisions[$key]['segmentCount'];
            $revisions[$key]['pointCount'] += count($segment['points']);
        }

        return [
            'formatVersion' => 1,
            'retentionContract' => [
                'maximumRevisions' => self::MAX_REVISIONS,
                'maximumSegments' => self::MAX_SEGMENTS,
                'maximumPoints' => self::MAX_POINTS,
                'maximumSerializedBytes' => self::MAX_SERIALIZED_BYTES,
            ],
            'revisionCount' => count($revisions),
            'segmentCount' => count($state['segments']),
            'pointCount' => array_sum(array_map(
                static fn (array $segment): int => count($segment['points']),
                $state['segments']
            )),
            'revisions' => array_values($revisions),
            'counters' => $state['counters'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private static function state(array $state): array
    {
        if (
            ($state['formatVersion'] ?? null) !== 1
            || !is_array($state['segments'] ?? null)
            || !array_is_list($state['segments'])
            || count($state['segments']) > self::MAX_SEGMENTS
            || !is_array($state['counters'] ?? null)
        ) {
            throw new InvalidArgumentException('Track state is invalid.');
        }
        $pointCount = 0;
        foreach ($state['segments'] as $segment) {
            if (
                !is_array($segment)
                || !is_string($segment['geometryKey'] ?? null)
                || preg_match(
                    self::HASH_PATTERN,
                    $segment['geometryKey']
                ) !== 1
                || !is_int($segment['startedAt'] ?? null)
                || !is_int($segment['endedAt'] ?? null)
                || $segment['startedAt'] <= 0
                || $segment['endedAt'] < $segment['startedAt']
                || !is_array($segment['points'] ?? null)
                || !array_is_list($segment['points'])
            ) {
                throw new InvalidArgumentException(
                    'Retained track segment is invalid.'
                );
            }
            $pointCount += count($segment['points']);
            foreach ($segment['points'] as $point) {
                self::retainedPoint($point);
            }
        }
        if ($pointCount > self::MAX_POINTS) {
            throw new InvalidArgumentException(
                'Retained track points exceed the limit.'
            );
        }
        foreach (
            [
                'ingestedPointCount',
                'duplicatePointCount',
                'evictedPointCount',
                'evictedSegmentCount',
                'evictedRevisionCount',
            ] as $counter
        ) {
            if (
                !is_int($state['counters'][$counter] ?? null)
                || $state['counters'][$counter] < 0
            ) {
                throw new InvalidArgumentException(
                    'Track-state counter is invalid.'
                );
            }
        }

        return $state;
    }

    /** @return array<string, mixed> */
    private static function sourcePoint(mixed $point): array
    {
        if (
            !is_array($point)
            || !is_int($point['receivedAt'] ?? null)
            || $point['receivedAt'] <= 0
            || !is_array($point['attribution'] ?? null)
        ) {
            throw new InvalidArgumentException('Track point is invalid.');
        }
        $attribution = $point['attribution'];
        $zoneKey = $attribution['zoneKey'] ?? null;
        if (
            $zoneKey !== null
            && (!is_string($zoneKey)
                || preg_match(self::HASH_PATTERN, $zoneKey) !== 1)
        ) {
            throw new InvalidArgumentException(
                'Track point zone key is invalid.'
            );
        }

        return [
            'localX' => self::finite($point['localX'] ?? null),
            'localY' => self::finite($point['localY'] ?? null),
            'orientation' => self::finite($point['orientation'] ?? null),
            'receivedAt' => $point['receivedAt'],
            'zoneKey' => $zoneKey,
            'attributionSource' => self::boundedText(
                $attribution['source'] ?? null,
                32
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function retainedPoint(mixed $point): array
    {
        if (
            !is_array($point)
            || !is_int($point['receivedAt'] ?? null)
            || $point['receivedAt'] <= 0
        ) {
            throw new InvalidArgumentException(
                'Retained track point is invalid.'
            );
        }
        $zoneKey = $point['zoneKey'] ?? null;
        if (
            $zoneKey !== null
            && (!is_string($zoneKey)
                || preg_match(self::HASH_PATTERN, $zoneKey) !== 1)
        ) {
            throw new InvalidArgumentException(
                'Retained track point zone key is invalid.'
            );
        }

        return [
            'localX' => self::finite($point['localX'] ?? null),
            'localY' => self::finite($point['localY'] ?? null),
            'orientation' => self::finite($point['orientation'] ?? null),
            'receivedAt' => $point['receivedAt'],
            'zoneKey' => $zoneKey,
            'attributionSource' => self::boundedText(
                $point['attributionSource'] ?? null,
                32
            ),
        ];
    }

    /** @param array<string, mixed> $point */
    private static function pointKey(string $geometryKey, array $point): string
    {
        return hash(
            'sha256',
            $geometryKey
                . '|' . sprintf('%.6F', $point['localX'])
                . '|' . sprintf('%.6F', $point['localY'])
                . '|' . (string) $point['receivedAt']
        );
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private static function fit(array $state): array
    {
        while (count($state['segments']) > self::MAX_SEGMENTS) {
            $removed = array_shift($state['segments']);
            if (is_array($removed)) {
                $state['counters']['evictedPointCount'] += count(
                    $removed['points']
                );
                ++$state['counters']['evictedSegmentCount'];
            }
        }

        while (self::pointCount($state['segments']) > self::MAX_POINTS) {
            $first = $state['segments'][0] ?? null;
            if (!is_array($first)) {
                break;
            }
            if (count($first['points']) <= 1) {
                array_shift($state['segments']);
                ++$state['counters']['evictedSegmentCount'];
            } else {
                array_shift($state['segments'][0]['points']);
                $state['segments'][0]['startedAt'] =
                    $state['segments'][0]['points'][0]['receivedAt'];
            }
            ++$state['counters']['evictedPointCount'];
        }

        while (count(self::revisionOrder($state['segments'])) > self::MAX_REVISIONS) {
            $oldest = self::revisionOrder($state['segments'])[0];
            $retained = [];
            foreach ($state['segments'] as $segment) {
                if ($segment['geometryKey'] === $oldest) {
                    $state['counters']['evictedPointCount'] += count(
                        $segment['points']
                    );
                    ++$state['counters']['evictedSegmentCount'];
                    continue;
                }
                $retained[] = $segment;
            }
            $state['segments'] = $retained;
            ++$state['counters']['evictedRevisionCount'];
        }

        return $state;
    }

    /** @param list<array<string, mixed>> $segments */
    private static function pointCount(array $segments): int
    {
        return array_sum(array_map(
            static fn (array $segment): int => count($segment['points']),
            $segments
        ));
    }

    /**
     * @param list<array<string, mixed>> $segments
     *
     * @return list<string>
     */
    private static function revisionOrder(array $segments): array
    {
        $keys = [];
        foreach ($segments as $segment) {
            $keys[$segment['geometryKey']] ??= $segment['startedAt'];
            $keys[$segment['geometryKey']] = min(
                $keys[$segment['geometryKey']],
                $segment['startedAt']
            );
        }
        asort($keys, SORT_NUMERIC);

        return array_keys($keys);
    }

    /** @param array<string, mixed> $state */
    private static function encoded(array $state): string
    {
        try {
            $encoded = json_encode(
                $state,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Track state cannot be encoded.',
                0,
                $exception
            );
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException(
                'Track state exceeds the serialized limit.'
            );
        }

        return $encoded;
    }

    private static function finite(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Track coordinate is invalid.');
        }
        $number = (float) $value;
        if (!is_finite($number) || abs($number) > 1000 * 1000) {
            throw new InvalidArgumentException(
                'Track coordinate exceeds the limit.'
            );
        }

        return $number;
    }

    private static function boundedText(mixed $value, int $maximum): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Track text is invalid.');
        }

        return $value;
    }
}
