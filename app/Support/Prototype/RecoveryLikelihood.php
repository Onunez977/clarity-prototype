<?php

declare(strict_types=1);

namespace App\Support\Prototype;

use App\Support\Scout\Haversine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Additive Recovery Likelihood for one open assignment.
 *
 * Weights are the published point table from docs/low-hanging-fruit-brainstorm.md.
 * Change a constant to retune the queue. Every non-zero point is a breakdown row.
 * The one-line reason uses at most three clauses, and only clauses that scored.
 */
final class RecoveryLikelihood
{
    public const NEW_HIT_48H = 40;

    public const NEW_HIT_10D = 25;

    public const OLD_HIT = 8;

    public const REPEAT_CLOSE = 20;

    public const REPEAT_TIGHT = 10;

    public const DAY_PART = 10;

    public const DISTANCE_CLOSE = 15;

    public const DISTANCE_NEAR = 5;

    public const NO_CHECK_IN = 10;

    public const HIT_AFTER_CHECK_IN = 12;

    public const IN_COVERAGE = 8;

    public const DRIVER_NEARBY = 8;

    public const FRESH_ORDER = 5;

    public const INVALID_SCAN = -40;

    public const MOVING = -15;

    public const HOT_BAND = 70;

    public const WARM_BAND = 40;

    public const CLOSE_MILES = 0.1;

    public const TIGHT_MILES = 400 / 5280;

    public const NEAR_MILES = 1.0;

    public const DRIVER_RADIUS_MILES = 5.0;

    public const DRIVER_FRESH_MINUTES = 24 * 60;

    public const REPEAT_DAYS = 10;

    public const FRESH_ORDER_HOURS = 72;

