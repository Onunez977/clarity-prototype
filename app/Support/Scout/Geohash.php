<?php

declare(strict_types=1);

namespace App\Support\Scout;

use App\Http\Controllers\Api\ClarityScoutMapController;

/**
 * Geohash encode/decode + grid helpers shared by the scanner map surfaces.
 *
 * The decode/bounds logic was lifted verbatim from
 * {@see ClarityScoutMapController} so the heatmap
 * GeoJSON builder and the zone-coverage builder share one implementation.
 */
final class Geohash
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    /**
     * Degree dimensions of a single cell at the given precision. Geohash
     * interleaves longitude first, so odd total-bit counts give longitude one
     * extra bit (wider-than-tall cells at even precisions like 6).
     *
     * @return array{lat: float, lng: float}
     */
    public static function cellSize(int $precision): array
    {
        $bits = 5 * $precision;
        $lngBits = (int) ceil($bits / 2);
        $latBits = (int) floor($bits / 2);

        return [
            'lat' => 180.0 / (2 ** $latBits),
            'lng' => 360.0 / (2 ** $lngBits),
        ];
    }

    public static function encode(float $lng, float $lat, int $precision): string
    {
        $minLat = -90.0;
        $maxLat = 90.0;
        $minLng = -180.0;
        $maxLng = 180.0;

        $hash = '';
        $isLng = true;
        $bit = 0;
        $charIndex = 0;

        while (strlen($hash) < $precision) {
            if ($isLng) {
                $mid = ($minLng + $maxLng) / 2;
                if ($lng >= $mid) {
                    $charIndex = ($charIndex << 1) + 1;
                    $minLng = $mid;
                } else {
                    $charIndex <<= 1;
                    $maxLng = $mid;
                }
            } else {
                $mid = ($minLat + $maxLat) / 2;
                if ($lat >= $mid) {
                    $charIndex = ($charIndex << 1) + 1;
                    $minLat = $mid;
                } else {
                    $charIndex <<= 1;
                    $maxLat = $mid;
                }
            }

            $isLng = ! $isLng;

            if (++$bit === 5) {
                $hash .= self::BASE32[$charIndex];
                $bit = 0;
                $charIndex = 0;
            }
        }

        return $hash;
    }

    /**
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null
     */
    public static function decodeBounds(string $geohash): ?array
    {
        $minLat = -90.0;
        $maxLat = 90.0;
        $minLng = -180.0;
        $maxLng = 180.0;

        $isLng = true;

        for ($i = 0, $len = strlen($geohash); $i < $len; $i++) {
            $charIndex = strpos(self::BASE32, $geohash[$i]);

            if ($charIndex === false) {
                return null;
            }

            for ($bit = 4; $bit >= 0; $bit--) {
                $bitValue = ($charIndex >> $bit) & 1;

                if ($isLng) {
                    $mid = ($minLng + $maxLng) / 2;
                    if ($bitValue === 1) {
                        $minLng = $mid;
                    } else {
                        $maxLng = $mid;
                    }
                } else {
                    $mid = ($minLat + $maxLat) / 2;
                    if ($bitValue === 1) {
                        $minLat = $mid;
                    } else {
                        $maxLat = $mid;
                    }
                }

                $isLng = ! $isLng;
            }
        }

        return [
            'min_lat' => $minLat,
            'max_lat' => $maxLat,
            'min_lng' => $minLng,
            'max_lng' => $maxLng,
        ];
    }

    /**
     * Closed GeoJSON Polygon ring (lng, lat) for a geohash cell, or null if the
     * hash is malformed.
     *
     * @return array<int, array<int, array{0: float, 1: float}>>|null
     */
    public static function cellPolygon(string $geohash): ?array
    {
        $b = self::decodeBounds($geohash);

        if (! $b) {
            return null;
        }

        return [[
            [$b['min_lng'], $b['min_lat']],
            [$b['max_lng'], $b['min_lat']],
            [$b['max_lng'], $b['max_lat']],
            [$b['min_lng'], $b['max_lat']],
            [$b['min_lng'], $b['min_lat']],
        ]];
    }

    /**
     * Number of cells that {@see enumerateCells} would produce for a bbox at
     * the given precision — used to pick a precision under a cap before paying
     * the enumeration cost.
     *
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}  $bbox
     */
    public static function estimateCellCount(array $bbox, int $precision): int
    {
        $size = self::cellSize($precision);

        $rows = (int) ceil(max(0.0, $bbox['max_lat'] - $bbox['min_lat']) / $size['lat']) + 1;
        $cols = (int) ceil(max(0.0, $bbox['max_lng'] - $bbox['min_lng']) / $size['lng']) + 1;

        return $rows * $cols;
    }

    /**
     * Every geohash cell of `precision` whose footprint overlaps `bbox`. Walks
     * a grid snapped to the global geohash lattice (origin = the cell holding
     * the bbox's SW corner) so each emitted center lands squarely in a distinct
     * cell. Stops once `cap` cells are collected, flagging `capped`.
     *
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}  $bbox
     * @return array{cells: array<int, string>, capped: bool}
     */
    public static function enumerateCells(array $bbox, int $precision, int $cap): array
    {
        $size = self::cellSize($precision);
        $latH = $size['lat'];
        $lngW = $size['lng'];

        $origin = self::decodeBounds(self::encode($bbox['min_lng'], $bbox['min_lat'], $precision));

        if (! $origin) {
            return ['cells' => [], 'capped' => false];
        }

        $cells = [];
        $capped = false;

        for ($lat = $origin['min_lat'] + $latH / 2; $lat <= $bbox['max_lat'] + $latH / 2; $lat += $latH) {
            for ($lng = $origin['min_lng'] + $lngW / 2; $lng <= $bbox['max_lng'] + $lngW / 2; $lng += $lngW) {
                $cells[self::encode($lng, $lat, $precision)] = true;

                if (count($cells) >= $cap) {
                    $capped = true;
                    break 2;
                }
            }
        }

        return ['cells' => array_keys($cells), 'capped' => $capped];
    }
}
