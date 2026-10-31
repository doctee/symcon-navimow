<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;

/**
 * Display-only history. Never use this filtered cache for motion diagnostics.
 */
final class RetainedMapPath
{
    private const MAX_BYTES = 1100 * 1024;

    /** @return array<string, mixed> */
    public static function restore(string $encoded): array
    {
        if ($encoded === '' || $encoded === '{}') {
            return [
                'formatVersion' => 1,
                'geometryKey' => null,
                'phase' => 'unknown',
                'after' => 0,
                'observedAt' => 0,
                'selectionTruncated' => false,
                'working' => RevisionBoundedTrackStore::initialState(),
                'display' => RevisionBoundedTrackStore::initialState(),
            ];
        }
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Display track exceeds limit.');
        }
        $state = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($state)
            || ($state['formatVersion'] ?? null) !== 1
            || !is_string($state['geometryKey'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $state['geometryKey']) !== 1
            || !in_array($state['phase'] ?? null, ['unknown', 'running', 'docked'], true)
            || !is_int($state['after'] ?? null) || $state['after'] < 0
            || !is_int($state['observedAt'] ?? null) || $state['observedAt'] < 0
            || !is_bool($state['selectionTruncated'] ?? null)
            || !is_array($state['working'] ?? null)
            || !is_array($state['display'] ?? null)
        ) {
            throw new InvalidArgumentException('Display track state is invalid.');
        }
        RevisionBoundedTrackStore::serializeState($state['working']);
        RevisionBoundedTrackStore::serializeState($state['display']);
        return $state;
    }

    /** @param array<string, mixed> $state */
    public static function serialize(array $state): string
    {
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        self::restore($encoded);
        return $encoded;
    }

    /**
     * Null REST state means unknown, not stopped. Fault/pause phases do not
     * close a run. Only fresh Docked followed by Running starts a new history.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $scene
     * @return array<string, mixed>
     */
    public static function observe(
        array $state,
        array $scene,
        ?int $freshVehicleState,
        int $now
    ): array {
        $key = $scene['revision']['geometryKey'] ?? null;
        if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $now <= 0) {
            throw new InvalidArgumentException('Display track observation is invalid.');
        }
        if ($state['geometryKey'] !== $key) {
            $state = self::restore('{}');
            $state['geometryKey'] = $key;
        }
        if ($freshVehicleState === 1 && $state['phase'] === 'docked') {
            $state['working'] = RevisionBoundedTrackStore::initialState();
            $state['selectionTruncated'] = false;
        }
        if ($freshVehicleState === 1) {
            $state['phase'] = 'running';
        }

        $filtered = $scene;
        $filtered['path']['segments'] = [];
        $filtered['path']['selectionTruncated'] = false;
        foreach ($scene['path']['segments'] as $segment) {
            // Do not splice across removed points: that would invent an edge.
            $run = [];
            foreach ($segment['points'] as $point) {
                if (
                    ($point['vehicleStateCode'] ?? 0) === 2
                    || $point['receivedAt'] <= $state['after']
                ) {
                    self::append($filtered, $segment, $run);
                    $run = [];
                } else {
                    $run[] = $point;
                }
            }
            self::append($filtered, $segment, $run);
        }
        // Already Docked: do not let jitter or stale repeated input consume
        // the display budget. The unfiltered analytics path remains separate.
        if ($state['phase'] !== 'docked') {
            $state['selectionTruncated'] = $state['selectionTruncated']
                || $filtered['path']['selectionTruncated'];
            $state['working'] = RevisionBoundedTrackStore::ingestScene(
                $state['working'],
                $filtered
            );
            $newest = self::newestEdge($state['working']);
            if (
                $newest > $state['observedAt']
                && ($freshVehicleState === 1 || $state['observedAt'] === 0
                    || ($freshVehicleState === 2 && $state['phase'] === 'running'))
            ) {
                $state['display'] = $state['working'];
                $state['observedAt'] = $newest;
            }
        }
        if ($freshVehicleState === 2) {
            $state['phase'] = 'docked';
            $state['after'] = max($state['after'], $now);
        }
        self::serialize($state);
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function path(array $state, string $geometryKey): array
    {
        $matches = $state['geometryKey'] === $geometryKey;
        $store = $matches ? $state['display'] : RevisionBoundedTrackStore::initialState();
        $path = RevisionBoundedTrackStore::scenePath($store, $geometryKey);
        $path['displayHistory'] = [
            'observedAt' => $matches ? $state['observedAt'] : 0,
            'historical' => !$matches || $state['phase'] !== 'running'
                || $state['observedAt'] <= $state['after'],
            'bounded' => true,
            'truncated' => $matches && ($state['selectionTruncated']
                || $store['counters']['evictedPointCount'] > 0),
        ];
        return $path;
    }

    /**
     * @param array<string, mixed> $scene
     * @param array<string, mixed> $segment
     * @param list<array<string, mixed>> $points
     */
    private static function append(array &$scene, array $segment, array $points): void
    {
        if ($points === []) {
            return;
        }
        if (count($scene['path']['segments']) >= 64) {
            array_shift($scene['path']['segments']);
            $scene['path']['selectionTruncated'] = true;
        }
        $segment['points'] = $points;
        $scene['path']['segments'][] = $segment;
    }

    /** @param array<string, mixed> $store */
    private static function newestEdge(array $store): int
    {
        $newest = 0;
        foreach ($store['segments'] as $segment) {
            $previous = null;
            foreach ($segment['points'] as $point) {
                if (
                    $previous !== null
                    && $previous['vehicleStateCode'] !== 2
                    && $point['vehicleStateCode'] !== 2
                    && ($previous['localX'] !== $point['localX'] || $previous['localY'] !== $point['localY'])
                ) {
                    $newest = max($newest, $point['receivedAt']);
                }
                $previous = $point;
            }
        }
        return $newest;
    }
}
