<?php

declare(strict_types=1);

namespace App\Support\Scout;

use Illuminate\Support\Carbon;

/**
 * Rebuild a Scout map's historic drive overlay from raw scanner positions
 * (clarity.sc_positions) as an array of short, per-hour line strings rather than
 * one continuous multi-day line (see DEV-2018).
 *
 * Splitting on the hour and on a >{@see self::BREAK_DISTANCE_FEET} jump between
 * consecutive points stops the overlapping/duplicate-line and out-of-order
 * artifacts that the kit_trips-backed overlay produced: a power-off-and-drive
 * gap breaks the line instead of drawing a bogus connector, and single-point
 * segments (themselves an artifact source) are dropped.
 */
final class HistoricPathBuilder
{
    /**
     * Break a line when consecutive positions jump farther than this. At native
     * ~1s cadence even ~100mph keeps legit consecutive points a few hundred feet
     * apart, so 500ft only trips on genuine gaps (a missed beacon / power-off).
     */
    private const BREAK_DISTANCE_FEET = 500.0;

    /**
     * Debug-only palette, indexed by hour-of-day, so each per-hour segment paints
     * a distinct color when the scanner debug map is enabled.
     *
     * @var list<array{0: int, 1: int, 2: int}>
     */
    private const DEBUG_COLORS = [
        [228, 26, 28],
        [55, 126, 184],
        [77, 175, 74],
        [152, 78, 163],
        [255, 127, 0],
        [166, 86, 40],
        [247, 129, 191],
        [153, 153, 153],
    ];

    /**
     * @param  list<array{lng: float, lat: float, timestamp_utc: string}>  $positions  Ordered by timestamp ASC, UTC
     * @return list<array{path: list<array{0: float, 1: float}>, trip_date: string, label?: string, color?: array{0: int, 1: int, 2: int}}>
     */
    public static function build(array $positions, string $timezone, bool $debug = false): array
    {
        $segments = [];
        $current = null;
        $previous = null;

        foreach ($positions as $position) {
            $point = [(float) $position['lng'], (float) $position['lat']];
            $local = Carbon::parse($position['timestamp_utc'], 'UTC')->setTimezone($timezone);
            $hourKey = $local->format('Y-m-d H');

            $shouldBreak = $current !== null && (
                $hourKey !== $current['hour_key']
                || ($previous !== null && Haversine::feet($previous, $point) > self::BREAK_DISTANCE_FEET)
            );

            if ($shouldBreak) {
                $segments[] = $current;
                $current = null;
            }

            if ($current === null) {
                $current = ['points' => [], 'start' => $local, 'hour_key' => $hourKey];
            }

            $current['points'][] = $point;
            $previous = $point;
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        $result = [];

        foreach ($segments as $segment) {
            if (count($segment['points']) < 2) {
                continue;
            }

            /** @var Carbon $start */
            $start = $segment['start'];

            $item = [
                'path' => $segment['points'],
                'trip_date' => $start->format('Y-m-d'),
            ];

            if ($debug) {
                $hourStart = $start->copy()->startOfHour();
                $item['label'] = $hourStart->format('g:i A').'–'.$hourStart->copy()->addHour()->format('g:i A');
                $item['color'] = self::DEBUG_COLORS[(int) $hourStart->format('G') % count(self::DEBUG_COLORS)];
            }

            $result[] = $item;
        }

        return $result;
    }
}
