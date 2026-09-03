<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;

final class LocalMapSvgRenderer
{
    private const MAX_ZONES = 32;
    private const MAX_OBSTACLES = 256;
    private const MAX_SEGMENTS = 64;
    private const MAX_POINTS = 8192;
    private const MAX_OUTPUT_BYTES = 1024 * 1024;
    private const LIGHT_COLORS = [
        ['fill' => '#dbeafe', 'stroke' => '#2563eb'],
        ['fill' => '#dcfce7', 'stroke' => '#16a34a'],
        ['fill' => '#fef3c7', 'stroke' => '#d97706'],
        ['fill' => '#fce7f3', 'stroke' => '#db2777'],
        ['fill' => '#e0e7ff', 'stroke' => '#4f46e5'],
        ['fill' => '#ccfbf1', 'stroke' => '#0f766e'],
    ];
    private const DARK_COLORS = [
        ['fill' => '#243a52', 'stroke' => '#78aee8'],
        ['fill' => '#24483a', 'stroke' => '#67c994'],
        ['fill' => '#4b3d24', 'stroke' => '#d8ad55'],
        ['fill' => '#4b2d40', 'stroke' => '#db83b0'],
        ['fill' => '#333554', 'stroke' => '#9b9fe5'],
        ['fill' => '#204742', 'stroke' => '#65c6ba'],
    ];

