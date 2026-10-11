<?php

namespace Tests\Feature\Services\Scout;

use App\Dto\Scout\ZoneSummaryDto;
use App\Enums\ShiftEndTypeEnum;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Org;
use App\Models\Zone;
use App\Services\Scout\BuildShiftSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class BuildShiftSummaryWindowTest extends TestCase
{
    private Org $org;

    private Kit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfHour());
        config()->set('services.mapbox.token', null);

        $this->org = Org::factory()->create();
        $this->kit = Kit::factory()->forOrg($this->org)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_clamps_a_multi_week_shift_read_window_to_eight_hours(): void
    {
        $endedAt = Carbon::parse('2026-08-17 17:18:50', 'UTC');
        $shift = $this->shift($endedAt->copy()->subDays(17), $endedAt);

        [$windowStart, $windowEnd] = $this->invokeSummaryWindow($shift);

        $this->assertTrue($windowEnd->equalTo($endedAt->copy()->utc()));
        $this->assertTrue($windowStart->equalTo($endedAt->copy()->utc()->subHours(BuildShiftSummary::SUMMARY_WINDOW_HOURS)));
    }

    #[Test]
    public function it_does_not_widen_a_short_shift_to_eight_hours(): void
    {
        $endedAt = Carbon::parse('2026-08-17 17:18:50', 'UTC');
        $startedAt = $endedAt->copy()->subHours(3);
        $shift = $this->shift($startedAt, $endedAt);

        [$windowStart, $windowEnd] = $this->invokeSummaryWindow($shift);

        $this->assertTrue($windowStart->equalTo($startedAt->copy()->utc()));
        $this->assertTrue($windowEnd->equalTo($endedAt->copy()->utc()));
    }

    #[Test]
    public function it_keeps_window_end_on_ended_at_when_the_last_scan_is_earlier(): void
    {
        $endedAt = Carbon::parse('2026-08-18 08:10:00', 'UTC');
        $lastScanAt = Carbon::parse('2026-08-17 17:00:00', 'UTC');
        $shift = $this->shift($endedAt->copy()->subDay(), $endedAt);
        $this->insertPgScan([-97.7148, 30.3052], $lastScanAt);

        [$windowStart, $windowEnd] = $this->invokeSummaryWindow($shift);

        $this->assertTrue($windowEnd->equalTo($endedAt->copy()->utc()));
        $this->assertTrue($windowStart->equalTo($endedAt->copy()->utc()->subHours(BuildShiftSummary::SUMMARY_WINDOW_HOURS)));
        $this->assertTrue($windowStart->greaterThan($lastScanAt));
    }

    #[Test]
    public function it_attributes_zones_using_only_the_clamped_window(): void
    {
        $endedAt = now();
        $shift = $this->shift($endedAt->copy()->subDays(17), $endedAt);
        $zone = Zone::factory()->forOrg($this->org)->atLocation(-97.7150, 30.3050)->withGeometry()->create();

        $this->insertPgScan([-97.7145, 30.3055], $endedAt->copy()->subHours(10));
        $this->insertPgScan([-97.7148, 30.3052], $endedAt->copy()->subHour());

        [$windowStart, $windowEnd] = $this->invokeSummaryWindow($shift);

        DB::connection('zod')->enableQueryLog();
        $zones = $this->invokeBuildZoneSummaries($shift, $windowStart, $windowEnd);
        $hitQuery = collect(DB::connection('zod')->getQueryLog())
            ->first(fn (array $entry) => str_contains(strtolower($entry['query']), 'hit_type'));
        DB::connection('zod')->flushQueryLog();
        DB::connection('zod')->disableQueryLog();

        $this->assertCount(1, $zones);
        $this->assertSame($zone->id, $zones[0]->id);
        $this->assertSame(1, $zones[0]->scans);
        $this->assertNotNull($hitQuery);
        $this->assertMatchesRegularExpression('/scan_id["`]?\s+in\s+\(\s*\d+/i', $hitQuery['query']);
        $this->assertDoesNotMatchRegularExpression('/scan_id["`]?\s+in\s+\(\s*\?/i', $hitQuery['query']);
    }

    #[Test]
    public function it_loads_hits_for_more_than_65535_scan_ids_without_a_parameter_limit_error(): void
    {
        $endedAt = Carbon::parse('2026-08-17 17:18:50', 'UTC');
        $startedAt = $endedAt->copy()->subHour();

        $hits = $this->invokeHitsForScanIds(range(1, 70_000), $startedAt, $endedAt);

        $this->assertCount(0, $hits);
    }

    #[Test]
    public function it_passes_the_clamped_window_to_every_for_shift_read(): void
    {
        $endedAt = Carbon::parse('2026-08-17 17:18:50', 'UTC');
        $shift = $this->shift($endedAt->copy()->subDays(17), $endedAt);
        $expectedStart = $endedAt->copy()->utc()->subHours(BuildShiftSummary::SUMMARY_WINDOW_HOURS);

        $builder = new class extends BuildShiftSummary
        {
            public ?Carbon $positionsStart = null;

            public ?Carbon $positionsEnd = null;

            public ?Carbon $scansStart = null;

            public ?Carbon $hitsStart = null;

            protected function pullPositions(?string $kitMapping, Carbon $startedAt, Carbon $endedAt): array
            {
                $this->positionsStart = $startedAt;
                $this->positionsEnd = $endedAt;

                return [];
            }

            protected function countScans(int $orgId, int $kitId, Carbon $startedAt, Carbon $endedAt): int
            {
                $this->scansStart = $startedAt;

                return 0;
            }

            protected function countHits(int $orgId, int $kitId, Carbon $startedAt, Carbon $endedAt): int
            {
                $this->hitsStart = $startedAt;

                return 0;
            }
        };

        DB::connection('platform')->enableQueryLog();
        $summary = $builder->forShift($shift);
        $zoneQuery = collect(DB::connection('platform')->getQueryLog())
            ->first(fn (array $entry) => str_contains(strtolower($entry['query']), 'shift_scans'));
        DB::connection('platform')->flushQueryLog();
        DB::connection('platform')->disableQueryLog();

        $this->assertNotNull($zoneQuery);
        $this->assertSame($expectedStart->toDateTimeString(), (string) $zoneQuery['bindings'][1]);
        $this->assertSame($endedAt->copy()->utc()->toDateTimeString(), (string) $zoneQuery['bindings'][2]);
        $this->assertTrue($builder->positionsStart?->equalTo($expectedStart));
        $this->assertTrue($builder->positionsEnd?->equalTo($endedAt->copy()->utc()));
        $this->assertTrue($builder->scansStart?->equalTo($expectedStart));
        $this->assertTrue($builder->hitsStart?->equalTo($expectedStart));
        $this->assertTrue($summary->isTruncated());
        $this->assertTrue($summary->windowStartedAt->equalTo($expectedStart));
        $this->assertTrue($summary->startedAt->equalTo($shift->started_at));
        $this->assertTrue($summary->endedAt->equalTo($shift->ended_at));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function invokeSummaryWindow(KitShift $shift): array
    {
        $method = new ReflectionMethod(BuildShiftSummary::class, 'summaryWindow');

        return $method->invoke(new BuildShiftSummary, $shift);
    }

    /**
     * @return array<int, ZoneSummaryDto>
     */
    private function invokeBuildZoneSummaries(KitShift $shift, Carbon $startedAt, Carbon $endedAt): array
    {
        $method = new ReflectionMethod(BuildShiftSummary::class, 'buildZoneSummaries');

        return $method->invoke(new BuildShiftSummary, $shift, $startedAt, $endedAt);
    }

    /**
     * @param  list<int>  $scanIds
     */
    private function invokeHitsForScanIds(array $scanIds, Carbon $startedAt, Carbon $endedAt): mixed
    {
        $method = new ReflectionMethod(BuildShiftSummary::class, 'hitsForScanIds');

        return $method->invoke(new BuildShiftSummary, $scanIds, $startedAt, $endedAt);
    }

    private function shift(Carbon $startedAt, Carbon $endedAt): KitShift
    {
        return KitShift::factory()->forKit($this->kit)->create([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'end_type' => ShiftEndTypeEnum::Confirmed,
        ]);
    }

    /**
     * Seed a `public.scans` row directly on the `platform` connection (raw
     * SQL insert, not `Scan::factory()`) so it's visible to the raw-SQL
     * zone-breakdown query within the same test transaction.
     *
     * Triggers are disabled for the insert: `no_scans_in_illegal_states`
     * unconditionally acquires a lock on `tiger.state` while planning (even
     * though it's short-circuited away at runtime for most rows), and
     * `HasZodDatabase::setupHasZodDatabase()` holds an `AccessExclusiveLock`
     * on `tiger.state` (via an `ALTER TABLE`) for the whole test's
     * `zod`-connection transaction — a cross-connection insert that plans a
     * read of it here would deadlock. None of the scans triggers are
     * relevant to this fixture (no hotlist/overwatch matching, no
     * aggregate/metadata rollups needed).
     *
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertPgScan(array $point, Carbon $at, bool $isVisible = true): void
    {
        DB::connection('platform')->statement('ALTER TABLE public.scans DISABLE TRIGGER ALL');

        try {
            DB::connection('platform')->insert(
                'INSERT INTO public.scans (ocr, us_state, geo, kit_id, is_visible, scanned_at, created_at, camera_name, guid)
                 VALUES (?, ?, public.ST_SetSRID(public.ST_MakePoint(?, ?), 4326), ?, ?, ?, ?, ?, ?)',
                [
                    'ABC'.random_int(1000, 9999),
                    'TX',
                    $point[0],
                    $point[1],
                    $this->kit->id,
                    $isVisible,
                    $at->toDateTimeString(),
                    $at->toDateTimeString(),
                    'cam1',
                    (string) Str::uuid(),
                ]
            );
        } finally {
            DB::connection('platform')->statement('ALTER TABLE public.scans ENABLE TRIGGER ALL');
        }
    }
}
