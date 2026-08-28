<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;
use JsonException;

final class MapGeometryReducer
{
    private const MAX_DETAIL_BYTES = 4 * 1024 * 1024;
    private const MAX_ZONES = 32;
    private const MAX_ELEMENTS_PER_ZONE = 128;
    private const MAX_AREAS = 256;
    private const MAX_POINTS_PER_RING = 1024;
    private const MAX_POINTS_TOTAL = 8192;
    private const MAX_COORDINATE_ABS = 1000 * 1000;
    private const MAX_NAME_BYTES = 128;
    private const EPSILON = 0.000000001;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function reduce(array $payload): array
    {
        $geometry = self::decodeGeometry($payload['map_detail'] ?? null);
        $subMaps = self::boundedList(
            $geometry['sub_maps'] ?? null,
            self::MAX_ZONES,
            'sub_maps'
        );

        $pointCount = 0;
        $zones = [];
        $station = null;
        foreach ($subMaps as $zoneIndex => $subMap) {
            if (!is_array($subMap)) {
                throw new InvalidArgumentException('Map zone must be an object.');
            }

            $zoneId = self::boundedInteger(
                $subMap['id'] ?? null,
                0,
                65535,
                'zone id'
            );
            $elements = self::boundedList(
                $subMap['elements'] ?? [],
                self::MAX_ELEMENTS_PER_ZONE,
                'zone elements'
            );
            $boundary = null;
            $flags = [];
            foreach ($elements as $element) {
                if (!is_array($element)) {
                    throw new InvalidArgumentException(
                        'Map element must be an object.'
                    );
                }

                $type = $element['type'] ?? null;
                if ($type === 'BOUNDARY' && $boundary === null) {
                    [$boundary, $flags] = self::normalizeRing(
                        $element['points'] ?? null,
                        $pointCount,
                        sprintf('zone %d boundary', $zoneIndex)
                    );
                }
                if ($type === 'CHARGING_PILE' && $station === null) {
                    $station = self::normalizeStation($element);
                }
            }

            if ($boundary === null) {
                throw new InvalidArgumentException(
                    sprintf('Zone %d has no valid boundary.', $zoneIndex)
                );
            }

            $zones[] = [
                'id' => $zoneId,
                'name' => self::boundedName(
                    $subMap['name'] ?? sprintf('Zone %d', $zoneId)
                ),
                'reportedArea' => self::optionalPositiveNumber(
                    $subMap['area'] ?? null,
                    'zone area'
                ),
                'calculatedArea' => self::ringArea($boundary),
                'ring' => $boundary,
                'boundaryFlags' => $flags,
                'holes' => [],
            ];
        }

        return [
            'formatVersion' => 1,
            'authority' => 'decoded-private-map-payload',
            'coordinateFrame' => 'navimow-local-map',
            'reportedArea' => self::optionalPositiveNumber(
                $geometry['area'] ?? null,
                'map area'
            ),
            'zones' => $zones,
            'obstacles' => self::normalizeAreaList(
                $geometry['obstacles'] ?? [],
                $pointCount,
                'obstacles'
            ),
            'visionOffAreas' => self::normalizeAreaList(
                $geometry['vision_off_areas'] ?? [],
                $pointCount,
                'vision_off_areas'
            ),
            'station' => $station,
            'pointCount' => $pointCount,
            'geometryContract' => [
                'ringsClosed' => true,
                'selfIntersectionsRejected' => true,
                'holesSupported' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeGeometry(mixed $detail): array
    {
        if (is_string($detail)) {
            if ($detail === '' || strlen($detail) > self::MAX_DETAIL_BYTES) {
                throw new InvalidArgumentException(
                    'Map detail string is empty or exceeds the byte limit.'
                );
            }
            try {
                $detail = json_decode(
                    $detail,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    'Map detail is not valid JSON.',
                    0,
                    $exception
                );
            }
        }

        if (!is_array($detail) || array_is_list($detail)) {
            throw new InvalidArgumentException(
                'Map detail must be a JSON object.'
            );
        }

        return $detail;
    }

    /**
     * @return list<mixed>
     */
    private static function boundedList(
        mixed $value,
        int $maximum,
        string $label
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(
                sprintf('%s must be a list.', $label)
            );
        }
        if (count($value) > $maximum) {
            throw new InvalidArgumentException(
                sprintf('%s exceeds its item limit.', $label)
            );
        }

        return $value;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeAreaList(
        mixed $values,
        int &$pointCount,
        string $label
    ): array {
        $values = self::boundedList($values, self::MAX_AREAS, $label);
        $result = [];
        foreach ($values as $index => $value) {
            if (!is_array($value)) {
                throw new InvalidArgumentException(
                    sprintf('%s item must be an object.', $label)
                );
            }
            [$ring] = self::normalizeRing(
                $value['points'] ?? null,
                $pointCount,
                sprintf('%s %d', $label, $index)
            );
            $result[] = [
                'ring' => $ring,
                'calculatedArea' => self::ringArea($ring),
            ];
        }

        return $result;
    }

    /**
     * @return array{0: list<array{0: float, 1: float}>, 1: list<int|null>}
     */
    private static function normalizeRing(
        mixed $points,
        int &$pointCount,
        string $label
    ): array {
        $points = self::boundedList(
            $points,
            self::MAX_POINTS_PER_RING,
            $label
        );
        $ring = [];
        $flags = [];
        foreach ($points as $point) {
            if (!is_array($point) || count($point) < 2) {
                throw new InvalidArgumentException(
                    sprintf('%s contains an invalid point.', $label)
                );
            }
            $normalized = [
                self::coordinate($point[0] ?? null, $label),
                self::coordinate($point[1] ?? null, $label),
            ];
            if ($ring !== [] && self::samePoint($ring[array_key_last($ring)], $normalized)) {
                continue;
            }
            $ring[] = $normalized;
            $flags[] = self::optionalInteger($point[2] ?? null, $label);
        }

        if (count($ring) >= 2 && self::samePoint($ring[0], $ring[array_key_last($ring)])) {
            array_pop($ring);
            array_pop($flags);
        }
        if (count($ring) < 3) {
            throw new InvalidArgumentException(
                sprintf('%s requires three distinct points.', $label)
            );
        }

        $pointCount += count($ring);
        if ($pointCount > self::MAX_POINTS_TOTAL) {
            throw new InvalidArgumentException(
                'Map geometry exceeds the total point limit.'
            );
        }

        $ring[] = $ring[0];
        if (!self::isSimpleRing($ring)) {
            throw new InvalidArgumentException(
                sprintf('%s is self-intersecting.', $label)
            );
        }
        if (self::ringArea($ring) <= self::EPSILON) {
            throw new InvalidArgumentException(
                sprintf('%s has no measurable area.', $label)
            );
        }

        return [$ring, $flags];
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array{x: float, y: float, direction: float|null}
     */
    private static function normalizeStation(array $element): array
    {
        $position = $element['position'] ?? null;
        if (!is_array($position) || count($position) < 2) {
            throw new InvalidArgumentException(
                'Charging station position is invalid.'
            );
        }

        return [
            'x' => self::coordinate($position[0] ?? null, 'station'),
            'y' => self::coordinate($position[1] ?? null, 'station'),
            'direction' => self::optionalFiniteNumber(
                $element['direction'] ?? null,
                'station direction'
            ),
        ];
    }

    private static function boundedInteger(
        mixed $value,
        int $minimum,
        int $maximum,
        string $label
    ): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(
                sprintf('%s is outside the supported range.', $label)
            );
        }

        return $value;
    }

    private static function optionalInteger(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new InvalidArgumentException(
                sprintf('%s boundary flag must be an integer.', $label)
            );
        }

        return $value;
    }

    private static function boundedName(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_NAME_BYTES) {
            throw new InvalidArgumentException('Zone name is invalid.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                'Zone name contains control characters.'
            );
        }

        return $value;
    }