    /**
     * @param  array{
     *     order_date: CarbonInterface|string,
     *     addresses: list<array<string, mixed>>,
     *     hits: list<array<string, mixed>>,
     *     check_ins: list<array<string, mixed>>
     * }  $case
     * @param  array<string, mixed>  $coverage
     * @param  list<array<string, mixed>>  $drivers
     * @return array<string, mixed>
     */
    public function score(array $case, array $coverage, array $drivers, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::parse($now ?? now());
        $orderDate = CarbonImmutable::parse($case['order_date']);
        $addresses = array_values($case['addresses'] ?? []);
        $hits = array_values($case['hits'] ?? []);
        $checkIns = array_values($case['check_ins'] ?? []);

        $scoredAddresses = [];
        foreach ($addresses as $address) {
            $scoredAddresses[] = $this->scoreAddress($address, $hits, $checkIns, $coverage, $drivers, $orderDate, $now);
        }

        $winner = null;
        foreach ($scoredAddresses as $address) {
            if ($winner === null || $this->beats($address, $winner)) {
                $winner = $address;
            }
        }

        $hasExact = (bool) ($winner['has_exact'] ?? false);
        $signals = $hasExact ? ($winner['signals'] ?? []) : [];
        $warnings = $hasExact ? ($winner['warnings'] ?? []) : [];
        $clauses = $hasExact ? ($winner['clauses'] ?? []) : [];

        $orderHours = ($now->getTimestamp() - $orderDate->getTimestamp()) / 3600;
        if ($orderHours >= 0 && $orderHours < self::FRESH_ORDER_HOURS) {
            $signals[] = [
                'label' => 'Order is under 3 days old',
                'points' => self::FRESH_ORDER,
                'detail' => $this->ago($orderDate, $now),
            ];
            $clauses[] = 'Order is '.$this->ago($orderDate, $now);
        }

        if (! $hasExact) {
            if ($this->hasProbable($hits)) {
                $warnings[] = 'Probable match is not in the score';
            }
            $ungeocoded = array_values(array_filter(
                $scoredAddresses,
                fn (array $address): bool => ! ($address['geocoded'] ?? false),
            ));
            $lead = ($addresses !== [] && count($ungeocoded) === count($addresses))
                ? 'Address '.($addresses[0]['position'] ?? 1).' is not geocoded'
                : 'No exact hit on a geocoded address';
            array_unshift($clauses, $lead);
        }

        $score = 0;
        foreach ($signals as $signal) {
            $score += (int) $signal['points'];
        }

        $reasonClauses = array_slice(array_values(array_filter($clauses)), 0, 3);

        $newest = $winner['newest_hit_at'] ?? null;

        return [
            'score' => $score,
            'band' => $this->band($score),
            'band_label' => $this->bandLabel($score),
            'reason' => $this->sentence($reasonClauses),
            'clauses' => $reasonClauses,
            'breakdown' => $signals,
            'warnings' => array_values(array_unique($warnings)),
            'best_address_id' => $winner['id'] ?? null,
            'addresses' => $scoredAddresses,
            'newest_hit_at' => $newest,
            'newest_hit_label' => $winner['newest_hit_label'] ?? 'No exact hit',
            'in_coverage' => $winner['in_coverage'] ?? null,
            'driver_name' => $winner['driver_name'] ?? null,
            'driver_miles' => $winner['driver_miles'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  list<array<string, mixed>>  $hits
     * @param  list<array<string, mixed>>  $checkIns
     * @param  array<string, mixed>  $coverage
     * @param  list<array<string, mixed>>  $drivers
     * @return array<string, mixed>
     */
    private function scoreAddress(
        array $address,
        array $hits,
        array $checkIns,
        array $coverage,
        array $drivers,
        CarbonImmutable $orderDate,
        CarbonImmutable $now,
    ): array {
        $id = (int) $address['id'];
        $position = (int) ($address['position'] ?? 0);
        $lat = $this->nullableFloat($address['lat'] ?? $address['latitude'] ?? null);
        $lng = $this->nullableFloat($address['lng'] ?? $address['longitude'] ?? null);
        $geocoded = $lat !== null && $lng !== null;
        $exact = [];
        $probable = 0;
        $stateMismatches = 0;

        foreach ($hits as $hit) {
            if ((int) ($hit['address_id'] ?? 0) !== $id) {
                continue;
            }
            $type = strtoupper((string) ($hit['hit_type'] ?? ''));
            if ($type === 'P') {
                $probable++;

                continue;
            }
            if (! in_array($type, ['L', 'H'], true)) {
                continue;
            }
            $exact[] = $hit;
            if (! empty($hit['state_mismatch'])) {
                $stateMismatches++;
            }
        }

        usort($exact, function (array $a, array $b): int {
            return CarbonImmutable::parse($b['scanned_at'])->getTimestamp()
                <=> CarbonImmutable::parse($a['scanned_at'])->getTimestamp();
        });

        $addressCheckIns = array_values(array_filter(
            $checkIns,
            fn (array $row): bool => (int) ($row['address_id'] ?? 0) === $id,
        ));
        $lastCheckIn = null;
        foreach ($addressCheckIns as $row) {
            $at = CarbonImmutable::parse($row['matched_at']);
            if ($lastCheckIn === null || $at->greaterThan($lastCheckIn)) {
                $lastCheckIn = $at;
            }
        }

        $base = [
            'id' => $id,
            'position' => $position,
            'line' => (string) ($address['line'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'lat' => $lat,
            'lng' => $lng,
            'geocoded' => $geocoded,
            'has_exact' => $exact !== [],
            'hit_count' => count($exact),
            'close_hit_count' => 0,
            'nearest_miles' => null,
            'check_in_count' => count($addressCheckIns),
            'last_check_in_at' => $lastCheckIn?->toIso8601String(),
            'last_check_in_label' => $lastCheckIn ? $this->ago($lastCheckIn, $now) : null,
            'in_coverage' => null,
            'driver_name' => null,
            'driver_miles' => null,
            'signals' => [],
            'clauses' => [],
            'warnings' => [],
            'score' => 0,
            'reason' => $geocoded ? 'No exact hit' : 'Address '.$position.' is not geocoded',
            'newest_hit_at' => null,
            'newest_hit_label' => 'No exact hit',
        ];

        if (! $geocoded) {
            $base['clauses'] = ['Address '.$position.' is not geocoded'];

            return $base;
        }

        $inCoverage = $this->inside($lat, $lng, $coverage);
        $base['in_coverage'] = ($coverage['kind'] ?? 'none') === 'none' ? null : $inCoverage;
        $driver = $this->nearestFreshDriver($lat, $lng, $drivers);

        if ($exact === []) {
            if ($probable > 0) {
                $base['warnings'][] = 'Probable match is not in the score';
            }
            $base['clauses'] = ['No exact hit'];

            return $base;
        }

        $newest = $exact[0];
        $newestAt = CarbonImmutable::parse($newest['scanned_at']);
        $newestHours = max(0, ($now->getTimestamp() - $newestAt->getTimestamp()) / 3600);
        $historical = strtoupper((string) $newest['hit_type']) === 'H' && $newestAt->lessThan($orderDate);
        $signals = [];
        $clauses = [];

        if ($newestHours < 48) {
            $signals[] = $this->signal('Newest exact hit is under 48 hours', self::NEW_HIT_48H, $this->ago($newestAt, $now));
        } elseif ($newestHours <= self::REPEAT_DAYS * 24) {
            $signals[] = $this->signal('Newest exact hit is 2–10 days old', self::NEW_HIT_10D, $this->ago($newestAt, $now));
        } else {
            $signals[] = $this->signal('Newest exact hit is older than 10 days', self::OLD_HIT, $this->ago($newestAt, $now));
        }

        if ($historical) {
            $clauses[] = 'Historical, scanned '.$newestAt->format('M j, Y').' ('.$this->ago($newestAt, $now).')';
        } else {
            $clauses[] = 'Newest hit '.$this->ago($newestAt, $now);
        }

        $recentClose = [];
        $nearest = null;
        foreach ($exact as $hit) {
            $distance = $this->nullableFloat($hit['distance_miles'] ?? null);
            if ($distance === null) {
                continue;
            }
            $nearest = $nearest === null ? $distance : min($nearest, $distance);
            $age = ($now->getTimestamp() - CarbonImmutable::parse($hit['scanned_at'])->getTimestamp()) / 86400;
            if ($distance <= self::CLOSE_MILES && $age <= self::REPEAT_DAYS) {
                $recentClose[] = $hit;
            }
        }

        $dayPart = null;
        if (count($recentClose) >= 2) {
            $signals[] = $this->signal(
                'Repeat hits within 0.1 mi in the last 10 days',
                self::REPEAT_CLOSE,
                count($recentClose).' exact hits',
            );
            $tight = 0;
            $parts = [];
            foreach ($recentClose as $hit) {
                $distance = (float) $hit['distance_miles'];
                if ($distance <= self::TIGHT_MILES) {
                    $tight++;
                }
                $parts[] = $this->dayPart((int) ($hit['local_hour'] ?? 12));
            }
            if ($tight >= 2) {
                $signals[] = $this->signal('Repeat hits within about 400 ft', self::REPEAT_TIGHT, $tight.' hits');
            }
            if (count(array_unique($parts)) === 1) {
                $dayPart = $parts[0];
                $signals[] = $this->signal('Repeats share a local day-part', self::DAY_PART, $dayPart.' cluster');
            }
            $repeat = count($recentClose).' exact hits within 0.1 mi of Address '.$position.' in the last 10 days';
            if ($dayPart !== null) {
                $repeat .= ', '.$dayPart.' cluster';
            }
            array_unshift($clauses, $repeat);
        }

        if ($nearest !== null && $nearest <= self::CLOSE_MILES) {
            $signals[] = $this->signal('Nearest hit is under 0.1 mi', self::DISTANCE_CLOSE, self::formatMiles($nearest));
            if (count($recentClose) < 2) {
                $clauses[] = self::formatMiles($nearest).' from Address '.$position;
            }
        } elseif ($nearest !== null && $nearest <= self::NEAR_MILES) {
            $signals[] = $this->signal('Nearest hit is 0.1–1 mi', self::DISTANCE_NEAR, self::formatMiles($nearest));
            if (count($recentClose) < 2) {
                $clauses[] = self::formatMiles($nearest).' from Address '.$position;
            }
        }

        $hitAfterCheckIn = false;
        if ($addressCheckIns === []) {
            $signals[] = $this->signal('No check-in recorded', self::NO_CHECK_IN, 'Address '.$position);
            $clauses[] = 'No check-in recorded';
        } else {
            foreach ($exact as $hit) {
                if (CarbonImmutable::parse($hit['scanned_at'])->greaterThan($lastCheckIn)) {
                    $hitAfterCheckIn = true;
                    break;
                }
            }
            if ($hitAfterCheckIn) {
                $signals[] = $this->signal('Exact hit after the latest check-in', self::HIT_AFTER_CHECK_IN, 'Latest check-in '.$this->ago($lastCheckIn, $now));
                $clauses[] = 'Hits after the latest check-in';
            } else {
                $base['warnings'][] = 'Check-in recorded '.$this->ago($lastCheckIn, $now);
            }
        }

        if (($coverage['kind'] ?? 'none') !== 'none') {
            if ($inCoverage) {
                $signals[] = $this->signal('Inside the company coverage zone', self::IN_COVERAGE, (string) ($coverage['label'] ?? 'Coverage zone'));
                $clauses[] = 'Inside the company zone';
            } else {
                $base['warnings'][] = 'Outside the company zone';
            }
        }

        if ($driver !== null) {
            $signals[] = $this->signal(
                'Driver with a fresh GPS fix is within 5 miles',
                self::DRIVER_NEARBY,
                $driver['name'].' · '.self::formatMiles($driver['miles']).' straight-line',
            );
            $clauses[] = $driver['name'].' is '.self::formatMiles($driver['miles']).' straight-line away';
            $base['driver_name'] = $driver['name'];
            $base['driver_miles'] = $driver['miles'];
        }

        $disposition = (string) ($newest['disposition'] ?? '');
        if ($disposition === 'invalid_scan') {
            array_unshift($signals, $this->signal('Newest hit marked Invalid Scan', self::INVALID_SCAN, 'Disposition'));
            array_unshift($clauses, 'Newest hit marked Invalid Scan');
        } elseif ($disposition === 'moving') {
            array_unshift($signals, $this->signal('Newest hit marked Moving', self::MOVING, 'Disposition'));
            array_unshift($clauses, 'Newest hit marked Moving');
        }

        if ($probable > 0) {
            $base['warnings'][] = $probable === 1
                ? '1 probable match is not in the score'
                : $probable.' probable matches are not in the score';
        }
        if ($stateMismatches > 0) {
            $base['warnings'][] = $stateMismatches === 1
                ? 'State on 1 hit differs from the order'
                : 'State on '.$stateMismatches.' hits differs from the order';
        }

        $score = 0;
        foreach ($signals as $signal) {
            $score += (int) $signal['points'];
        }

        $base['signals'] = $signals;
        $base['clauses'] = array_values(array_filter($clauses));
        $base['score'] = $score;
        $base['reason'] = $this->sentence(array_slice($base['clauses'], 0, 3));
        $base['close_hit_count'] = count($recentClose);
        $base['nearest_miles'] = $nearest;
        $base['newest_hit_at'] = $newestAt->toIso8601String();
        $base['newest_hit_label'] = $historical
            ? 'Historical '.$newestAt->format('M j, Y')
            : $this->ago($newestAt, $now);

        return $base;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private function beats(array $candidate, array $current): bool
    {
        $score = ($candidate['score'] ?? 0) <=> ($current['score'] ?? 0);
        if ($score !== 0) {
            return $score > 0;
        }
        $newest = strcmp((string) ($candidate['newest_hit_at'] ?? ''), (string) ($current['newest_hit_at'] ?? ''));
        if ($newest !== 0) {
            return $newest > 0;
        }

        return ($candidate['position'] ?? 0) < ($current['position'] ?? 0);
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     */
    private function hasProbable(array $hits): bool
    {
        foreach ($hits as $hit) {
            if (strtoupper((string) ($hit['hit_type'] ?? '')) === 'P') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $drivers
     * @return array{name: string, miles: float}|null
     */
    private function nearestFreshDriver(float $lat, float $lng, array $drivers): ?array
    {
        $best = null;
        foreach ($drivers as $driver) {
            $minutes = (int) ($driver['fix_minutes_ago'] ?? 999999);
            if ($minutes >= self::DRIVER_FRESH_MINUTES) {
                continue;
            }
            $driverLat = $this->nullableFloat($driver['lat'] ?? null);
            $driverLng = $this->nullableFloat($driver['lng'] ?? null);
            if ($driverLat === null || $driverLng === null) {
                continue;
            }
            $miles = $this->miles($lat, $lng, $driverLat, $driverLng);
            if ($miles > self::DRIVER_RADIUS_MILES) {
                continue;
            }
            if ($best === null || $miles < $best['miles']) {
                $best = [
                    'name' => (string) ($driver['name'] ?? 'Driver'),
                    'miles' => $miles,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $coverage
     */
    private function inside(float $lat, float $lng, array $coverage): bool
    {
        $kind = (string) ($coverage['kind'] ?? 'none');
        if ($kind === 'radius') {
            return $this->miles($lat, $lng, (float) $coverage['lat'], (float) $coverage['lng']) <= (float) $coverage['radius_miles'];
        }
        if ($kind === 'polygon') {
            return $this->inRing($lng, $lat, $coverage['ring'] ?? []);
        }
        if ($kind === 'locations') {
            foreach ($coverage['shapes'] ?? [] as $shape) {
                if ($this->inside($lat, $lng, $shape)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function inRing(float $lng, float $lat, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        if ($count < 3) {
            return false;
        }
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];
            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return Haversine::feet([$lng1, $lat1], [$lng2, $lat2]) / 5280;
    }

    private function dayPart(int $hour): string
    {
        return match (true) {
            $hour <= 5 => 'overnight',
            $hour <= 11 => 'morning',
            $hour <= 16 => 'afternoon',
            default => 'evening',
        };
    }

    /**
     * @return array{label: string, points: int, detail: string}
     */
    private function signal(string $label, int $points, string $detail): array
    {
        return ['label' => $label, 'points' => $points, 'detail' => $detail];
    }

    /**
     * @param  list<string>  $clauses
     */
    private function sentence(array $clauses): string
    {
        $clauses = array_values(array_filter($clauses, fn (string $clause): bool => $clause !== ''));
        if ($clauses === []) {
            return 'No scored signals';
        }

        return implode('. ', $clauses).'.';
    }

    private function band(int $score): string
    {
        if ($score >= self::HOT_BAND) {
            return 'hot';
        }
        if ($score >= self::WARM_BAND) {
            return 'warm';
        }

        return 'cool';
    }

    private function bandLabel(int $score): string
    {
        return match ($this->band($score)) {
            'hot' => 'Hot',
            'warm' => 'Warm',
            default => 'Cool',
        };
    }

    public static function formatMiles(float $miles): string
    {
        return number_format($miles, $miles < 0.1 ? 2 : 1).' mi';
    }

    public function ago(CarbonInterface $at, CarbonInterface $now): string
    {
        $minutes = max(0, ($now->getTimestamp() - $at->getTimestamp()) / 60);
        if ($minutes < 90) {
            return max(1, (int) round($minutes)).' min ago';
        }
        $hours = $minutes / 60;
        if ($hours < 48) {
            return (int) round($hours).' hours ago';
        }

        return max(2, (int) round($hours / 24)).' days ago';
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
