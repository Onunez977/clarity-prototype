<?php

declare(strict_types=1);

namespace App\Support\Scout;

use App\Http\Controllers\Api\ClarityScoutTripsController;

/**
 * Haversine distance over an ordered list of [lng, lat] coordinates.
 *
 * Mirrors the inline implementation in
 * {@see ClarityScoutTripsController::calculateMiles()}
 * — kept as a separate helper so the shift-summary service and the trips
 * controller can share it without duplicating geometry math.
 */
final class Haversine
{
    private const EARTH_RADIUS_MILES = 3958.8;

    private const EARTH_RADIUS_FEET = self::EARTH_RADIUS_MILES * 5280;

    /**
     * Great-circle distance in feet between two [lng, lat] points. Used by the
     * Scout historic-path builder to break a line on a real power-off-and-drive
     * jump (see {@see HistoricPathBuilder}).
     *
     * @param  array{0: float, 1: float}  $from  [lng, lat]
     * @param  array{0: float, 1: float}  $to  [lng, lat]
     */
    public static function feet(array $from, array $to): float
    {
        $lng1 = deg2rad((float) $from[0]);
        $lat1 = deg2rad((float) $from[1]);
        $lng2 = deg2rad((float) $to[0]);
        $lat2 = deg2rad((float) $to[1]);

        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;

        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_FEET * $c;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $coordinates  Ordered [lng, lat] pairs
     */
    public static function miles(array $coordinates): float
    {
        if (count($coordinates) < 2) {
            return 0.0;
        }

        $total = 0.0;
        $count = count($coordinates);

        for ($i = 1; $i < $count; $i++) {
            $lng1 = deg2rad((float) $coordinates[$i - 1][0]);
            $lat1 = deg2rad((float) $coordinates[$i - 1][1]);
            $lng2 = deg2rad((float) $coordinates[$i][0]);
            $lat2 = deg2rad((float) $coordinates[$i][1]);

            $dlat = $lat2 - $lat1;
            $dlng = $lng2 - $lng1;

            $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

            $total += self::EARTH_RADIUS_MILES * $c;
        }

        return $total;
    }
}