    /**
     * @param array<string, mixed> $scene
     * @param array<string, mixed> $options
     */
    public static function render(array $scene, array $options = []): string
    {
        if (
            ($scene['formatVersion'] ?? null) !== 1
            || ($scene['coordinateFrame'] ?? null)
                !== 'navimow-local-map-candidate'
            || !is_array($scene['viewport'] ?? null)
            || !is_array($scene['zones'] ?? null)
            || !array_is_list($scene['zones'])
            || count($scene['zones']) > self::MAX_ZONES
            || !is_array($scene['obstacles'] ?? null)
            || !array_is_list($scene['obstacles'])
            || count($scene['obstacles']) > self::MAX_OBSTACLES
            || !is_array($scene['path'] ?? null)
        ) {
            throw new InvalidArgumentException('Local map scene is invalid.');
        }
        $presentation = self::options($options);
        $viewport = self::viewport($scene['viewport']);
        $pointCount = 0;
        $zoneMarkup = [];
        $labelMarkup = [];
        foreach ($scene['zones'] as $index => $zone) {
            if (!is_array($zone)) {
                throw new InvalidArgumentException('Map zone is invalid.');
            }
            $ring = self::ring($zone['ring'] ?? null, $pointCount);
            $label = self::text($zone['label'] ?? null, 128);
            $colors = $presentation['theme'] === 'dark'
                ? self::DARK_COLORS
                : self::LIGHT_COLORS;
            $color = $colors[$index % count($colors)];
            $zoneMarkup[] = sprintf(
                '<polygon class="zone" data-zone-sequence="%d" points="%s" fill="%s" stroke="%s"/>',
                $index + 1,
                self::points($ring, $viewport),
                $color['fill'],
                $color['stroke']
            );
            if (
                !in_array(
                    $index + 1,
                    $presentation['hiddenZoneSequences'],
                    true
                )
            ) {
                $center = self::center($ring);
                $projected = self::project($center, $viewport);
                $suffix = self::progressSuffix($zone['statistics'] ?? null);
                $labelMarkup[] = sprintf(
                    '<text class="zone-label" x="%s" y="%s">%s%s</text>',
                    self::number($projected[0]),
                    self::number($projected[1]),
                    self::escape($label),
                    self::escape($suffix)
                );
            }
        }

        $obstacleMarkup = [];
        foreach ($scene['obstacles'] as $index => $obstacle) {
            if (!is_array($obstacle)) {
                throw new InvalidArgumentException(
                    'Map obstacle is invalid.'
                );
            }
            $ring = self::ring($obstacle['ring'] ?? null, $pointCount);
            $status = $obstacle['ownership']['status'] ?? null;
            if (!is_string($status)) {
                throw new InvalidArgumentException(
                    'Obstacle ownership is invalid.'
                );
            }
            $obstacleMarkup[] = sprintf(
                '<polygon class="obstacle obstacle-%s" data-obstacle-sequence="%d" points="%s"/>',
                self::escape($status),
                $index + 1,
                self::points($ring, $viewport)
            );
        }

        $pathMarkup = [];
        $diagnosticPointMarkup = [];
        $diagnosticPointSequence = 0;
        $markerRadius = max(
            0.7,
            max($viewport['width'], $viewport['height']) / 100.0
        );
        $segments = $scene['path']['segments'] ?? null;
        if (
            !is_array($segments)
            || !array_is_list($segments)
            || count($segments) > self::MAX_SEGMENTS
        ) {
            throw new InvalidArgumentException('Map path is invalid.');
        }
        $latest = null;
        $previousLatest = null;
        foreach ($segments as $index => $segment) {
            if (!is_array($segment)) {
                throw new InvalidArgumentException(
                    'Map path segment is invalid.'
                );
            }
            $values = $segment['points'] ?? null;
            if (!is_array($values) || !array_is_list($values)) {
                throw new InvalidArgumentException(
                    'Map path points are invalid.'
                );
            }
            $points = [];
            foreach ($values as $value) {
                if (!is_array($value)) {
                    throw new InvalidArgumentException(
                        'Map path point is invalid.'
                    );
                }
                $point = [
                    self::finite($value['localX'] ?? null),
                    self::finite($value['localY'] ?? null),
                ];
                $points[] = $point;
                if (
                    $latest !== null
                    && (
                        $point[0] !== $latest[0]
                        || $point[1] !== $latest[1]
                    )
                ) {
                    $previousLatest = $latest;
                }
                $latest = $point;
                ++$diagnosticPointSequence;
                $attribution = $value['attribution'] ?? null;
                if (!is_array($attribution)) {
                    throw new InvalidArgumentException(
                        'Map path attribution is invalid.'
                    );
                }
                $source = $attribution['source'] ?? null;
                if (!is_string($source)) {
                    throw new InvalidArgumentException(
                        'Map path attribution source is invalid.'
                    );
                }
                if (
                    in_array(
                        $source,
                        ['outside', 'ambiguous', 'unknown-task-zone'],
                        true
                    )
                ) {
                    $projectedPoint = self::project($latest, $viewport);
                    $diagnosticPointMarkup[] = sprintf(
                        '<circle class="path-point path-point-%s" data-point-sequence="%d" cx="%s" cy="%s" r="%s"><title>%s</title></circle>',
                        self::escape($source),
                        $diagnosticPointSequence,
                        self::number($projectedPoint[0]),
                        self::number($projectedPoint[1]),
                        self::number($markerRadius),
                        self::escape(self::diagnosticTitle($source))
                    );
                }
                ++$pointCount;
                if ($pointCount > self::MAX_POINTS) {
                    throw new InvalidArgumentException(
                        'Rendered point count exceeds the limit.'
                    );
                }
            }
            if ($points === []) {
                continue;
            }
            $pathMarkup[] = sprintf(
                '<polyline class="path" data-segment-sequence="%d" points="%s"/>',
                $index + 1,
                self::points($points, $viewport)
            );
        }

        $stationMarkup = '';
        if (($scene['station'] ?? null) !== null) {
            if (!is_array($scene['station'])) {
                throw new InvalidArgumentException('Map station is invalid.');
            }
            $station = self::project([
                self::finite($scene['station']['x'] ?? null),
                self::finite($scene['station']['y'] ?? null),
            ], $viewport);
            $direction = $scene['station']['direction'] ?? null;
            $rotation = $direction === null
                ? 0.0
                : -rad2deg(self::finite($direction));
            $stationState = $presentation['stationState'];
            $stationMarkup = sprintf(
                '<g class="station station-%s" transform="translate(%s %s) rotate(%s)"><title>%s</title>%s</g>',
                self::escape($stationState),
                self::number($station[0]),
                self::number($station[1]),
                self::number($rotation),
                self::escape(self::stationTitle($stationState)),
                self::stationGlyph()
            );
        }

        $mowerMarkup = '';
        if ($latest !== null && $presentation['showMower']) {
            $mower = self::project($latest, $viewport);
            $mowerState = $presentation['mowerState'];
            $mowerRotation = self::mowerRotation(
                $previousLatest,
                $latest,
                $viewport
            );
            $mowerMarkup = sprintf(
                '<g class="mower mower-%s" data-heading-degrees="%s" transform="translate(%s %s) rotate(%s)"><title>%s</title>%s</g>',
                self::escape($mowerState),
                self::number($mowerRotation),
                self::number($mower[0]),
                self::number($mower[1]),
                self::number($mowerRotation),
                self::escape(self::mowerTitle($mowerState)),
                self::mowerGlyph()
            );
        }

        $legendMarkup = self::legendMarkup($viewport, $presentation);

        $viewBox = implode(' ', array_map(
            [self::class, 'number'],
            [
                $viewport['minimumX'],
                $viewport['minimumY'],
                $viewport['width'],
                $viewport['height'],
            ]
        ));
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Local mower map" data-theme="%s" viewBox="%s" width="100%%" height="100%%" style="display:block" preserveAspectRatio="xMidYMid meet"><style>%s</style><rect class="background" x="%s" y="%s" width="%s" height="%s"/>%s%s%s%s%s%s%s</svg>',
            self::escape($presentation['theme']),
            $viewBox,
            self::styles($viewport, $presentation['theme']),
            self::number($viewport['minimumX']),
            self::number($viewport['minimumY']),
            self::number($viewport['width']),
            self::number($viewport['height']),
            implode('', $zoneMarkup),
            implode('', $obstacleMarkup),
            implode('', $pathMarkup),
            implode('', $diagnosticPointMarkup),
            $stationMarkup . $mowerMarkup,
            implode('', $labelMarkup),
            $legendMarkup
        );
        if (strlen($svg) > self::MAX_OUTPUT_BYTES) {
            throw new InvalidArgumentException(
                'Rendered map exceeds the output limit.'
            );
        }

