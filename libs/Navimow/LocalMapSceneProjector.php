<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;
use JsonException;
use LogicException;

final class LocalMapSceneProjector
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';
    private const MAX_ZONES = 32;
    private const MAX_AREAS = 256;
    private const MAX_POINTS_PER_RING = 1025;
    private const MAX_PATH_SEGMENTS = 64;
    private const MAX_PATH_POINTS = 512;
    private const MAX_LABEL_BYTES = 128;
    private const MAX_SERIALIZED_BYTES = 1024 * 1024;
    private const EPSILON = 0.000000001;

    /**
     * @param array<string, mixed> $geometry
     * @param array<string, mixed> $path
     * @param array<string, mixed> $statistics
     * @param array<array-key, mixed> $bindings
     * @param array<string, mixed> $revision
     *
     * @return array<string, mixed>
     */
    public static function build(
        array $geometry,
        array $path,
        array $statistics,
        array $bindings,
        array $revision
    ): array {
        $normalizedGeometry = self::geometry($geometry);
        $normalizedBindings = self::bindings(
            $bindings,
            $normalizedGeometry['zones']
        );
        $revisionState = self::revision($geometry, $revision);
        $normalizedPath = self::path($path);
        $normalizedStatistics = self::statistics($statistics);

        $zonesById = [];
        $zonesByKey = [];
        foreach ($normalizedGeometry['zones'] as $index => $zone) {
            $binding = $normalizedBindings[$zone['id']];
            $zone['sequence'] = $index + 1;
            $zone['zoneKey'] = $binding['zoneKey'];
            $zone['label'] = $binding['label'];
            $zonesById[$zone['id']] = $zone;
            if ($zone['zoneKey'] !== null) {
                $zonesByKey[$zone['zoneKey']] = $zone;
            }
        }

        $obstacles = self::obstacleLayer(
            $normalizedGeometry['obstacles'],
            array_values($zonesById)
        );
        $overlaps = self::overlaps(array_values($zonesById));
        $pathLayer = self::pathLayer(
            $normalizedPath,
            $zonesByKey,
            $revisionState
        );
        $statisticsByKey = self::statisticsByKey($normalizedStatistics);

        $zoneLayer = [];
        foreach ($zonesById as $zone) {
            $zoneStatistics = null;
            if (
                $revisionState['statisticsCompatible']
                && $zone['zoneKey'] !== null
            ) {
                $zoneStatistics = $statisticsByKey[$zone['zoneKey']] ?? null;
            }
            $configuredArea = is_array($zoneStatistics)
                ? $zoneStatistics['configuredZoneArea']
                : null;
            $reportedArea = $zone['reportedArea'];
            $denominatorMatches = is_float($configuredArea)
                && is_float($reportedArea)
                && self::relativeDifference(
                    $configuredArea,
                    $reportedArea
                ) <= 0.000001;

            $zoneLayer[] = [
                'sequence' => $zone['sequence'],
                'zoneKey' => $zone['zoneKey'],
                'label' => $zone['label'],
                'ring' => $zone['ring'],
                'reportedNetArea' => $reportedArea,
                'calculatedBoundaryArea' => $zone['calculatedArea'],
                'obstacleIndexes' => array_values(array_map(
                    static fn (array $obstacle): int => $obstacle['sequence'],
                    array_filter(
                        $obstacles,
                        static fn (array $obstacle): bool =>
                            $obstacle['ownership']['zoneSequence']
                                === $zone['sequence']
                    )
                )),
                'denominatorContract' =>
                    'manufacturer-reported-net-zone-area',
                'denominatorMatchesStatistics' => $denominatorMatches,
                'statistics' => $zoneStatistics,
            ];
        }

        $result = [
            'formatVersion' => 1,
            'authority' => [
                'state' => 'rest-authoritative',
                'path' => 'mqtt-inference',
                'geometry' => 'private-map-candidate',
            ],
            'coordinateFrame' => 'navimow-local-map-candidate',
            'revision' => $revisionState,
            'viewport' => self::viewport(
                $zoneLayer,
                $obstacles,
                $normalizedGeometry['station'],
                $pathLayer['segments']
            ),
            'station' => $normalizedGeometry['station'],
            'zones' => $zoneLayer,
            'obstacles' => $obstacles,
            'overlapDiagnostics' => [
                'pairCount' => count($overlaps),
                'geometryOnlyAttributionUnambiguous' => $overlaps === [],
                'pairs' => $overlaps,
            ],
            'path' => $pathLayer,
            'contracts' => [
                'taskZoneAttributionPrecedesGeometry' => true,
                'ambiguousGeometryNeverDoubleCounts' => true,
                'geometricCoveragePercent' => 'not-implemented',
                'revisionMismatchDropsPathAndStatistics' => true,
            ],
        ];

        try {
            $encoded = json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Local map scene cannot be encoded.',
                0,
                $exception
            );
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException(
                'Local map scene exceeds the byte limit.'
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $geometry
     *
     * @return array{
     *   zones: list<array<string, mixed>>,
     *   obstacles: list<array<string, mixed>>,
     *   station: array{x: float, y: float, direction: float|null}|null
     * }
     */
    private static function geometry(array $geometry): array
    {
        if (
            ($geometry['formatVersion'] ?? null) !== 1
            || ($geometry['coordinateFrame'] ?? null)
                !== 'navimow-local-map'
        ) {
            throw new InvalidArgumentException(
                'Map geometry contract is unsupported.'
            );
        }
        $zones = $geometry['zones'] ?? null;
        if (
            !is_array($zones)
            || !array_is_list($zones)
            || $zones === []
            || count($zones) > self::MAX_ZONES
        ) {
            throw new InvalidArgumentException('Map zones are invalid.');
        }

        $normalizedZones = [];
        $zoneIds = [];
        foreach ($zones as $zone) {
            if (
                !is_array($zone)
                || !is_int($zone['id'] ?? null)
                || isset($zoneIds[$zone['id']])
            ) {
                throw new InvalidArgumentException(
                    'Map zone identity is invalid.'
                );
            }
            $zoneIds[$zone['id']] = true;
            $normalizedZones[] = [
                'id' => $zone['id'],
                'reportedArea' => self::positiveNumberOrNull(
                    $zone['reportedArea'] ?? null
                ),
                'calculatedArea' => self::positiveNumber(
                    $zone['calculatedArea'] ?? null
                ),
                'ring' => self::ring($zone['ring'] ?? null),
            ];
        }

        $obstacles = $geometry['obstacles'] ?? null;
        if (
            !is_array($obstacles)
            || !array_is_list($obstacles)
            || count($obstacles) > self::MAX_AREAS
        ) {
            throw new InvalidArgumentException('Map obstacles are invalid.');
        }
        $normalizedObstacles = [];
        foreach ($obstacles as $obstacle) {
            if (!is_array($obstacle)) {
                throw new InvalidArgumentException(
                    'Map obstacle is invalid.'
                );
            }
            $normalizedObstacles[] = [
                'calculatedArea' => self::positiveNumber(
                    $obstacle['calculatedArea'] ?? null
                ),
                'ring' => self::ring($obstacle['ring'] ?? null),
            ];
        }

        $station = null;
        if (($geometry['station'] ?? null) !== null) {
            $value = $geometry['station'];
            if (!is_array($value)) {
                throw new InvalidArgumentException('Map station is invalid.');
            }
            $station = [
                'x' => self::finiteNumber($value['x'] ?? null),
                'y' => self::finiteNumber($value['y'] ?? null),
                'direction' => ($value['direction'] ?? null) === null
                    ? null
                    : self::finiteNumber($value['direction']),
            ];
        }

        return [
            'zones' => $normalizedZones,
            'obstacles' => $normalizedObstacles,
            'station' => $station,
        ];
    }

    /**
     * @param array<array-key, mixed> $bindings
     * @param list<array<string, mixed>> $zones
     *
     * @return array<int, array{zoneKey: string|null, label: string}>
     */
    private static function bindings(array $bindings, array $zones): array
    {
        if (!array_is_list($bindings) || count($bindings) !== count($zones)) {
            throw new InvalidArgumentException('Zone bindings are incomplete.');
        }
        $zoneIds = array_fill_keys(array_column($zones, 'id'), true);
        $result = [];
        $keys = [];
        foreach ($bindings as $binding) {
            if (
                !is_array($binding)
                || !is_int($binding['zoneId'] ?? null)
                || !isset($zoneIds[$binding['zoneId']])
                || isset($result[$binding['zoneId']])
            ) {
                throw new InvalidArgumentException('Zone binding is invalid.');
            }
            $zoneKey = $binding['zoneKey'] ?? null;
            if (
                $zoneKey !== null
                && (!is_string($zoneKey)
                    || preg_match(self::HASH_PATTERN, $zoneKey) !== 1
                    || isset($keys[$zoneKey]))
            ) {
                throw new InvalidArgumentException(
                    'Zone binding key is invalid.'
                );
            }
            if ($zoneKey !== null) {
                $keys[$zoneKey] = true;
            }
            $label = $binding['label'] ?? null;
            if (
                !is_string($label)
                || $label === ''
                || strlen($label) > self::MAX_LABEL_BYTES
                || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
            ) {
                throw new InvalidArgumentException(
                    'Zone binding label is invalid.'
                );
            }
            $result[$binding['zoneId']] = [
                'zoneKey' => $zoneKey,
                'label' => $label,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $geometry
     * @param array<string, mixed> $revision
     *
     * @return array<string, mixed>
     */
    private static function revision(array $geometry, array $revision): array
    {
        if (!function_exists('SAEF_CreateConfigurationHash')) {
            throw new LogicException(
                'SAEF ConfigurationHash helper is required.'
            );
        }
        $keys = [];
        foreach (
            [
                'currentGeometryKey',
                'acceptedGeometryKey',
                'pathGeometryKey',
                'statisticsGeometryKey',
            ] as $field
        ) {
            $value = $revision[$field] ?? null;
            if (
                !is_string($value)
                || preg_match(self::HASH_PATTERN, $value) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Geometry revision key is invalid.'
                );
            }
            $keys[$field] = $value;
        }
        if (!is_bool($revision['frameCorrelationApproved'] ?? null)) {
            throw new InvalidArgumentException(
                'Frame-correlation gate is invalid.'
            );
        }
        $computed = \SAEF_CreateConfigurationHash($geometry);
        if (!hash_equals($computed, $keys['currentGeometryKey'])) {
            throw new InvalidArgumentException(
                'Current geometry revision does not match the projection.'
            );
        }
        $accepted = hash_equals(
            $keys['currentGeometryKey'],
            $keys['acceptedGeometryKey']
        );
        $pathCompatible = $accepted
            && $revision['frameCorrelationApproved']
            && hash_equals(
                $keys['currentGeometryKey'],
                $keys['pathGeometryKey']
            );
        $statisticsCompatible = $accepted
            && hash_equals(
                $keys['currentGeometryKey'],
                $keys['statisticsGeometryKey']
            );

        return [
            'geometryKey' => $keys['currentGeometryKey'],
            'state' => $accepted ? 'accepted' : 'candidate',
            'frameCorrelationApproved' =>
                $revision['frameCorrelationApproved'],
            'pathCompatible' => $pathCompatible,
            'statisticsCompatible' => $statisticsCompatible,
            'requiresReconciliation' => !$accepted,
        ];
    }

    /** @return array<string, mixed> */
    private static function path(array $path): array
    {
        if (
            ($path['formatVersion'] ?? null) !== 1
            || ($path['coordinateFrame'] ?? null) !== 'uncalibrated-local'
        ) {
            throw new InvalidArgumentException('Path contract is unsupported.');
        }
        $segments = $path['segments'] ?? null;
        if (
            !is_array($segments)
            || !array_is_list($segments)
            || count($segments) > self::MAX_PATH_SEGMENTS
        ) {
            throw new InvalidArgumentException('Path segments are invalid.');
        }
        $pointCount = 0;
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                throw new InvalidArgumentException('Path segment is invalid.');
            }
            $points = $segment['points'] ?? null;
            if (!is_array($points) || !array_is_list($points)) {
                throw new InvalidArgumentException(
                    'Path segment points are invalid.'
                );
            }
            $pointCount += count($points);
            if ($pointCount > self::MAX_PATH_POINTS) {
                throw new InvalidArgumentException(
                    'Path points exceed the retention limit.'
                );
            }
            foreach ($points as $point) {
                if (
                    !is_array($point)
                    || !is_int($point['receivedAt'] ?? null)
                    || $point['receivedAt'] <= 0
                ) {
                    throw new InvalidArgumentException(
                        'Path point is invalid.'
                    );
                }
                self::finiteNumber($point['localX'] ?? null);
                self::finiteNumber($point['localY'] ?? null);
                self::finiteNumber($point['orientation'] ?? null);
            }
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private static function statistics(array $statistics): array
    {
        if (($statistics['formatVersion'] ?? null) !== 1) {
            throw new InvalidArgumentException(
                'Zone statistics contract is unsupported.'
            );
        }
        $zones = $statistics['zones'] ?? null;
        if (
            !is_array($zones)
            || !array_is_list($zones)
            || count($zones) > self::MAX_ZONES
        ) {
            throw new InvalidArgumentException(
                'Zone statistics are invalid.'
            );
        }
        foreach ($zones as $zone) {
            if (
                !is_array($zone)
                || !is_string($zone['areaKey'] ?? null)
                || preg_match(self::HASH_PATTERN, $zone['areaKey']) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Zone statistic key is invalid.'
                );
            }
        }

        return $statistics;
    }

    /**
     * @param list<array<string, mixed>> $obstacles
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private static function obstacleLayer(array $obstacles, array $zones): array
    {
        $result = [];
        foreach ($obstacles as $index => $obstacle) {
            $point = self::representative($obstacle['ring']);
            $matches = array_values(array_filter(
                $zones,
                static fn (array $zone): bool => self::pointInRing(
                    $point,
                    $zone['ring']
                )
            ));
            $ownership = [
                'status' => count($matches) === 1
                    ? 'single-zone'
                    : (count($matches) > 1 ? 'ambiguous' : 'outside'),
                'zoneSequence' => count($matches) === 1
                    ? $matches[0]['sequence']
                    : null,
                'zoneKey' => count($matches) === 1
                    ? $matches[0]['zoneKey']
                    : null,
                'candidateCount' => count($matches),
            ];
            $result[] = [
                'sequence' => $index + 1,
                'ring' => $obstacle['ring'],
                'calculatedArea' => $obstacle['calculatedArea'],
                'ownership' => $ownership,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private static function overlaps(array $zones): array
    {
        $result = [];
        for ($left = 0; $left < count($zones); ++$left) {
            for ($right = $left + 1; $right < count($zones); ++$right) {
                $crossings = self::crossingCount(
                    $zones[$left]['ring'],
                    $zones[$right]['ring']
                );
                if ($crossings === 0) {
                    continue;
                }
                $result[] = [
                    'firstZoneSequence' => $zones[$left]['sequence'],
                    'secondZoneSequence' => $zones[$right]['sequence'],
                    'strictBoundaryCrossings' => $crossings,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, array<string, mixed>> $zonesByKey
     * @param array<string, mixed> $revision
     *
     * @return array<string, mixed>
     */
    private static function pathLayer(
        array $path,
        array $zonesByKey,
        array $revision
    ): array {
        if (!$revision['pathCompatible']) {
            return [
                'status' => $revision['frameCorrelationApproved']
                    ? 'revision-mismatch'
                    : 'frame-not-approved',
                'segments' => [],
                'counters' => [
                    'includedPointCount' => 0,
                    'taskAttributedPointCount' => 0,
                    'geometryFallbackPointCount' => 0,
                    'ambiguousPointCount' => 0,
                    'outsidePointCount' => 0,
                    'unknownTaskZonePointCount' => 0,
                ],
            ];
        }

        $segments = [];
        $counters = [
            'includedPointCount' => 0,
            'taskAttributedPointCount' => 0,
            'geometryFallbackPointCount' => 0,
            'ambiguousPointCount' => 0,
            'outsidePointCount' => 0,
            'unknownTaskZonePointCount' => 0,
        ];
        foreach ($path['segments'] as $segment) {
            $areaKey = $segment['areaKey'] ?? null;
            if (
                $areaKey !== null
                && (!is_string($areaKey)
                    || preg_match(self::HASH_PATTERN, $areaKey) !== 1)
            ) {
                throw new InvalidArgumentException(
                    'Path segment area key is invalid.'
                );
            }
            $points = [];
            foreach ($segment['points'] as $point) {
                $local = [
                    (float) $point['localX'],
                    (float) $point['localY'],
                ];
                $matches = array_values(array_filter(
                    $zonesByKey,
                    static fn (array $zone): bool => self::pointInRing(
                        $local,
                        $zone['ring']
                    )
                ));
                $attribution = self::attribution(
                    $areaKey,
                    $matches,
                    $zonesByKey
                );
                $counter = $attribution['counter'];
                $counters[$counter]++;
                $counters['includedPointCount']++;
                unset($attribution['counter']);
                $points[] = [
                    'localX' => $local[0],
                    'localY' => $local[1],
                    'orientation' => (float) $point['orientation'],
                    'receivedAt' => $point['receivedAt'],
                    'attribution' => $attribution,
                ];
            }
            $segments[] = [
                'sequence' => $segment['sequence'] ?? count($segments) + 1,
                'breakReason' => $segment['breakReason'] ?? 'unknown',
                'taskZoneKey' => $areaKey,
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
     * @param list<array<string, mixed>> $matches
     * @param array<string, array<string, mixed>> $zonesByKey
     *
     * @return array<string, mixed>
     */
    private static function attribution(
        ?string $taskZoneKey,
        array $matches,
        array $zonesByKey
    ): array {
        if ($taskZoneKey !== null) {
            if (!isset($zonesByKey[$taskZoneKey])) {
                return [
                    'source' => 'unknown-task-zone',
                    'zoneKey' => null,
                    'geometryCandidateCount' => count($matches),
                    'geometryPlausible' => false,
                    'counter' => 'unknownTaskZonePointCount',
                ];
            }
            $plausible = in_array(
                $zonesByKey[$taskZoneKey],
                $matches,
                true
            );

            return [
                'source' => 'task',
                'zoneKey' => $taskZoneKey,
                'geometryCandidateCount' => count($matches),
                'geometryPlausible' => $plausible,
                'counter' => 'taskAttributedPointCount',
            ];
        }
        if (count($matches) === 1) {
            return [
                'source' => 'geometry-fallback',
                'zoneKey' => $matches[0]['zoneKey'],
                'geometryCandidateCount' => 1,
                'geometryPlausible' => true,
                'counter' => 'geometryFallbackPointCount',
            ];
        }
        if (count($matches) > 1) {
            return [
                'source' => 'ambiguous',
                'zoneKey' => null,
                'geometryCandidateCount' => count($matches),
                'geometryPlausible' => false,
                'counter' => 'ambiguousPointCount',
            ];
        }

        return [
            'source' => 'outside',
            'zoneKey' => null,
            'geometryCandidateCount' => 0,
            'geometryPlausible' => false,
            'counter' => 'outsidePointCount',
        ];
    }

    /**
     * @param array<string, mixed> $statistics
     *
     * @return array<string, array<string, mixed>>
     */
    private static function statisticsByKey(array $statistics): array
    {
        $result = [];
        foreach ($statistics['zones'] as $zone) {
            if (isset($result[$zone['areaKey']])) {
                throw new InvalidArgumentException(
                    'Duplicate zone statistic key.'
                );
            }
            $configuredArea = $zone['configuredZoneArea'] ?? null;
            if ($configuredArea !== null) {
                $configuredArea = self::positiveNumber($configuredArea);
            }
            $zone['configuredZoneArea'] = $configuredArea;
            $result[$zone['areaKey']] = $zone;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $zones
     * @param list<array<string, mixed>> $obstacles
     * @param array{x: float, y: float, direction: float|null}|null $station
     * @param list<array<string, mixed>> $segments
     *
     * @return array<string, float>
     */
    private static function viewport(
        array $zones,
        array $obstacles,
        ?array $station,
        array $segments
    ): array {
        $points = [];
        foreach ($zones as $zone) {
            array_push($points, ...$zone['ring']);
        }
        foreach ($obstacles as $obstacle) {
            array_push($points, ...$obstacle['ring']);
        }
        if ($station !== null) {
            $points[] = [$station['x'], $station['y']];
        }
        foreach ($segments as $segment) {
            foreach ($segment['points'] as $point) {
                $points[] = [$point['localX'], $point['localY']];
            }
        }
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $minimumX = min($xs);
        $maximumX = max($xs);
        $minimumY = min($ys);
        $maximumY = max($ys);
        $span = max($maximumX - $minimumX, $maximumY - $minimumY);
        $padding = max(1.0, $span * 0.05);

        return [
            'minimumX' => $minimumX - $padding,
            'minimumY' => $minimumY - $padding,
            'maximumX' => $maximumX + $padding,
            'maximumY' => $maximumY + $padding,
            'width' => ($maximumX - $minimumX) + 2.0 * $padding,
            'height' => ($maximumY - $minimumY) + 2.0 * $padding,
            'paddingLocal' => $padding,
        ];
    }

    /**
     * @param mixed $value
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function ring(mixed $value): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || count($value) < 4
            || count($value) > self::MAX_POINTS_PER_RING
        ) {
            throw new InvalidArgumentException('Map ring is invalid.');
        }
        $ring = [];
        foreach ($value as $point) {
            if (!is_array($point) || count($point) !== 2) {
                throw new InvalidArgumentException('Map point is invalid.');
            }
            $ring[] = [
                self::finiteNumber($point[0] ?? null),
                self::finiteNumber($point[1] ?? null),
            ];
        }
        if ($ring[0] !== $ring[array_key_last($ring)]) {
            throw new InvalidArgumentException('Map ring is not closed.');
        }

        return $ring;
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param list<array{0: float, 1: float}> $ring
     */
    private static function pointInRing(array $point, array $ring): bool
    {
        $inside = false;
        for ($index = 0, $last = count($ring) - 1; $index < $last; ++$index) {
            $first = $ring[$index];
            $second = $ring[$index + 1];
            if (self::pointOnSegment($point, $first, $second)) {
                return true;
            }
            if (($first[1] > $point[1]) !== ($second[1] > $point[1])) {
                $crossingX = ($second[0] - $first[0])
                    * ($point[1] - $first[1])
                    / ($second[1] - $first[1])
                    + $first[0];
                if ($point[0] < $crossingX) {
                    $inside = !$inside;
                }
            }
        }

        return $inside;
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param array{0: float, 1: float} $first
     * @param array{0: float, 1: float} $second
     */
    private static function pointOnSegment(
        array $point,
        array $first,
        array $second
    ): bool {
        if (abs(self::orientation($first, $second, $point)) > self::EPSILON) {
            return false;
        }

        return $point[0] >= min($first[0], $second[0]) - self::EPSILON
            && $point[0] <= max($first[0], $second[0]) + self::EPSILON
            && $point[1] >= min($first[1], $second[1]) - self::EPSILON
            && $point[1] <= max($first[1], $second[1]) + self::EPSILON;
    }

    /**
     * @param list<array{0: float, 1: float}> $ring
     *
     * @return array{0: float, 1: float}
     */
    private static function representative(array $ring): array
    {
        $points = array_slice($ring, 0, -1);

        return [
            array_sum(array_column($points, 0)) / count($points),
            array_sum(array_column($points, 1)) / count($points),
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $first
     * @param list<array{0: float, 1: float}> $second
     */
    private static function crossingCount(array $first, array $second): int
    {
        $count = 0;
        for ($left = 0; $left < count($first) - 1; ++$left) {
            for ($right = 0; $right < count($second) - 1; ++$right) {
                if (
                    self::strictSegmentsCross(
                        $first[$left],
                        $first[$left + 1],
                        $second[$right],
                        $second[$right + 1]
                    )
                ) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     * @param array{0: float, 1: float} $d
     */
    private static function strictSegmentsCross(
        array $a,
        array $b,
        array $c,
        array $d
    ): bool {
        $first = self::orientation($a, $b, $c);
        $second = self::orientation($a, $b, $d);
        $third = self::orientation($c, $d, $a);
        $fourth = self::orientation($c, $d, $b);

        return (($first > self::EPSILON && $second < -self::EPSILON)
                || ($first < -self::EPSILON && $second > self::EPSILON))
            && (($third > self::EPSILON && $fourth < -self::EPSILON)
                || ($third < -self::EPSILON && $fourth > self::EPSILON));
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     */
    private static function orientation(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1])
            - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    private static function positiveNumber(mixed $value): float
    {
        $number = self::finiteNumber($value);
        if ($number <= 0.0) {
            throw new InvalidArgumentException('Area must be positive.');
        }

        return $number;
    }

    private static function positiveNumberOrNull(mixed $value): ?float
    {
        return $value === null ? null : self::positiveNumber($value);
    }

    private static function finiteNumber(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Number is invalid.');
        }
        $number = (float) $value;
        if (!is_finite($number) || abs($number) > 1000 * 1000) {
            throw new InvalidArgumentException('Number is outside bounds.');
        }

        return $number;
    }

    private static function relativeDifference(float $first, float $second): float
    {
        return abs($first - $second)
            / max(abs($first), abs($second), self::EPSILON);
    }
}
