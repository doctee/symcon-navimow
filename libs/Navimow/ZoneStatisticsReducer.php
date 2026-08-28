<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;

final class ZoneStatisticsReducer
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';

    public static function reduce(
        array $ledgerProjection,
        array $configuredZoneAreas = []
    ): array {
        $passes = $ledgerProjection['passes'] ?? null;
        $transitions = $ledgerProjection['transitions'] ?? null;
        if (
            !is_array($passes)
            || !array_is_list($passes)
            || count($passes) > 32
            || !is_array($transitions)
            || !array_is_list($transitions)
            || count($transitions) > 64
        ) {
            throw new InvalidArgumentException(
                'Task projection is invalid or unbounded.'
            );
        }
        $areas = self::configuredAreas($configuredZoneAreas);
        $zones = [];
        foreach ($passes as $pass) {
            $normalized = self::pass($pass);
            $areaKey = $normalized['areaKey'];
            if ($areaKey === null) {
                continue;
            }
            $passTransitions = array_values(array_filter(
                $transitions,
                static fn (mixed $transition): bool => is_array($transition)
                    && ($transition['passSequence'] ?? null)
                        === $normalized['sequence']
            ));
            $passStatistic = self::passStatistic(
                $normalized,
                $passTransitions,
                $areas[$areaKey] ?? null
            );
            $zones[$areaKey] ??= self::emptyZone(
                $areaKey,
                $areas[$areaKey] ?? null
            );
            $zones[$areaKey]['passes'][] = $passStatistic;
            $zones[$areaKey]['observedAreaTotal'] +=
                $passStatistic['observedAreaDelta'];
            $zones[$areaKey]['completedPassCount'] +=
                $passStatistic['completionObserved'] ? 1 : 0;
            $zones[$areaKey]['interruptionCount'] +=
                $passStatistic['interruptionCount'];
            $zones[$areaKey]['resumeCount'] +=
                $passStatistic['resumeCount'];
            $zones[$areaKey]['firstObservedAt'] = min(
                $zones[$areaKey]['firstObservedAt'],
                $passStatistic['startedAt']
            );
            $zones[$areaKey]['lastObservedAt'] = max(
                $zones[$areaKey]['lastObservedAt'],
                $passStatistic['lastObservedAt']
            );
            $zones[$areaKey]['latestPass'] = $passStatistic;
        }

        foreach ($zones as &$zone) {
            $zone['retainedPassCount'] = count($zone['passes']);
            $zone['observedAreaTotal'] = round(
                $zone['observedAreaTotal'],
                6
            );
            $zone['confidence'] = self::confidence($zone['passes']);
        }
        unset($zone);
        ksort($zones);

        return [
            'formatVersion' => 1,
            'authority' => 'mqtt-inference',
            'percentageContract' => [
                'passProgressPercent' => 'task-progress-candidate',
                'latestObservedAreaPercent' =>
                    'latest-pass-area/configured-zone-area',
                'geometricCoveragePercent' => 'not-implemented',
            ],
            'zones' => array_values($zones),
        ];
    }

    private static function configuredAreas(array $areas): array
    {
        $normalized = [];
        foreach ($areas as $key => $area) {
            if (
                !is_string($key)
                || preg_match(self::HASH_PATTERN, $key) !== 1
                || (!is_int($area) && !is_float($area))
                || !is_finite((float) $area)
                || (float) $area <= 0.0
                || (float) $area > 1000000000.0
            ) {
                throw new InvalidArgumentException(
                    'Configured zone area is invalid.'
                );
            }
            $normalized[$key] = (float) $area;
        }

        return $normalized;
    }

    private static function pass(mixed $pass): array
    {
        if (
            !is_array($pass)
            || !is_int($pass['sequence'] ?? null)
            || !is_int($pass['startedAt'] ?? null)
            || !is_int($pass['lastObservedAt'] ?? null)
            || $pass['startedAt'] <= 0
            || $pass['lastObservedAt'] < $pass['startedAt']
        ) {
            throw new InvalidArgumentException('Task pass is invalid.');
        }
        $areaKey = $pass['partitionKey'] ?? $pass['boundaryKey'] ?? null;
        if (
            $areaKey !== null
            && (!is_string($areaKey)
                || preg_match(self::HASH_PATTERN, $areaKey) !== 1)
        ) {
            throw new InvalidArgumentException('Task area key is invalid.');
        }

        return $pass + ['areaKey' => $areaKey];
    }

    private static function passStatistic(
        array $pass,
        array $transitions,
        ?float $configuredArea
    ): array {
        $firstArea = self::numberOrNull($pass['firstSubtotalArea'] ?? null);
        $maximumArea = self::numberOrNull($pass['maxSubtotalArea'] ?? null);
        $observedArea = $firstArea !== null && $maximumArea !== null
            ? max(0.0, $maximumArea - $firstArea)
            : 0.0;
        $progress = self::numberOrNull($pass['maxProgress'] ?? null);
        $passProgressPercent = $progress === null
            ? null
            : min(100.0, max(0.0, $progress / 100.0));
        $interruptionCount = 0;
        $resumeCount = 0;
        foreach ($transitions as $transition) {
            if (($transition['type'] ?? null) === 'delay-change') {
                if (($transition['taskDelay'] ?? null) === true) {
                    $interruptionCount++;
                } elseif (($transition['taskDelay'] ?? null) === false) {
                    $resumeCount++;
                }
            }
            if (($transition['type'] ?? null) === 'transport-session-change') {
                $resumeCount++;
            }
        }
        $areaPercent = $configuredArea === null
            ? null
            : round(($observedArea / $configuredArea) * 100.0, 4);

        return [
            'passSequence' => $pass['sequence'],
            'startedAt' => $pass['startedAt'],
            'lastObservedAt' => $pass['lastObservedAt'],
            'completionObserved' =>
                ($pass['completionObservedAt'] ?? null) !== null,
            'passProgressPercent' => $passProgressPercent,
            'observedAreaDelta' => round($observedArea, 6),
            'latestObservedAreaPercent' => $areaPercent,
            'interruptionCount' => $interruptionCount,
            'resumeCount' => $resumeCount,
            'evidenceSource' => 'task-pass-summary',
        ];
    }

    private static function emptyZone(
        string $areaKey,
        ?float $configuredArea
    ): array {
        return [
            'areaKey' => $areaKey,
            'configuredZoneArea' => $configuredArea,
            'firstObservedAt' => PHP_INT_MAX,
            'lastObservedAt' => 0,
            'retainedPassCount' => 0,
            'completedPassCount' => 0,
            'interruptionCount' => 0,
            'resumeCount' => 0,
            'observedAreaTotal' => 0.0,
            'confidence' => 'low',
            'latestPass' => null,
            'passes' => [],
        ];
    }

    private static function confidence(array $passes): string
    {
        foreach (array_reverse($passes) as $pass) {
            if (
                $pass['passProgressPercent'] !== null
                && $pass['observedAreaDelta'] > 0.0
            ) {
                return 'high';
            }
            if (
                $pass['passProgressPercent'] !== null
                || $pass['observedAreaDelta'] > 0.0
            ) {
                return 'medium';
            }
        }

        return 'low';
    }

    private static function numberOrNull(mixed $value): ?float
    {
        if (
            (!is_int($value) && !is_float($value))
            || !is_finite((float) $value)
        ) {
            return null;
        }

        return (float) $value;
    }
}