        return $svg;
    }

    /** @return array<string, float> */
    private static function viewport(array $value): array
    {
        $result = [];
        foreach (
            [
                'minimumX',
                'minimumY',
                'maximumX',
                'maximumY',
                'width',
                'height',
            ] as $field
        ) {
            $result[$field] = self::finite($value[$field] ?? null);
        }
        if ($result['width'] <= 0.0 || $result['height'] <= 0.0) {
            throw new InvalidArgumentException('Map viewport is invalid.');
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @param int $pointCount
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function ring(mixed $value, int &$pointCount): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || count($value) < 4
            || count($value) > 1025
        ) {
            throw new InvalidArgumentException('Rendered ring is invalid.');
        }
        $ring = [];
        foreach ($value as $point) {
            if (!is_array($point) || count($point) !== 2) {
                throw new InvalidArgumentException(
                    'Rendered ring point is invalid.'
                );
            }
            $ring[] = [
                self::finite($point[0] ?? null),
                self::finite($point[1] ?? null),
            ];
            ++$pointCount;
            if ($pointCount > self::MAX_POINTS) {
                throw new InvalidArgumentException(
                    'Rendered point count exceeds the limit.'
                );
            }
        }

        return $ring;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     * @param array<string, float> $viewport
     */
    private static function points(array $points, array $viewport): string
    {
        return implode(' ', array_map(
            static function (array $point) use ($viewport): string {
                $projected = self::project($point, $viewport);

                return self::number($projected[0])
                    . ',' . self::number($projected[1]);
            },
            $points
        ));
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param array<string, float> $viewport
     *
     * @return array{0: float, 1: float}
     */
    private static function project(array $point, array $viewport): array
    {
        return [
            $point[0],
            $viewport['minimumY'] + $viewport['maximumY'] - $point[1],
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $ring
     *
     * @return array{0: float, 1: float}
     */
    private static function center(array $ring): array
    {
        $points = array_slice($ring, 0, -1);

        return [
            array_sum(array_column($points, 0)) / count($points),
            array_sum(array_column($points, 1)) / count($points),
        ];
    }

    private static function progressSuffix(mixed $statistics): string
    {
        if (
            !is_array($statistics)
            || !is_array($statistics['latestPass'] ?? null)
            || !is_float(
                $statistics['latestPass']['passProgressPercent'] ?? null
            )
        ) {
            return '';
        }

        return sprintf(
            ' · %.1f%%',
            $statistics['latestPass']['passProgressPercent']
        );
    }

    /**
     * @param array<string, float> $viewport
     * @param array{hiddenZoneSequences: list<int>, stationState: string, mowerState: string, showMower: bool, theme: string} $presentation
     */
    private static function legendMarkup(
        array $viewport,
        array $presentation
    ): string {
        $span = max($viewport['width'], $viewport['height']);
        $font = max(1.35, min(1.9, $span / 58.0));
        $row = $font * 1.55;
        $padding = $font * 0.8;
        $width = min(
            $viewport['width'] * 0.52,
            max(26.0, $font * 18.0)
        );
        $height = $padding * 2.0 + $row * 10.0;
        $inset = max(0.8, $span / 110.0);
        $x = $viewport['maximumX'] - $inset - $width;
        $y = $viewport['maximumY'] - $inset - $height;
        $iconX = $padding + $font * 0.75;
        $labelX = $padding + $font * 2.1;
        $secondColumnX = $width * 0.52;
        $rowY = static fn (int $index): float =>
            $padding + $row * ($index + 0.62);
        $stationState = self::escape($presentation['stationState']);
        $mowerState = self::escape($presentation['mowerState']);
        $text = static fn (float $yValue, string $label): string => sprintf(
            '<text class="legend-label" x="%s" y="%s">%s</text>',
            self::number($labelX),
            self::number($yValue),
            self::escape($label)
        );

        $stateRow = static function (
            float $xValue,
            float $yValue,
            string $class,
            string $label
        ) use ($font): string {
            return sprintf(
                '<circle class="legend-state %s" cx="%s" cy="%s" r="%s"/><text class="legend-state-label" x="%s" y="%s">%s</text>',
                self::escape($class),
                self::number($xValue),
                self::number($yValue),
                self::number($font * 0.28),
                self::number($xValue + $font * 0.62),
                self::number($yValue),
                self::escape($label)
            );
        };

        $markup = sprintf(
            '<g class="legend" transform="translate(%s %s)"><title>Symbollegende</title><rect class="legend-background" width="%s" height="%s" rx="%s"/>',
            self::number($x),
            self::number($y),
            self::number($width),
            self::number($height),
            self::number($font * 0.38)
        );
        $markup .= sprintf(
            '<g class="legend-station legend-station-%s" transform="translate(%s %s) scale(.48)">%s</g>%s',
            $stationState,
            self::number($iconX),
            self::number($rowY(0)),
            self::stationGlyph(),
            $text($rowY(0), 'Station')
        );
        $markup .= sprintf(
            '<g class="legend-mower legend-mower-%s" transform="translate(%s %s) scale(.48)">%s</g><text class="legend-label" x="%s" y="%s">Mäher</text>',
            $mowerState,
            self::number($secondColumnX + $font * 0.75),
            self::number($rowY(0)),
            self::mowerGlyph(),
            self::number($secondColumnX + $font * 2.1),
            self::number($rowY(0))
        );
        $markup .= $stateRow(
            $padding,
            $rowY(1),
            'state-docked',
            'Angedockt'
        );
        $markup .= $stateRow(
            $padding,
            $rowY(2),
            'state-returning',
            'Rückfahrt'
        );
        $markup .= $stateRow(
            $padding,
            $rowY(3),
            'state-away',
            'Unterwegs'
        );
        $markup .= $stateRow(
            $padding,
            $rowY(4),
            'state-unknown',
            'Unbekannt'
        );
        $markup .= $stateRow(
            $secondColumnX,
            $rowY(1),
            'state-active',
            'Aktiv'
        );
        $markup .= $stateRow(
            $secondColumnX,
            $rowY(2),
            'state-paused',
            'Pause/Bereit'
        );
        $markup .= $stateRow(
            $secondColumnX,
            $rowY(3),
            'state-returning',
            'Rückfahrt'
        );
        $markup .= $stateRow(
            $secondColumnX,
            $rowY(4),
            'state-attention',
            'Störung'
        );
        $markup .= $stateRow(
            $secondColumnX,
            $rowY(5),
            'state-offline',
            'Offline'
        );
        $markup .= $stateRow(
            $secondColumnX,
            $rowY(6),
            'state-unknown',
            'Unbekannt'
        );
        $markup .= sprintf(
            '<line class="legend-path" x1="%s" x2="%s" y1="%s" y2="%s"/>%s',
            self::number($iconX),
            self::number($iconX + $font * 1.5),
            self::number($rowY(7)),
            self::number($rowY(7)),
            $text($rowY(7), 'Fahrspur')
        );
        $markup .= sprintf(
            '<rect class="legend-obstacle" x="%s" y="%s" width="%s" height="%s" rx=".25"/>%s',
            self::number($iconX - $font * 0.75),
            self::number($rowY(8) - $font * 0.45),
            self::number($font * 1.5),
            self::number($font * 0.9),
            $text($rowY(8), 'Sperrbereich')
        );
        $markup .= sprintf(
            '<g class="legend-points" transform="translate(%s %s)"><circle class="legend-point-outside" cx="-.9" r=".38"><title>Außerhalb der Kartenzone</title></circle><circle class="legend-point-ambiguous" r=".38"><title>Uneindeutige Zonenzuordnung</title></circle><circle class="legend-point-unknown" cx=".9" r=".38"><title>Unbekannte Aufgabenzone</title></circle></g>%s</g>',
            self::number($iconX),
            self::number($rowY(9)),
            $text($rowY(9), 'Zuordnung prüfen')
        );

        return $markup;
    }

    private static function stationGlyph(): string
    {
        return '<rect class="station-base" x="-2.6" y="-1.7" width="5.2" height="3.4" rx=".45"/><path class="station-guide" d="M-1.75-1.05v2.1M1.75-1.05v2.1"/><path class="station-occupancy" d="M-1.35-.85H.45L1.35 0 .45.85H-1.35Z"/>';
    }

    private static function mowerGlyph(): string
    {
        return '<path class="mower-body" d="M-1.65-1.25H.65L1.9 0 .65 1.25H-1.65Z"/><path class="mower-direction" d="M-.75 0H.75M.2-.55L.75 0 .2.55"/>';
    }

    /**
     * @param array{0: float, 1: float}|null $previous
     * @param array{0: float, 1: float} $latest
     * @param array<string, float> $viewport
     */
    private static function mowerRotation(
        ?array $previous,
        array $latest,
        array $viewport
    ): float {
        if ($previous === null) {
            return 0.0;
        }
        $from = self::project($previous, $viewport);
        $to = self::project($latest, $viewport);
        $deltaX = $to[0] - $from[0];
        $deltaY = $to[1] - $from[1];
        if (abs($deltaX) < 0.000001 && abs($deltaY) < 0.000001) {
            return 0.0;
        }

        return rad2deg(atan2($deltaY, $deltaX));
    }

    /** @param array<string, float> $viewport */
    private static function styles(array $viewport, string $theme): string
    {
        $span = max($viewport['width'], $viewport['height']);
        $stroke = max(0.18, $span / 280.0);
        $path = max(0.28, $span / 180.0);
        $font = max(1.6, min(2.4, $span / 50.0));

        $palette = $theme === 'dark'
            ? [
                'background' => '#171b1f',
                'obstacleFill' => '#c1c9ce',
                'obstacleStroke' => '#8e9aa2',
                'path' => '#f2f5f4',
                'pointStroke' => '#171b1f',
                'label' => '#f2f5f4',
                'labelStroke' => '#171b1f',
                'legendBackground' => '#20262b',
                'legendBorder' => '#59656d',
                'legendText' => '#e8edef',
            ]
            : [
                'background' => '#f8fafc',
                'obstacleFill' => '#94a3b8',
                'obstacleStroke' => '#64748b',
                'path' => '#111827',
                'pointStroke' => '#ffffff',
                'label' => '#111827',
                'labelStroke' => '#ffffff',
                'legendBackground' => '#ffffff',
                'legendBorder' => '#cbd5e1',
                'legendText' => '#1f2937',
            ];

        $legendFont = self::number(max(1.35, min(1.9, $span / 58.0)));
        $strokeWidth = self::number($stroke);
        $pathWidth = self::number($path);
        $fontSize = self::number($font);

        return implode('', [
            sprintf(
                'html,body{margin:0;padding:0;background:%s;overflow:hidden}svg{display:block;width:100%%;height:100%%;background:%s}',
                $palette['background'],
                $palette['background']
            ),
            sprintf('.background{fill:%s}', $palette['background']),
            sprintf(
                '.zone{fill-opacity:.62;stroke-width:%s;vector-effect:non-scaling-stroke}',
                $strokeWidth
            ),
            sprintf(
                '.obstacle{fill:%s;fill-opacity:.06;stroke:%s;stroke-width:%s;stroke-dasharray:1.1 .8;vector-effect:non-scaling-stroke}',
                $palette['obstacleFill'],
                $palette['obstacleStroke'],
                $strokeWidth
            ),
            '.obstacle-ambiguous{fill:#ff4d5a;fill-opacity:.08;stroke:#ff4d5a}',
            sprintf(
                '.path{fill:none;stroke:%s;stroke-width:%s;stroke-linecap:round;stroke-linejoin:round;vector-effect:non-scaling-stroke}',
                $palette['path'],
                $pathWidth
            ),
            sprintf(
                '.path-point{stroke:%s;stroke-width:%s;vector-effect:non-scaling-stroke}',
                $palette['pointStroke'],
                $strokeWidth
            ),
            '.path-point-outside{fill:#ff9f1c}.path-point-ambiguous{fill:#ff4d5a}.path-point-unknown-task-zone{fill:#d946ef}',
            sprintf(
                '.station .station-base,.legend-station .station-base,.station .station-guide,.legend-station .station-guide,.station .station-occupancy,.legend-station .station-occupancy{stroke-width:%s;vector-effect:non-scaling-stroke}',
                $strokeWidth
            ),
            '.station .station-guide,.legend-station .station-guide{fill:none;stroke-linecap:round}.station .station-occupancy,.legend-station .station-occupancy{display:none}',
            '.station-docked .station-base,.legend-station-docked .station-base{fill:#123c2f;stroke:#39d98a}.station-docked .station-guide,.legend-station-docked .station-guide{stroke:#8cf0bd}.station-docked .station-occupancy,.legend-station-docked .station-occupancy{display:inline;fill:#39d98a;stroke:#06281d}',
            '.station-docking .station-base,.legend-station-docking .station-base{fill:#7a3f00;stroke:#ff9f1c}.station-docking .station-guide,.legend-station-docking .station-guide{stroke:#ffe0a3}',
            '.station-undocked .station-base,.legend-station-undocked .station-base{fill:#303a42;stroke:#a7b1b8}.station-undocked .station-guide,.legend-station-undocked .station-guide{stroke:#eef2f5}',
            '.station-unknown .station-base,.legend-station-unknown .station-base{fill:#701a75;stroke:#f0abfc}.station-unknown .station-guide,.legend-station-unknown .station-guide{stroke:#ffffff}',
            sprintf(
                '.mower-body,.legend-mower .mower-body,.mower-direction,.legend-mower .mower-direction{stroke-width:%s;vector-effect:non-scaling-stroke}',
                $strokeWidth
            ),
            '.mower-direction,.legend-mower .mower-direction{fill:none;stroke:#ffffff;stroke-linecap:round;stroke-linejoin:round}',
            '.mower-active .mower-body,.legend-mower-active .mower-body,.state-active{fill:#39d98a;stroke:#0b3b29}',
            '.mower-paused .mower-body,.legend-mower-paused .mower-body,.state-paused{fill:#ffd166;stroke:#6e4b0a}',
            '.mower-returning .mower-body,.legend-mower-returning .mower-body,.state-returning{fill:#ff9f1c;stroke:#70420a}',
            '.mower-attention .mower-body,.legend-mower-attention .mower-body,.state-attention{fill:#ff4d5a;stroke:#6b1f1d}',
            '.mower-offline .mower-body,.legend-mower-offline .mower-body,.state-offline,.state-away{fill:#87949e;stroke:#29333a}',
            '.mower-unknown .mower-body,.legend-mower-unknown .mower-body,.state-unknown{fill:#d946ef;stroke:#701a75}',
            '.mower-docked .mower-body,.legend-mower-docked .mower-body,.state-docked{fill:#39d98a;stroke:#0b3b29}',
            sprintf(
                '.zone-label{font-family:system-ui,sans-serif;font-size:%spx;text-anchor:middle;dominant-baseline:middle;fill:%s;paint-order:stroke;stroke:%s;stroke-width:.35;stroke-linejoin:round;letter-spacing:0}',
                $fontSize,
                $palette['label'],
                $palette['labelStroke']
            ),
            '.legend{pointer-events:none}',
            sprintf(
                '.legend-background{fill:%s;fill-opacity:.9;stroke:%s;stroke-width:%s;vector-effect:non-scaling-stroke}',
                $palette['legendBackground'],
                $palette['legendBorder'],
                $strokeWidth
            ),
            sprintf(
                '.legend-label{font-family:system-ui,sans-serif;font-size:%spx;font-weight:600;dominant-baseline:middle;fill:%s;letter-spacing:0}.legend-state-label{font-family:system-ui,sans-serif;font-size:%spx;dominant-baseline:middle;fill:%s;letter-spacing:0}',
                $legendFont,
                $palette['legendText'],
                $legendFont,
                $palette['legendText']
            ),
            sprintf(
                '.legend-state{stroke-width:%s;vector-effect:non-scaling-stroke}.legend-path{stroke:%s;stroke-width:%s;stroke-linecap:round;vector-effect:non-scaling-stroke}',
                $strokeWidth,
                $palette['path'],
                $pathWidth
            ),
            sprintf(
                '.legend-obstacle{fill:%s;fill-opacity:.06;stroke:%s;stroke-width:%s;stroke-dasharray:1.1 .8;vector-effect:non-scaling-stroke}.legend-points circle{stroke:%s;stroke-width:%s;vector-effect:non-scaling-stroke}',
                $palette['obstacleFill'],
                $palette['obstacleStroke'],
                $strokeWidth,
                $palette['pointStroke'],
                $strokeWidth
            ),
            '.legend-point-outside{fill:#ff9f1c}.legend-point-ambiguous{fill:#ff4d5a}.legend-point-unknown{fill:#d946ef}',
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{hiddenZoneSequences: list<int>, stationState: string, mowerState: string, showMower: bool, theme: string}
     */
    private static function options(array $options): array
    {
        $hidden = $options['hiddenZoneSequences'] ?? [];
        $stationState = $options['stationState'] ?? 'unknown';
        $mowerState = $options['mowerState'] ?? 'unknown';
        $showMower = $options['showMower'] ?? true;
        $theme = $options['theme'] ?? 'dark';
        if (
            !is_array($hidden)
            || !array_is_list($hidden)
            || count($hidden) > self::MAX_ZONES
            || !is_string($stationState)
            || !is_string($mowerState)
            || !is_bool($showMower)
            || !is_string($theme)
            || !in_array($theme, ['dark', 'light'], true)
            || !in_array(
                $stationState,
                ['docked', 'docking', 'undocked', 'unknown'],
                true
            )
            || !in_array(
                $mowerState,
                [
                    'active',
                    'paused',
                    'returning',
                    'attention',
                    'offline',
                    'docked',
                    'unknown',
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Map presentation options are invalid.'
            );
        }
        $seen = [];
        foreach ($hidden as $sequence) {
            if (
                !is_int($sequence)
                || $sequence < 1
                || $sequence > self::MAX_ZONES
                || isset($seen[$sequence])
            ) {
                throw new InvalidArgumentException(
                    'Hidden zone sequence is invalid.'
                );
            }
            $seen[$sequence] = true;
        }

        return [
            'hiddenZoneSequences' => $hidden,
            'stationState' => $stationState,
            'mowerState' => $mowerState,
            'showMower' => $showMower,
            'theme' => $theme,
        ];
    }

    private static function stationTitle(string $state): string
    {
        return match ($state) {
            'docked' => 'Mower docked',
            'docking' => 'Mower returning to station',
            'undocked' => 'Mower away from station',
            'unknown' => 'Dock state unknown',
            default => throw new InvalidArgumentException(
                'Station state is invalid.'
            ),
        };
    }

    private static function mowerTitle(string $state): string
    {
        return match ($state) {
            'active' => 'Mower active',
            'paused' => 'Mower paused or ready',
            'returning' => 'Mower returning to station',
            'attention' => 'Mower needs attention',
            'offline' => 'Mower offline',
            'docked' => 'Mower docked',
            'unknown' => 'Mower state unknown',
            default => throw new InvalidArgumentException(
                'Mower state is invalid.'
            ),
        };
    }

    private static function diagnosticTitle(string $source): string
    {
        return match ($source) {
            'outside' => 'Outside mapped zone',
            'ambiguous' => 'Ambiguous zone attribution',
            'unknown-task-zone' => 'Unknown task zone',
            default => throw new InvalidArgumentException(
                'Map diagnostic source is invalid.'
            ),
        };
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Map label is invalid.');
        }

        return $value;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1,
            'UTF-8'
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }

    private static function finite(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Map number is invalid.');
        }
        $number = (float) $value;
        if (!is_finite($number) || abs($number) > 1000 * 1000) {
            throw new InvalidArgumentException(
                'Map number exceeds the limit.'
            );
        }

        return $number;
    }
}