    private static function coordinate(mixed $value, string $label): float
    {
        $number = self::finiteNumber($value, $label);
        if (abs($number) > self::MAX_COORDINATE_ABS) {
            throw new InvalidArgumentException(
                sprintf('%s coordinate exceeds the supported range.', $label)
            );
        }

        return $number;
    }

    private static function optionalPositiveNumber(
        mixed $value,
        string $label
    ): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        $number = self::finiteNumber($value, $label);
        if ($number <= 0) {
            throw new InvalidArgumentException(
                sprintf('%s must be positive.', $label)
            );
        }

        return $number;
    }

    private static function optionalFiniteNumber(
        mixed $value,
        string $label
    ): ?float {
        return $value === null || $value === ''
            ? null
            : self::finiteNumber($value, $label);
    }

    private static function finiteNumber(mixed $value, string $label): float
    {
        if (!(is_int($value) || is_float($value) || (is_string($value) && $value !== ''))) {
            throw new InvalidArgumentException(
                sprintf('%s must be numeric.', $label)
            );
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new InvalidArgumentException(
                sprintf('%s must be finite.', $label)
            );
        }

        return $number;
    }

    /**
     * @param array{0: float, 1: float} $left
     * @param array{0: float, 1: float} $right
     */
    private static function samePoint(array $left, array $right): bool
    {
        return abs($left[0] - $right[0]) <= self::EPSILON
            && abs($left[1] - $right[1]) <= self::EPSILON;
    }

    /**
     * @param list<array{0: float, 1: float}> $ring
     */
    private static function ringArea(array $ring): float
    {
        $twiceArea = 0.0;
        for ($index = 0, $last = count($ring) - 1; $index < $last; ++$index) {
            $twiceArea += $ring[$index][0] * $ring[$index + 1][1]
                - $ring[$index + 1][0] * $ring[$index][1];
        }

        return abs($twiceArea) / 2.0;
    }

    /**
     * @param list<array{0: float, 1: float}> $ring
     */
    private static function isSimpleRing(array $ring): bool
    {
        $edgeCount = count($ring) - 1;
        for ($left = 0; $left < $edgeCount; ++$left) {
            for ($right = $left + 1; $right < $edgeCount; ++$right) {
                if ($right === $left + 1 || ($left === 0 && $right === $edgeCount - 1)) {
                    continue;
                }
                if (
                    self::segmentsIntersect(
                        $ring[$left],
                        $ring[$left + 1],
                        $ring[$right],
                        $ring[$right + 1]
                    )
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     * @param array{0: float, 1: float} $d
     */
    private static function segmentsIntersect(
        array $a,
        array $b,
        array $c,
        array $d
    ): bool {
        $o1 = self::orientation($a, $b, $c);
        $o2 = self::orientation($a, $b, $d);
        $o3 = self::orientation($c, $d, $a);
        $o4 = self::orientation($c, $d, $b);

        if ($o1 * $o2 < 0 && $o3 * $o4 < 0) {
            return true;
        }

        return ($o1 === 0 && self::onSegment($a, $c, $b))
            || ($o2 === 0 && self::onSegment($a, $d, $b))
            || ($o3 === 0 && self::onSegment($c, $a, $d))
            || ($o4 === 0 && self::onSegment($c, $b, $d));
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     */
    private static function orientation(array $a, array $b, array $c): int
    {
        $cross = ($b[0] - $a[0]) * ($c[1] - $a[1])
            - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        if (abs($cross) <= self::EPSILON) {
            return 0;
        }

        return $cross > 0 ? 1 : -1;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $point
     * @param array{0: float, 1: float} $b
     */
    private static function onSegment(array $a, array $point, array $b): bool
    {
        return $point[0] >= min($a[0], $b[0]) - self::EPSILON
            && $point[0] <= max($a[0], $b[0]) + self::EPSILON
            && $point[1] >= min($a[1], $b[1]) - self::EPSILON
            && $point[1] <= max($a[1], $b[1]) + self::EPSILON;
    }
}
