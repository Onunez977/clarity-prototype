<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Prototype;

use App\Support\Prototype\RecoveryLikelihood;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class RecoveryLikelihoodTest extends TestCase
{
    public function test_overnight_repeat_near_an_unvisited_address_scores_hot_and_explains_itself(): void
    {
        $now = CarbonImmutable::parse('2026-10-11 18:00:00');
        $result = (new RecoveryLikelihood)->score(
            $this->case($now, [
                ['id' => 1, 'position' => 1, 'line' => '120 Main St', 'lat' => 39.8662, 'lng' => -86.1494],
                ['id' => 2, 'position' => 2, 'line' => '88 Oak Ave', 'lat' => 39.8712, 'lng' => -86.1416],
            ], [
                $this->hit(2, $now->subHours(6), 'L', 0.04, 2, true),
                $this->hit(2, $now->subHours(30), 'L', 0.05, 3, true),
                $this->hit(2, $now->subHours(24 * 8), 'L', 0.06, 1),
                $this->hit(2, $now->subDays(120), 'H', 0.08, 2),
            ], [
                ['address_id' => 1, 'matched_at' => $now->subHours(20)],
            ], $now->subDays(14)),
            $this->coverage(),
            [$this->driver(12)],
            $now,
        );

        $this->assertSame(121, $result['score']);
        $this->assertSame('hot', $result['band']);
        $this->assertSame(2, $result['best_address_id']);
        $this->assertSame(
            '3 exact hits within 0.1 mi of Address 2 in the last 10 days, overnight cluster. Newest hit 6 hours ago. No check-in recorded.',
            $result['reason'],
        );
        $this->assertLessThanOrEqual(3, count($result['clauses']));
        $this->assertStringNotContainsStringIgnoringCase('never visited', $result['reason']);
        $this->assertStringNotContainsStringIgnoringCase('home', $result['reason']);
        $this->assertSame(121, array_sum(array_column($result['breakdown'], 'points')));
        $this->assertContains('State on 2 hits differs from the order', $result['warnings']);
    }

    public function test_invalid_scan_subtracts_forty_points(): void
    {
        $now = CarbonImmutable::parse('2026-10-11 18:00:00');
        $hits = [$this->hit(1, $now->subHours(4), 'L', 0.05, 11)];
        $plain = $this->scoreHits($now, $hits);
        $hits[0]['disposition'] = 'invalid_scan';
        $invalid = $this->scoreHits($now, $hits);

        $this->assertSame($plain['score'] - 40, $invalid['score']);
        $this->assertStringStartsWith('Newest hit marked Invalid Scan', $invalid['reason']);
    }

    public function test_probable_match_is_not_scored(): void
    {
        $now = CarbonImmutable::parse('2026-10-11 18:00:00');
        $result = $this->scoreHits($now, [
            $this->hit(1, $now->subHours(2), 'P', 0.02, 13),
        ], $now->subDays(10));

        $this->assertSame(0, $result['score']);
        $this->assertSame('No exact hit on a geocoded address.', $result['reason']);
        $this->assertContains('Probable match is not in the score', $result['warnings']);
    }

    public function test_stale_driver_and_old_historical_hit_do_not_look_fresh(): void
    {
        $now = CarbonImmutable::parse('2026-10-11 18:00:00');
        $result = (new RecoveryLikelihood)->score(
            $this->case($now, [
                ['id' => 1, 'position' => 1, 'line' => '180 S College Ave', 'lat' => 39.1653, 'lng' => -86.5264],
            ], [
                $this->hit(1, $now->subDays(120), 'H', 0.4, 14),
            ], [], $now->subDays(40)),
            $this->coverage(),
            [$this->driver(1800)],
            $now,
        );

        $this->assertLessThan(RecoveryLikelihood::HOT_BAND, $result['score']);
        $this->assertStringContainsString('Historical, scanned', $result['reason']);
        $this->assertNull($result['driver_name']);
        $this->assertFalse($result['in_coverage']);
        $labels = array_column($result['breakdown'], 'label');
        $this->assertNotContains('Newest exact hit is under 48 hours', $labels);
        $this->assertNotContains('Driver with a fresh GPS fix is within 5 miles', $labels);
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return array<string, mixed>
     */
    private function scoreHits(CarbonImmutable $now, array $hits, ?CarbonImmutable $orderDate = null): array
    {
        return (new RecoveryLikelihood)->score(
            $this->case($now, [
                ['id' => 1, 'position' => 1, 'line' => '4100 N College Ave', 'lat' => 39.8688, 'lng' => -86.1452],
            ], $hits, [], $orderDate ?? $now->subDays(14)),
            $this->coverage(),
            [$this->driver(12)],
            $now,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @param  list<array<string, mixed>>  $hits
     * @param  list<array<string, mixed>>  $checkIns
     * @return array<string, mixed>
     */
    private function case(CarbonImmutable $now, array $addresses, array $hits, array $checkIns, CarbonImmutable $orderDate): array
    {
        return [
            'order_date' => $orderDate,
            'addresses' => $addresses,
            'hits' => $hits,
            'check_ins' => $checkIns,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hit(int $addressId, CarbonImmutable $at, string $type, float $miles, int $hour, bool $stateMismatch = false): array
    {
        return [
            'address_id' => $addressId,
            'scanned_at' => $at,
            'hit_type' => $type,
            'distance_miles' => $miles,
            'local_hour' => $hour,
            'disposition' => null,
            'state_mismatch' => $stateMismatch,
            'kit_name' => 'North 4',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function coverage(): array
    {
        return [
            'kind' => 'radius',
            'label' => '25 miles around Test Company HQ',
            'lat' => 39.768403,
            'lng' => -86.158066,
            'radius_miles' => 25,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driver(int $minutes): array
    {
        return [
            'id' => 1,
            'name' => 'Jordan Hale',
            'lat' => 39.8680,
            'lng' => -86.1450,
            'fix_minutes_ago' => $minutes,
        ];
    }
}
