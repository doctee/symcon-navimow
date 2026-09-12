<?php

declare(strict_types=1);

namespace Navimow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class MowingAnalyticsReducer
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';
    private const MAX_REVISIONS = 4;
    private const MAX_ZONES = 32;
    private const MAX_SUBAREAS = 32;
    private const MAX_DAYS = 400;
    private const MAX_RUNS = 256;
    private const MAX_SEGMENTS = 64;
    private const MAX_POINTS = 2048;
    private const MAX_COVERAGE_SAMPLES = 250000;
    private const MAX_SERIALIZED_BYTES = 524288;
    private const MAX_PAIR_GAP_SECONDS = 300;
    private const RUNNING_STATE = 1;

    /** @return array<string, mixed> */
    public static function initialState(): array
    {
        return [
            'formatVersion' => 1,
            'revisions' => [],
            'counters' => [
                'updateCount' => 0,
                'coverageSampleCount' => 0,
                'coverageSampleLimitCount' => 0,
                'evictedRevisionCount' => 0,
                'evictedDayCount' => 0,
                'evictedRunCount' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $scene
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function update(
        array $state,
        array $scene,
        int $observedAt,
        array $options = []
    ): array {
        if ($observedAt <= 0) {
            throw new InvalidArgumentException(
                'Mowing analytics observation time is invalid.'
            );
        }
        $state = self::state($state);
        $policy = self::policy($options);
        $input = self::scene($scene);
        self::validateZoneBindings($input['zones'], $policy['zoneBindings']);
        $contractKey = self::contractKey($input['geometryKey'], $policy);
        $snapshot = self::snapshot($input, $policy);
        $zoneDefinitions = array_map(
            static fn (array $zone): array => [
                'zoneId' => $zone['zoneId'],
                'zoneKey' => $zone['zoneKey'],
                'label' => $zone['label'],
                'reportedNetArea' => $zone['reportedNetArea'],
            ],
            $input['zones']
        );
        $subareaDefinitions = array_map(
            static fn (array $subarea): array => [
                'key' => $subarea['key'],
                'zoneKey' => $subarea['zoneKey'],
                'label' => $subarea['label'],
            ],
            $policy['subareas']
        );

        $revisionIndex = null;
        foreach ($state['revisions'] as $index => $revision) {
            if (
                hash_equals($input['geometryKey'], $revision['geometryKey'])
                && hash_equals($contractKey, $revision['contractKey'])
            ) {
                $revisionIndex = $index;
                break;
            }
        }
        if ($revisionIndex === null) {
            $state['revisions'][] = [
                'geometryKey' => $input['geometryKey'],
                'contractKey' => $contractKey,
                'firstObservedAt' => $observedAt,
                'lastObservedAt' => $observedAt,
                'zones' => $zoneDefinitions,
                'subareas' => $subareaDefinitions,
                'days' => [],
                'runs' => [],
                'zoneState' => [],
                'coverageContract' => self::coverageContract($policy),
            ];
            $revisionIndex = array_key_last($state['revisions']);
        }

        $revision = $state['revisions'][$revisionIndex];
        $revision['lastObservedAt'] = max(
            $revision['lastObservedAt'],
            $observedAt
        );
        $revision['zones'] = $zoneDefinitions;
        $revision['subareas'] = $subareaDefinitions;
        $revision['coverageContract'] = self::coverageContract($policy);
        $revision['days'] = self::mergeRows(
            $revision['days'],
            $snapshot['days'],
            'date'
        );
        $revision['runs'] = self::mergeRows(
            $revision['runs'],
            $snapshot['runs'],
            'runKey'
        );
        $revision['zoneState'] = self::mergeRows(
            $revision['zoneState'],
            $snapshot['zoneState'],
            'zoneKey'
        );
        [$revision['days'], $evictedDays] = self::retainLatest(
            $revision['days'],
            self::MAX_DAYS,
            'date'
        );
        [$revision['runs'], $evictedRuns] = self::retainLatest(
            $revision['runs'],
            self::MAX_RUNS,
            'endedAt'
        );
        $state['revisions'][$revisionIndex] = $revision;

        usort(
            $state['revisions'],
            static fn (array $left, array $right): int =>
                $left['lastObservedAt'] <=> $right['lastObservedAt']
        );
        while (count($state['revisions']) > self::MAX_REVISIONS) {
            array_shift($state['revisions']);
            ++$state['counters']['evictedRevisionCount'];
        }
        ++$state['counters']['updateCount'];
        $state['counters']['coverageSampleCount'] +=
            $snapshot['coverageSampleCount'];
        if ($snapshot['coverageTruncated']) {
            ++$state['counters']['coverageSampleLimitCount'];
        }
        $state['counters']['evictedDayCount'] += $evictedDays;
        $state['counters']['evictedRunCount'] += $evictedRuns;

        self::encoded($state);
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function project(
        array $state,
        string $geometryKey,
        int $now,
        array $options = []
    ): array {
        if (
            preg_match(self::HASH_PATTERN, $geometryKey) !== 1
            || $now <= 0
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics projection identity is invalid.'
            );
        }
        $state = self::state($state);
        $policy = self::policy($options);
        $contractKey = self::contractKey($geometryKey, $policy);
        $revision = null;
        foreach ($state['revisions'] as $candidate) {
            if (
                hash_equals($geometryKey, $candidate['geometryKey'])
                && hash_equals($contractKey, $candidate['contractKey'])
            ) {
                $revision = $candidate;
                break;
            }
        }
        if ($revision === null) {
            return self::emptyProjection(
                $geometryKey,
                $now,
                $policy,
                $state['counters']
            );
        }

        $clock = new DateTimeImmutable('@' . $now);
        $clock = $clock->setTimezone(new DateTimeZone($policy['timeZone']));
        $today = $clock->format('Y-m-d');
        $weekStart = $clock->modify('monday this week')->format('Y-m-d');
        $monthPrefix = $clock->format('Y-m-');
        $latestRun = null;
        foreach ($revision['runs'] as $run) {
            if ($latestRun === null || $run['endedAt'] > $latestRun['endedAt']) {
                $latestRun = $run;
            }
        }

        $zones = [];
        foreach ($revision['zones'] as $definition) {
            $zoneKey = $definition['zoneKey'];
            $zoneState = self::rowByKey(
                $revision['zoneState'],
                'zoneKey',
                $zoneKey
            );
            $lastMowedAt = $zoneState['lastMowedAt'] ?? null;
            if (!is_int($lastMowedAt) || $lastMowedAt <= 0) {
                $lastMowedAt = null;
            }
            $recencyDays = is_int($lastMowedAt)
                ? max(0, intdiv(max(0, $now - $lastMowedAt), 86400))
                : null;
            $zoneRuns = array_values(array_filter(
                $revision['runs'],
                static fn (array $run): bool =>
                    $run['zoneKey'] === $zoneKey
            ));
            usort(
                $zoneRuns,
                static fn (array $left, array $right): int =>
                    $right['endedAt'] <=> $left['endedAt']
            );
            $zoneLatestRun = $zoneRuns[0] ?? null;
            $periods = self::periodTotals(
                $revision['days'],
                $zoneKey,
                $today,
                $weekStart,
                $monthPrefix
            );
            $coverage = null;
            if (
                is_array($zoneLatestRun)
                && is_float($zoneLatestRun['estimatedArea'])
                && is_float($definition['reportedNetArea'])
                && $definition['reportedNetArea'] > 0.0
            ) {
                $coverage = min(
                    100.0,
                    100.0 * $zoneLatestRun['estimatedArea']
                        / $definition['reportedNetArea']
                );
            }
            $zones[] = [
                'zoneId' => $definition['zoneId'],
                'zoneKey' => $zoneKey,
                'label' => $definition['label'],
                'reportedNetArea' => $definition['reportedNetArea'],
                'lastMowedAt' => $lastMowedAt,
                'recencyDays' => $recencyDays,
                'recencyState' => self::recencyState($recencyDays, $policy),
                'todayEstimatedArea' => $periods['todayEstimatedArea'],
                'weekEstimatedArea' => $periods['weekEstimatedArea'],
                'monthEstimatedArea' => $periods['monthEstimatedArea'],
                'latestRun' => $zoneLatestRun,
                'latestRunCoveragePercent' => $coverage,
                'interruptionCount' => $zoneState['interruptionCount'] ?? 0,
                'resumeCount' => $zoneState['resumeCount'] ?? 0,
                'rainInterruptionCount' => null,
                'quality' => self::quality($zoneLatestRun, $policy),
            ];
        }

        return [
            'formatVersion' => 1,
            'authority' => 'mqtt-derived-diagnostic',
            'geometryKey' => $geometryKey,
            'observedAt' => $revision['lastObservedAt'],
            'projectedAt' => $now,
            'state' => $zones === [] ? 'no-data' : 'available',
            'contracts' => [
                'restRemainsStateAuthority' => true,
                'distanceUnit' => 'metre-candidate',
                'geometricCoverage' => self::coverageContract($policy),
                'periodAreaSemantics' =>
                    'sum-of-daily-unique-cell-estimates',
                'rainReasonEvidence' => 'not-observed',
                'subareaStatistics' => $policy['subareas'] === []
                    ? 'prepared-no-subareas-configured'
                    : 'diagnostic',
            ],
            'latestRun' => $latestRun,
            'zones' => $zones,
            'subareas' => self::subareaProjection(
                $revision,
                $today,
                $weekStart,
                $monthPrefix,
                $now,
                $policy
            ),
            'counters' => $state['counters'],
        ];
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
                'Mowing analytics state exceeds the serialized limit.'
            );
        }
        try {
            $decoded = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Mowing analytics state is not valid JSON.',
                0,
                $exception
            );
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                'Mowing analytics state is invalid.'
            );
        }
        return self::state($decoded);
    }

    /** @param array<string, mixed> $policy */
    private static function coverageContract(array $policy): array
    {
        return [
            'state' => $policy['cuttingWidthMeters'] > 0.0
                ? 'diagnostic-enabled'
                : 'disabled-missing-cutting-width',
            'coordinateScale' => 'navimow-local-metre-candidate',
            'cuttingWidthMeters' => $policy['cuttingWidthMeters'] > 0.0
                ? $policy['cuttingWidthMeters']
                : null,
            'cellSizeMeters' => $policy['cuttingWidthMeters'] > 0.0
                ? $policy['coverageCellSizeMeters']
                : null,
            'denominator' => 'manufacturer-reported-net-zone-area',
            'cuttingStateEvidence' => 'vehicle-state-running-candidate',
            'accuracyClaim' => 'estimate-not-measurement',
        ];
    }

    /** @param array<string, mixed> $policy */
    private static function contractKey(
        string $geometryKey,
        array $policy
    ): string {
        $subareas = array_map(
            static fn (array $subarea): array => [
                'key' => $subarea['key'],
                'zoneKey' => $subarea['zoneKey'],
                'ring' => $subarea['ring'],
            ],
            $policy['subareas']
        );
        usort(
            $subareas,
            static fn (array $left, array $right): int =>
                $left['key'] <=> $right['key']
        );
        try {
            $encoded = json_encode(
                [
                    'algorithmVersion' => 1,
                    'geometryKey' => $geometryKey,
                    'timeZone' => $policy['timeZone'],
                    'metersPerLocalUnit' =>
                        $policy['metersPerLocalUnit'],
                    'cuttingWidthMeters' =>
                        $policy['cuttingWidthMeters'],
                    'coverageCellSizeMeters' =>
                        $policy['coverageCellSizeMeters'],
                    'zoneBindings' => $policy['zoneBindings'],
                    'subareas' => $subareas,
                ],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Mowing analytics contract is not serializable.',
                0,
                $exception
            );
        }
        return hash('sha256', $encoded);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $policy
     *
     * @return array<string, mixed>
     */
    private static function snapshot(array $input, array $policy): array
    {
        $dayCells = [];
        $runCells = [];
        $subareaDayCells = [];
        $subareaDayLast = [];
        $days = [];
        $runs = [];
        $zoneState = [];
        $sampleCount = 0;
        $coverageTruncated = false;
        $zoneByKey = [];
        foreach ($input['zones'] as $zone) {
            $zoneByKey[$zone['zoneKey']] = $zone;
        }

        foreach ($input['pathSegments'] as $segment) {
            if ($segment['vehicleStateCode'] !== self::RUNNING_STATE) {
                continue;
            }
            $points = $segment['points'];
            foreach ($points as $point) {
                $zoneKey = $point['zoneKey'];
                if ($zoneKey === null || !isset($zoneByKey[$zoneKey])) {
                    continue;
                }
                $zoneState[$zoneKey] ??= [
                    'zoneKey' => $zoneKey,
                    'lastMowedAt' => 0,
                    'interruptionCount' => 0,
                    'resumeCount' => 0,
                ];
                $zoneState[$zoneKey]['lastMowedAt'] = max(
                    $zoneState[$zoneKey]['lastMowedAt'],
                    $point['receivedAt']
                );
            }
            for ($index = 1; $index < count($points); ++$index) {
                $left = $points[$index - 1];
                $right = $points[$index];
                $zoneKey = $right['zoneKey'];
                if (
                    $zoneKey === null
                    || $left['zoneKey'] !== $zoneKey
                    || !isset($zoneByKey[$zoneKey])
                ) {
                    continue;
                }
                $seconds = $right['receivedAt'] - $left['receivedAt'];
                if ($seconds <= 0 || $seconds > self::MAX_PAIR_GAP_SECONDS) {
                    continue;
                }
                $distanceLocal = hypot(
                    $right['localX'] - $left['localX'],
                    $right['localY'] - $left['localY']
                );
                if ($distanceLocal <= 0.0 || $distanceLocal > 50.0) {
                    continue;
                }
                $distanceMeters = $distanceLocal
                    * $policy['metersPerLocalUnit'];
                $date = self::dateAt(
                    $right['receivedAt'],
                    $policy['timeZone']
                );
                $runKey = self::runKey($input['geometryKey'], $zoneKey, $segment);
                $days[$date] ??= ['date' => $date, 'zones' => [], 'subareas' => []];
                $days[$date]['zones'][$zoneKey] ??= self::periodRow($zoneKey);
                $day = &$days[$date]['zones'][$zoneKey];
                $day['distanceMeters'] += $distanceMeters;
                $day['activeDurationSeconds'] += $seconds;
                $day['pointPairCount']++;
                $day['lastMowedAt'] = max(
                    $day['lastMowedAt'],
                    $right['receivedAt']
                );
                unset($day);

                $runs[$runKey] ??= [
                    'runKey' => $runKey,
                    'zoneKey' => $zoneKey,
                    'passSequence' => $segment['passSequence'],
                    'sessionSequence' => $segment['sessionSequence'],
                    'startedAt' => $left['receivedAt'],
                    'endedAt' => $right['receivedAt'],
                    'distanceMeters' => 0.0,
                    'activeDurationSeconds' => 0,
                    'estimatedArea' => null,
                    'pointPairCount' => 0,
                    'coverageTruncated' => false,
                ];
                $run = &$runs[$runKey];
                $run['startedAt'] = min($run['startedAt'], $left['receivedAt']);
                $run['endedAt'] = max($run['endedAt'], $right['receivedAt']);
                $run['distanceMeters'] += $distanceMeters;
                $run['activeDurationSeconds'] += $seconds;
                $run['pointPairCount']++;
                unset($run);

                if (
                    $policy['cuttingWidthMeters'] <= 0.0
                    || $coverageTruncated
                ) {
                    continue;
                }
                $cells = self::coverageCells(
                    $left,
                    $right,
                    $zoneByKey[$zoneKey],
                    $input['obstacles'],
                    $policy,
                    $sampleCount,
                    $coverageTruncated
                );
                foreach ($cells as $cellKey => $center) {
                    $dayCells[$date][$zoneKey][$cellKey] = true;
                    $runCells[$runKey][$cellKey] = true;
                    foreach ($policy['subareas'] as $subarea) {
                        if (
                            $subarea['zoneKey'] === $zoneKey
                            && self::pointInRing($center, $subarea['ring'])
                        ) {
                            $subareaDayCells[$date][$subarea['key']][$cellKey]
                                = true;
                            $subareaDayLast[$date][$subarea['key']] = max(
                                $subareaDayLast[$date][$subarea['key']] ?? 0,
                                $right['receivedAt']
                            );
                        }
                    }
                }
            }
        }

        foreach ($input['zones'] as $zone) {
            $statistics = $zone['statistics'];
            if (!is_array($statistics)) {
                continue;
            }
            $key = $zone['zoneKey'];
            $zoneState[$key] ??= [
                'zoneKey' => $key,
                'lastMowedAt' => 0,
                'interruptionCount' => 0,
                'resumeCount' => 0,
            ];
            $zoneState[$key]['interruptionCount'] = max(
                0,
                (int) ($statistics['interruptionCount'] ?? 0)
            );
            $zoneState[$key]['resumeCount'] = max(
                0,
                (int) ($statistics['resumeCount'] ?? 0)
            );
        }

        $cellArea = $policy['coverageCellSizeMeters'] ** 2;
        foreach ($days as $date => &$day) {
            foreach ($day['zones'] as $zoneKey => &$row) {
                $row['estimatedArea'] = $policy['cuttingWidthMeters'] > 0.0
                    ? count($dayCells[$date][$zoneKey] ?? []) * $cellArea
                    : null;
            }
            unset($row);
            foreach ($policy['subareas'] as $subarea) {
                $day['subareas'][] = [
                    'subareaKey' => $subarea['key'],
                    'estimatedArea' => $policy['cuttingWidthMeters'] > 0.0
                        ? count(
                            $subareaDayCells[$date][$subarea['key']] ?? []
                        ) * $cellArea
                        : null,
                    'lastMowedAt' =>
                        $subareaDayLast[$date][$subarea['key']] ?? 0,
                ];
            }
            $day['zones'] = array_values($day['zones']);
        }
        unset($day);
        foreach ($runs as $runKey => &$run) {
            $run['estimatedArea'] = $policy['cuttingWidthMeters'] > 0.0
                ? count($runCells[$runKey] ?? []) * $cellArea
                : null;
            $run['coverageTruncated'] = $coverageTruncated;
            $run['areaPerformanceSquareMetersPerHour'] =
                self::areaPerformance(
                    $run['estimatedArea'],
                    $run['activeDurationSeconds']
                );
        }
        unset($run);

        return [
            'days' => array_values($days),
            'runs' => array_values($runs),
            'zoneState' => array_values($zoneState),
            'coverageSampleCount' => $sampleCount,
            'coverageTruncated' => $coverageTruncated,
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param array<string, mixed> $zone
     * @param list<array<string, mixed>> $obstacles
     * @param array<string, mixed> $policy
     * @param int $sampleCount
     * @param bool $truncated
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private static function coverageCells(
        array $left,
        array $right,
        array $zone,
        array $obstacles,
        array $policy,
        int &$sampleCount,
        bool &$truncated
    ): array {
        $cellLocal = $policy['coverageCellSizeMeters']
            / $policy['metersPerLocalUnit'];
        $halfWidthLocal = $policy['cuttingWidthMeters']
            / $policy['metersPerLocalUnit'] / 2.0;
        $dx = $right['localX'] - $left['localX'];
        $dy = $right['localY'] - $left['localY'];
        $length = hypot($dx, $dy);
        if ($length <= 0.0) {
            return [];
        }
        $nx = -$dy / $length;
        $ny = $dx / $length;
        $alongSteps = max(1, (int) ceil($length / ($cellLocal / 2.0)));
        $acrossSteps = max(
            1,
            (int) ceil((2.0 * $halfWidthLocal) / ($cellLocal / 2.0))
        );
        $result = [];
        for ($along = 0; $along <= $alongSteps; ++$along) {
            $ratio = $along / $alongSteps;
            $x = $left['localX'] + $dx * $ratio;
            $y = $left['localY'] + $dy * $ratio;
            for ($across = 0; $across <= $acrossSteps; ++$across) {
                ++$sampleCount;
                if ($sampleCount > self::MAX_COVERAGE_SAMPLES) {
                    $truncated = true;
                    return $result;
                }
                $offset = -$halfWidthLocal
                    + (2.0 * $halfWidthLocal * $across / $acrossSteps);
                $sample = [$x + $nx * $offset, $y + $ny * $offset];
                $ix = (int) floor($sample[0] / $cellLocal);
                $iy = (int) floor($sample[1] / $cellLocal);
                $center = [
                    ($ix + 0.5) * $cellLocal,
                    ($iy + 0.5) * $cellLocal,
                ];
                if (!self::pointInRing($center, $zone['ring'])) {
                    continue;
                }
                $blocked = false;
                foreach ($zone['obstacleIndexes'] as $obstacleIndex) {
                    $obstacle = $obstacles[$obstacleIndex - 1] ?? null;
                    if (
                        is_array($obstacle)
                        && self::pointInRing($center, $obstacle['ring'])
                    ) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    $result[$ix . ':' . $iy] = $center;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $scene
     *
     * @return array<string, mixed>
     */
    private static function scene(array $scene): array
    {
        $revision = $scene['revision'] ?? null;
        if (
            ($scene['formatVersion'] ?? null) !== 1
            || !is_array($revision)
            || ($revision['state'] ?? null) !== 'accepted'
            || ($revision['pathCompatible'] ?? null) !== true
            || !is_string($revision['geometryKey'] ?? null)
            || preg_match(self::HASH_PATTERN, $revision['geometryKey']) !== 1
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics scene revision is invalid.'
            );
        }
        $zones = $scene['zones'] ?? null;
        $obstacles = $scene['obstacles'] ?? null;
        $segments = $scene['path']['segments'] ?? null;
        if (
            !is_array($zones)
            || !array_is_list($zones)
            || count($zones) > self::MAX_ZONES
            || !is_array($obstacles)
            || !array_is_list($obstacles)
            || !is_array($segments)
            || !array_is_list($segments)
            || count($segments) > self::MAX_SEGMENTS
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics scene collections are invalid.'
            );
        }
        $normalizedZones = [];
        foreach ($zones as $zone) {
            $key = $zone['zoneKey'] ?? null;
            if (
                !is_array($zone)
                || !is_int($zone['sequence'] ?? null)
                || !is_string($key)
                || preg_match(self::HASH_PATTERN, $key) !== 1
            ) {
                continue;
            }
            $normalizedZones[] = [
                'zoneId' => is_int($zone['zoneId'] ?? null)
                    ? $zone['zoneId']
                    : $zone['sequence'],
                'zoneKey' => $key,
                'label' => self::text($zone['label'] ?? null, 128),
                'reportedNetArea' => self::positiveOrNull(
                    $zone['reportedNetArea'] ?? null
                ),
                'ring' => self::ring($zone['ring'] ?? null),
                'obstacleIndexes' => self::integerList(
                    $zone['obstacleIndexes'] ?? []
                ),
                'statistics' => is_array($zone['statistics'] ?? null)
                    ? $zone['statistics']
                    : null,
            ];
        }
        $normalizedObstacles = [];
        foreach ($obstacles as $obstacle) {
            if (!is_array($obstacle)) {
                throw new InvalidArgumentException(
                    'Mowing analytics obstacle is invalid.'
                );
            }
            $normalizedObstacles[] = [
                'ring' => self::ring($obstacle['ring'] ?? null),
            ];
        }
        $normalizedSegments = [];
        $pointCount = 0;
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                throw new InvalidArgumentException(
                    'Mowing analytics path segment is invalid.'
                );
            }
            $points = $segment['points'] ?? null;
            if (!is_array($points) || !array_is_list($points)) {
                throw new InvalidArgumentException(
                    'Mowing analytics path points are invalid.'
                );
            }
            $normalizedPoints = [];
            foreach ($points as $point) {
                ++$pointCount;
                if ($pointCount > self::MAX_POINTS || !is_array($point)) {
                    throw new InvalidArgumentException(
                        'Mowing analytics path exceeds its point limit.'
                    );
                }
                $attribution = $point['attribution'] ?? null;
                $zoneKey = is_array($attribution)
                    ? ($attribution['zoneKey'] ?? null)
                    : null;
                if (
                    $zoneKey !== null
                    && (!is_string($zoneKey)
                        || preg_match(self::HASH_PATTERN, $zoneKey) !== 1)
                ) {
                    throw new InvalidArgumentException(
                        'Mowing analytics point zone is invalid.'
                    );
                }
                $normalizedPoints[] = [
                    'localX' => self::finite($point['localX'] ?? null),
                    'localY' => self::finite($point['localY'] ?? null),
                    'receivedAt' => self::positiveInteger(
                        $point['receivedAt'] ?? null
                    ),
                    'zoneKey' => $zoneKey,
                ];
            }
            $normalizedSegments[] = [
                'passSequence' => self::nonNegativeIntegerOrNull(
                    $segment['passSequence'] ?? null
                ),
                'sessionSequence' => self::nonNegativeIntegerOrNull(
                    $segment['sessionSequence'] ?? null
                ),
                'vehicleStateCode' => self::nonNegativeInteger(
                    $segment['vehicleStateCode'] ?? 0
                ),
                'points' => $normalizedPoints,
            ];
        }
        return [
            'geometryKey' => $revision['geometryKey'],
            'zones' => $normalizedZones,
            'obstacles' => $normalizedObstacles,
            'pathSegments' => $normalizedSegments,
        ];
    }

    /** @param array<string, mixed> $options */
    private static function policy(array $options): array
    {
        $timeZone = $options['timeZone'] ?? 'UTC';
        if (!is_string($timeZone) || $timeZone === '') {
            throw new InvalidArgumentException(
                'Mowing analytics time zone is invalid.'
            );
        }
        try {
            new DateTimeZone($timeZone);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Mowing analytics time zone is invalid.'
            );
        }
        $metersPerLocalUnit = self::positive(
            $options['metersPerLocalUnit'] ?? 1.0,
            1000.0
        );
        $cuttingWidth = self::nonNegative(
            $options['cuttingWidthMeters'] ?? 0.0,
            10.0
        );
        $cellSize = self::positive(
            $options['coverageCellSizeMeters'] ?? 0.1,
            5.0
        );
        if ($cuttingWidth > 0.0 && $cellSize > $cuttingWidth) {
            throw new InvalidArgumentException(
                'Coverage cells must not exceed the cutting width.'
            );
        }
        $warningDays = self::boundedInteger(
            $options['recencyWarningDays'] ?? 7,
            1,
            365
        );
        $criticalDays = self::boundedInteger(
            $options['recencyCriticalDays'] ?? 14,
            2,
            730
        );
        if ($criticalDays <= $warningDays) {
            throw new InvalidArgumentException(
                'Critical mowing recency must exceed warning recency.'
            );
        }
        return [
            'timeZone' => $timeZone,
            'metersPerLocalUnit' => $metersPerLocalUnit,
            'cuttingWidthMeters' => $cuttingWidth,
            'coverageCellSizeMeters' => $cellSize,
            'recencyWarningDays' => $warningDays,
            'recencyCriticalDays' => $criticalDays,
            'zoneBindings' => self::zoneBindings(
                $options['zoneBindings'] ?? []
            ),
            'subareas' => self::subareas($options['subareas'] ?? []),
        ];
    }

    /** @return list<array{zoneId: int, zoneKey: string}> */
    private static function zoneBindings(mixed $value): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || count($value) > self::MAX_ZONES
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics zone bindings are invalid.'
            );
        }
        $result = [];
        $ids = [];
        $keys = [];
        foreach ($value as $binding) {
            $zoneId = is_array($binding) ? ($binding['zoneId'] ?? null) : null;
            $zoneKey = is_array($binding)
                ? ($binding['zoneKey'] ?? null)
                : null;
            if (
                !is_int($zoneId)
                || $zoneId <= 0
                || isset($ids[$zoneId])
                || !is_string($zoneKey)
                || preg_match(self::HASH_PATTERN, $zoneKey) !== 1
                || isset($keys[$zoneKey])
            ) {
                throw new InvalidArgumentException(
                    'Mowing analytics zone binding is invalid.'
                );
            }
            $ids[$zoneId] = true;
            $keys[$zoneKey] = true;
            $result[] = ['zoneId' => $zoneId, 'zoneKey' => $zoneKey];
        }
        usort(
            $result,
            static fn (array $left, array $right): int =>
                $left['zoneId'] <=> $right['zoneId']
        );
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $zones
     * @param list<array{zoneId: int, zoneKey: string}> $bindings
     */
    private static function validateZoneBindings(
        array $zones,
        array $bindings
    ): void {
        $actual = array_map(
            static fn (array $zone): array => [
                'zoneId' => $zone['zoneId'],
                'zoneKey' => $zone['zoneKey'],
            ],
            $zones
        );
        usort(
            $actual,
            static fn (array $left, array $right): int =>
                $left['zoneId'] <=> $right['zoneId']
        );
        if ($actual !== $bindings) {
            throw new InvalidArgumentException(
                'Mowing analytics zone bindings do not match the scene.'
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private static function subareas(mixed $value): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || count($value) > self::MAX_SUBAREAS
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics subareas are invalid.'
            );
        }
        $result = [];
        $seen = [];
        foreach ($value as $subarea) {
            if (!is_array($subarea)) {
                throw new InvalidArgumentException(
                    'Mowing analytics subarea is invalid.'
                );
            }
            $key = $subarea['key'] ?? null;
            $zoneKey = $subarea['zoneKey'] ?? null;
            if (
                !is_string($key)
                || preg_match(self::KEY_PATTERN, $key) !== 1
                || isset($seen[$key])
                || !is_string($zoneKey)
                || preg_match(self::HASH_PATTERN, $zoneKey) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Mowing analytics subarea identity is invalid.'
                );
            }
            $seen[$key] = true;
            $result[] = [
                'key' => $key,
                'zoneKey' => $zoneKey,
                'label' => self::text($subarea['label'] ?? null, 128),
                'ring' => self::ring($subarea['ring'] ?? null),
            ];
        }
        return $result;
    }

    /** @param array<string, mixed> $state */
    private static function state(array $state): array
    {
        if (
            ($state['formatVersion'] ?? null) !== 1
            || !is_array($state['revisions'] ?? null)
            || !array_is_list($state['revisions'])
            || count($state['revisions']) > self::MAX_REVISIONS
            || !is_array($state['counters'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics state is invalid.'
            );
        }
        foreach (
            [
                'updateCount',
                'coverageSampleCount',
                'coverageSampleLimitCount',
                'evictedRevisionCount',
                'evictedDayCount',
                'evictedRunCount',
            ] as $counter
        ) {
            if (
                !is_int($state['counters'][$counter] ?? null)
                || $state['counters'][$counter] < 0
            ) {
                throw new InvalidArgumentException(
                    'Mowing analytics counter is invalid.'
                );
            }
        }
        foreach ($state['revisions'] as $revision) {
            if (
                !is_array($revision)
                || !is_string($revision['geometryKey'] ?? null)
                || preg_match(self::HASH_PATTERN, $revision['geometryKey']) !== 1
                || !is_string($revision['contractKey'] ?? null)
                || preg_match(self::HASH_PATTERN, $revision['contractKey']) !== 1
                || !is_int($revision['firstObservedAt'] ?? null)
                || !is_int($revision['lastObservedAt'] ?? null)
                || $revision['firstObservedAt'] <= 0
                || $revision['lastObservedAt'] < $revision['firstObservedAt']
                || !is_array($revision['zones'] ?? null)
                || !array_is_list($revision['zones'])
                || !is_array($revision['subareas'] ?? null)
                || !array_is_list($revision['subareas'])
                || !is_array($revision['days'] ?? null)
                || !array_is_list($revision['days'])
                || count($revision['days']) > self::MAX_DAYS
                || !is_array($revision['runs'] ?? null)
                || !array_is_list($revision['runs'])
                || count($revision['runs']) > self::MAX_RUNS
                || !is_array($revision['zoneState'] ?? null)
                || !array_is_list($revision['zoneState'])
            ) {
                throw new InvalidArgumentException(
                    'Mowing analytics revision is invalid.'
                );
            }
            self::validateStoredRevision($revision);
        }
        return $state;
    }

    /** @param array<string, mixed> $revision */
    private static function validateStoredRevision(array $revision): void
    {
        if (
            count($revision['zones']) > self::MAX_ZONES
            || count($revision['subareas']) > self::MAX_SUBAREAS
            || count($revision['zoneState']) > self::MAX_ZONES
            || !is_array($revision['coverageContract'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'Stored mowing analytics definition is invalid.'
            );
        }
        $keys = [];
        foreach ($revision['zones'] as $zone) {
            if (
                !is_array($zone)
                || !is_int($zone['zoneId'] ?? null)
                || $zone['zoneId'] <= 0
                || !is_string($zone['zoneKey'] ?? null)
                || preg_match(self::HASH_PATTERN, $zone['zoneKey']) !== 1
                || isset($keys[$zone['zoneKey']])
            ) {
                throw new InvalidArgumentException(
                    'Stored mowing analytics zone is invalid.'
                );
            }
            $keys[$zone['zoneKey']] = true;
            self::text($zone['label'] ?? null, 128);
            self::positiveOrNull($zone['reportedNetArea'] ?? null);
        }
        $subareaKeys = [];
        foreach ($revision['subareas'] as $subarea) {
            if (
                !is_array($subarea)
                || !is_string($subarea['key'] ?? null)
                || preg_match(self::KEY_PATTERN, $subarea['key']) !== 1
                || isset($subareaKeys[$subarea['key']])
                || !isset($keys[$subarea['zoneKey'] ?? ''])
            ) {
                throw new InvalidArgumentException(
                    'Stored mowing analytics subarea is invalid.'
                );
            }
            $subareaKeys[$subarea['key']] = true;
            self::text($subarea['label'] ?? null, 128);
        }
        foreach ($revision['days'] as $day) {
            if (
                !is_array($day)
                || !is_string($day['date'] ?? null)
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day['date']) !== 1
                || !is_array($day['zones'] ?? null)
                || !array_is_list($day['zones'])
                || count($day['zones']) > self::MAX_ZONES
                || !is_array($day['subareas'] ?? null)
                || !array_is_list($day['subareas'])
                || count($day['subareas']) > self::MAX_SUBAREAS
            ) {
                throw new InvalidArgumentException(
                    'Stored mowing analytics day is invalid.'
                );
            }
            foreach ($day['zones'] as $row) {
                self::validatePeriodRow($row, 'zoneKey', self::HASH_PATTERN);
            }
            foreach ($day['subareas'] as $row) {
                self::validatePeriodRow($row, 'subareaKey', self::KEY_PATTERN);
            }
        }
        foreach ($revision['runs'] as $run) {
            if (
                !is_array($run)
                || !is_string($run['runKey'] ?? null)
                || preg_match(self::HASH_PATTERN, $run['runKey']) !== 1
                || !isset($keys[$run['zoneKey'] ?? ''])
                || !is_int($run['startedAt'] ?? null)
                || !is_int($run['endedAt'] ?? null)
                || $run['startedAt'] <= 0
                || $run['endedAt'] < $run['startedAt']
                || !is_int($run['activeDurationSeconds'] ?? null)
                || $run['activeDurationSeconds'] < 0
                || !is_int($run['pointPairCount'] ?? null)
                || $run['pointPairCount'] < 0
                || !is_bool($run['coverageTruncated'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    'Stored mowing analytics run is invalid.'
                );
            }
            self::nonNegativeNumber($run['distanceMeters'] ?? null);
            self::nonNegativeNumberOrNull($run['estimatedArea'] ?? null);
            self::nonNegativeNumberOrNull(
                $run['areaPerformanceSquareMetersPerHour'] ?? null
            );
        }
        foreach ($revision['zoneState'] as $row) {
            if (
                !is_array($row)
                || !isset($keys[$row['zoneKey'] ?? ''])
                || !is_int($row['lastMowedAt'] ?? null)
                || $row['lastMowedAt'] < 0
                || !is_int($row['interruptionCount'] ?? null)
                || $row['interruptionCount'] < 0
                || !is_int($row['resumeCount'] ?? null)
                || $row['resumeCount'] < 0
            ) {
                throw new InvalidArgumentException(
                    'Stored mowing analytics zone state is invalid.'
                );
            }
        }
    }

    private static function validatePeriodRow(
        mixed $row,
        string $identity,
        string $pattern
    ): void {
        if (
            !is_array($row)
            || !is_string($row[$identity] ?? null)
            || preg_match($pattern, $row[$identity]) !== 1
        ) {
            throw new InvalidArgumentException(
                'Stored mowing analytics period identity is invalid.'
            );
        }
        if ($identity === 'zoneKey') {
            foreach (
                [
                    'activeDurationSeconds',
                    'pointPairCount',
                    'lastMowedAt',
                ] as $field
            ) {
                if (!is_int($row[$field] ?? null) || $row[$field] < 0) {
                    throw new InvalidArgumentException(
                        'Stored mowing analytics period value is invalid.'
                    );
                }
            }
            self::nonNegativeNumber($row['distanceMeters'] ?? null);
        } elseif (
            !is_int($row['lastMowedAt'] ?? null)
            || $row['lastMowedAt'] < 0
        ) {
            throw new InvalidArgumentException(
                'Stored mowing analytics subarea time is invalid.'
            );
        }
        self::nonNegativeNumberOrNull($row['estimatedArea'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $incoming
     *
     * @return list<array<string, mixed>>
     */
    private static function mergeRows(
        array $existing,
        array $incoming,
        string $identity
    ): array {
        $rows = [];
        foreach ($existing as $row) {
            if (is_string($row[$identity] ?? null)) {
                $rows[$row[$identity]] = $row;
            }
        }
        foreach ($incoming as $row) {
            if (!is_string($row[$identity] ?? null)) {
                throw new InvalidArgumentException(
                    'Mowing analytics aggregate row is invalid.'
                );
            }
            $key = $row[$identity];
            $rows[$key] = isset($rows[$key])
                ? self::mergeAggregate($rows[$key], $row)
                : $row;
        }
        return array_values($rows);
    }

    /** @return array<string, mixed> */
    private static function mergeAggregate(array $left, array $right): array
    {
        $result = $left;
        foreach ($right as $field => $value) {
            if ($value === null && array_key_exists($field, $left)) {
                continue;
            }
            if ($field === 'zones' || $field === 'subareas') {
                $identity = $field === 'zones' ? 'zoneKey' : 'subareaKey';
                $result[$field] = self::mergeRows(
                    is_array($left[$field] ?? null) ? $left[$field] : [],
                    is_array($value) ? $value : [],
                    $identity
                );
                continue;
            }
            if (in_array($field, ['startedAt', 'firstObservedAt'], true)) {
                $result[$field] = min((int) ($left[$field] ?? $value), (int) $value);
                continue;
            }
            if (
                in_array(
                    $field,
                    [
                        'endedAt',
                        'lastObservedAt',
                        'lastMowedAt',
                        'distanceMeters',
                        'activeDurationSeconds',
                        'estimatedArea',
                        'pointPairCount',
                        'interruptionCount',
                        'resumeCount',
                        'areaPerformanceSquareMetersPerHour',
                    ],
                    true
                )
                && (is_int($value) || is_float($value))
            ) {
                $current = $left[$field] ?? null;
                $result[$field] = is_int($current) || is_float($current)
                    ? max($current, $value)
                    : $value;
                continue;
            }
            if ($field === 'coverageTruncated') {
                $result[$field] = ($left[$field] ?? false) || $value === true;
                continue;
            }
            $result[$field] = $value;
        }
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private static function retainLatest(
        array $rows,
        int $maximum,
        string $field
    ): array {
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                ($left[$field] ?? 0) <=> ($right[$field] ?? 0)
        );
        $evicted = max(0, count($rows) - $maximum);
        if ($evicted > 0) {
            $rows = array_slice($rows, -$maximum);
        }
        return [$rows, $evicted];
    }

    /** @return array<string, mixed> */
    private static function periodRow(string $zoneKey): array
    {
        return [
            'zoneKey' => $zoneKey,
            'distanceMeters' => 0.0,
            'activeDurationSeconds' => 0,
            'estimatedArea' => null,
            'pointPairCount' => 0,
            'lastMowedAt' => 0,
        ];
    }

    private static function runKey(
        string $geometryKey,
        string $zoneKey,
        array $segment
    ): string {
        $identity = is_int($segment['passSequence'])
            ? 'pass:' . $segment['passSequence']
            : 'session:' . ($segment['sessionSequence'] ?? 0)
                . ':start:' . ($segment['points'][0]['receivedAt'] ?? 0);
        return hash('sha256', $geometryKey . '|' . $zoneKey . '|' . $identity);
    }

    /** @return array<string, float|int|null> */
    private static function periodTotals(
        array $days,
        string $zoneKey,
        string $today,
        string $weekStart,
        string $monthPrefix
    ): array {
        $result = [
            'todayEstimatedArea' => null,
            'weekEstimatedArea' => null,
            'monthEstimatedArea' => null,
        ];
        foreach ($days as $day) {
            if (!is_array($day) || !is_string($day['date'] ?? null)) {
                continue;
            }
            $row = self::rowByKey($day['zones'] ?? [], 'zoneKey', $zoneKey);
            $area = $row['estimatedArea'] ?? null;
            if (!is_float($area) && !is_int($area)) {
                continue;
            }
            if ($day['date'] === $today) {
                $result['todayEstimatedArea'] = (float) $area;
            }
            if ($day['date'] >= $weekStart && $day['date'] <= $today) {
                $result['weekEstimatedArea'] =
                    ($result['weekEstimatedArea'] ?? 0.0) + $area;
            }
            if (str_starts_with($day['date'], $monthPrefix)) {
                $result['monthEstimatedArea'] =
                    ($result['monthEstimatedArea'] ?? 0.0) + $area;
            }
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function subareaProjection(
        array $revision,
        string $today,
        string $weekStart,
        string $monthPrefix,
        int $now,
        array $policy
    ): array {
        $result = [];
        foreach ($revision['subareas'] as $definition) {
            $todayArea = null;
            $weekArea = null;
            $monthArea = null;
            $lastMowedAt = null;
            foreach ($revision['days'] as $day) {
                $row = self::rowByKey(
                    $day['subareas'] ?? [],
                    'subareaKey',
                    $definition['key']
                );
                $area = $row['estimatedArea'] ?? null;
                if (!is_int($area) && !is_float($area)) {
                    $area = null;
                }
                if ($day['date'] === $today && $area !== null) {
                    $todayArea = (float) $area;
                }
                if (
                    $area !== null
                    && $day['date'] >= $weekStart
                    && $day['date'] <= $today
                ) {
                    $weekArea = ($weekArea ?? 0.0) + $area;
                }
                if (
                    $area !== null
                    && str_starts_with($day['date'], $monthPrefix)
                ) {
                    $monthArea = ($monthArea ?? 0.0) + $area;
                }
                if (is_int($row['lastMowedAt'] ?? null)) {
                    $lastMowedAt = max(
                        $lastMowedAt ?? 0,
                        $row['lastMowedAt']
                    );
                }
            }
            $lastMowedAt = is_int($lastMowedAt) && $lastMowedAt > 0
                ? $lastMowedAt
                : null;
            $recencyDays = is_int($lastMowedAt)
                ? max(0, intdiv(max(0, $now - $lastMowedAt), 86400))
                : null;
            $result[] = [
                'key' => $definition['key'],
                'zoneKey' => $definition['zoneKey'],
                'label' => $definition['label'],
                'todayEstimatedArea' => $todayArea,
                'weekEstimatedArea' => $weekArea,
                'monthEstimatedArea' => $monthArea,
                'lastMowedAt' => $lastMowedAt,
                'recencyDays' => $recencyDays,
                'recencyState' => self::recencyState($recencyDays, $policy),
                'projectedAt' => $now,
                'quality' => $policy['cuttingWidthMeters'] > 0.0 ? 2 : 0,
            ];
        }
        return $result;
    }

    private static function recencyState(
        ?int $days,
        array $policy
    ): int {
        if ($days === null) {
            return 0;
        }
        if ($days >= $policy['recencyCriticalDays']) {
            return 3;
        }
        if ($days >= $policy['recencyWarningDays']) {
            return 2;
        }
        return 1;
    }

    private static function quality(?array $run, array $policy): int
    {
        if ($run === null) {
            return 0;
        }
        if (
            $policy['cuttingWidthMeters'] <= 0.0
            || ($run['coverageTruncated'] ?? false) === true
        ) {
            return 1;
        }
        return 2;
    }

    /** @return array<string, mixed> */
    private static function emptyProjection(
        string $geometryKey,
        int $now,
        array $policy,
        array $counters
    ): array {
        return [
            'formatVersion' => 1,
            'authority' => 'mqtt-derived-diagnostic',
            'geometryKey' => $geometryKey,
            'observedAt' => null,
            'projectedAt' => $now,
            'state' => 'no-data',
            'contracts' => [
                'restRemainsStateAuthority' => true,
                'distanceUnit' => 'metre-candidate',
                'geometricCoverage' => self::coverageContract($policy),
                'periodAreaSemantics' =>
                    'sum-of-daily-unique-cell-estimates',
                'rainReasonEvidence' => 'not-observed',
                'subareaStatistics' => 'prepared-no-data',
            ],
            'latestRun' => null,
            'zones' => [],
            'subareas' => [],
            'counters' => $counters,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function rowByKey(
        array $rows,
        string $field,
        string $value
    ): ?array {
        foreach ($rows as $row) {
            if (($row[$field] ?? null) === $value) {
                return $row;
            }
        }
        return null;
    }

    private static function areaPerformance(
        mixed $area,
        mixed $durationSeconds
    ): ?float {
        if (
            (!is_int($area) && !is_float($area))
            || !is_int($durationSeconds)
            || $durationSeconds <= 0
        ) {
            return null;
        }
        return (float) $area * 3600.0 / $durationSeconds;
    }

    private static function dateAt(int $timestamp, string $timeZone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timeZone))
            ->format('Y-m-d');
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param list<array{0: float, 1: float}> $ring
     */
    private static function pointInRing(array $point, array $ring): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            $intersects = (($yi > $point[1]) !== ($yj > $point[1]))
                && ($point[0] < ($xj - $xi) * ($point[1] - $yi)
                    / (($yj - $yi) ?: PHP_FLOAT_EPSILON) + $xi);
            if ($intersects) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** @return list<array{0: float, 1: float}> */
    private static function ring(mixed $value): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || count($value) < 4
            || count($value) > 1025
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics ring is invalid.'
            );
        }
        $ring = [];
        foreach ($value as $point) {
            if (!is_array($point) || count($point) !== 2) {
                throw new InvalidArgumentException(
                    'Mowing analytics ring point is invalid.'
                );
            }
            $ring[] = [
                self::finite($point[0] ?? null),
                self::finite($point[1] ?? null),
            ];
        }
        if ($ring[0] !== $ring[array_key_last($ring)]) {
            throw new InvalidArgumentException(
                'Mowing analytics ring is not closed.'
            );
        }
        return $ring;
    }

    /** @return list<int> */
    private static function integerList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(
                'Mowing analytics integer list is invalid.'
            );
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_int($item) || $item < 1 || $item > 256) {
                throw new InvalidArgumentException(
                    'Mowing analytics integer is invalid.'
                );
            }
            $result[] = $item;
        }
        return $result;
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException(
                'Mowing analytics text is invalid.'
            );
        }
        return $value;
    }

    private static function positiveInteger(mixed $value): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException(
                'Mowing analytics timestamp is invalid.'
            );
        }
        return $value;
    }

    private static function nonNegativeInteger(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(
                'Mowing analytics sequence is invalid.'
            );
        }
        return $value;
    }

    private static function nonNegativeIntegerOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        return self::nonNegativeInteger($value);
    }

    private static function boundedInteger(
        mixed $value,
        int $minimum,
        int $maximum
    ): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(
                'Mowing analytics integer option is invalid.'
            );
        }
        return $value;
    }

    private static function positive(mixed $value, float $maximum): float
    {
        $number = self::finite($value);
        if ($number <= 0.0 || $number > $maximum) {
            throw new InvalidArgumentException(
                'Mowing analytics positive number is invalid.'
            );
        }
        return $number;
    }

    private static function nonNegative(mixed $value, float $maximum): float
    {
        $number = self::finite($value);
        if ($number < 0.0 || $number > $maximum) {
            throw new InvalidArgumentException(
                'Mowing analytics non-negative number is invalid.'
            );
        }
        return $number;
    }

    private static function positiveOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        return self::positive($value, 1000 * 1000 * 1000.0);
    }

    private static function nonNegativeNumber(mixed $value): float
    {
        return self::nonNegative($value, 1000 * 1000 * 1000.0);
    }

    private static function nonNegativeNumberOrNull(mixed $value): ?float
    {
        return $value === null ? null : self::nonNegativeNumber($value);
    }

    private static function finite(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException(
                'Mowing analytics number is invalid.'
            );
        }
        $number = (float) $value;
        if (!is_finite($number) || abs($number) > 1000 * 1000 * 1000.0) {
            throw new InvalidArgumentException(
                'Mowing analytics number exceeds the limit.'
            );
        }
        return $number;
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
                'Mowing analytics state cannot be encoded.',
                0,
                $exception
            );
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            throw new InvalidArgumentException(
                'Mowing analytics state exceeds the serialized limit.'
            );
        }
        return $encoded;
    }
}
