<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\ShiftEndTypeEnum;
use App\Jobs\Scout\SendEndOfShiftSummary;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Org;
use App\Models\Scan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use LaravelClickhouseEloquent\ClickhouseHttpClient;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class CloseInactiveShiftsCommandTest extends TestCase
{
    private Kit $kit;

    private int $scanId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);

        $org = Org::factory()->create();
        $this->kit = Kit::factory()->forOrg($org)->create();
    }

    #[Test]
    public function command_is_a_no_op_when_clarity_scout_disabled(): void
    {
        config()->set('app.experimental.clarity_scout', false);
        Queue::fake();

        KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(5),
        ]);

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $this->assertNull(KitShift::query()->first()->ended_at);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function command_closes_shift_when_latest_scan_is_more_than_three_hours_ago(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);

        $this->insertScan($this->kit, now()->subHours(4));

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $shift->refresh();
        $this->assertNotNull($shift->ended_at);
        $this->assertSame(ShiftEndTypeEnum::Assumed, $shift->end_type);

        Queue::assertPushed(SendEndOfShiftSummary::class, fn ($job) => $job->shiftId === $shift->id);
    }

    #[Test]
    public function command_skips_shift_with_recent_activity(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(2),
        ]);

        $this->insertScan($this->kit, now()->subHour());

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $this->assertNull($shift->fresh()->ended_at);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function command_closes_zero_activity_shift_opened_long_ago(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $startedAt = now()->subHours(4)->utc();
        $shift = KitShift::factory()->forKit($this->kit)->open()->create();
        DB::connection('platform')->table('kit_shifts')->where('id', $shift->id)->update([
            'started_at' => $startedAt->format('Y-m-d H:i:sP'),
        ]);

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $shift->refresh();
        $this->assertNotNull($shift->ended_at);
        $this->assertGreaterThan(0, $shift->ended_at->timestamp);
        $this->assertSame(ShiftEndTypeEnum::Assumed, $shift->end_type);

        Queue::assertPushed(SendEndOfShiftSummary::class);
    }

    #[Test]
    public function command_does_not_close_shift_whose_started_at_is_in_the_future(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->addHours(4),
        ]);

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $this->assertNull(KitShift::query()->where('kit_id', $this->kit->id)->value('ended_at'));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function command_does_not_re_close_already_closed_shifts(): void
    {
        Queue::fake();

        KitShift::factory()->forKit($this->kit)->confirmed()->create([
            'started_at' => now()->subHours(6),
            'ended_at' => now()->subHours(4),
            'end_type' => ShiftEndTypeEnum::Confirmed,
        ]);

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function command_keeps_shift_open_when_gps_is_recent_but_scans_are_stale(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);

        $this->insertScan($this->kit, now()->subHours(4));
        $this->insertScPosition(now()->subHour());

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $this->assertNull($shift->fresh()->ended_at);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function command_closes_shift_stamping_the_latest_gps_fix_as_the_end(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);

        $this->insertScPosition(now()->subHours(4));

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $shift->refresh();
        $this->assertNotNull($shift->ended_at);
        $this->assertSame(ShiftEndTypeEnum::Assumed, $shift->end_type);
        $this->assertEqualsWithDelta(
            now()->subHours(4)->timestamp,
            $shift->ended_at->timestamp,
            5,
        );

        Queue::assertPushed(SendEndOfShiftSummary::class, fn ($job) => $job->shiftId === $shift->id);
    }

    #[Test]
    public function latest_scan_lookup_reads_clickhouse_scans_hot_with_org_and_time_bound(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);

        $this->insertScan($this->kit, now()->subHours(4));

        $spy = $this->spyClickhouseSelects();

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $this->assertNotNull($shift->fresh()->ended_at);

        $offending = collect($spy->selects)->filter(fn (string $sql): bool => stripos($sql, 'timezones') !== false
            || stripos($sql, 'st_within') !== false);
        $this->assertTrue(
            $offending->isEmpty(),
            'latestScanFor() must not join timezones; offending: '.$offending->implode(' | '),
        );

        $scanLookups = collect($spy->selects)->filter(fn (string $sql): bool => str_contains($sql, 'scans_hot')
            && str_contains($sql, 'scanned_at'));
        $this->assertTrue(
            $scanLookups->isNotEmpty(),
            'expected a scans_hot activity lookup against ClickHouse',
        );
        $this->assertTrue(
            $scanLookups->every(fn (string $sql): bool => str_contains($sql, 'org_id')
                && str_contains($sql, 'kit_id')
                && str_contains($sql, '>=')),
            'latestScanFor() must filter org_id, kit_id, and bound scanned_at; unbound: '.$scanLookups->implode(' | '),
        );
    }

    #[Test]
    public function command_does_not_use_postgres_scans_for_latest_activity(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);

        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);

        DB::connection('zod')->flushQueryLog();
        DB::connection('zod')->enableQueryLog();

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $shift->refresh();
        $this->assertNotNull($shift->ended_at);
        $this->assertEqualsWithDelta(
            $shift->started_at->timestamp,
            $shift->ended_at->timestamp,
            5,
        );

        $pgScanMaxes = collect(DB::connection('zod')->getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'scanned_at')
                && (str_contains(strtolower($sql), 'max(') || str_contains($sql, 'aggregate')));
        $this->assertTrue(
            $pgScanMaxes->isEmpty(),
            'latestScanFor() must not query Postgres scans; offending: '.$pgScanMaxes->implode(' | '),
        );
    }

    #[Test]
    public function scan_recorded_before_shift_started_at_is_ignored(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);

        $this->insertScan($this->kit, now()->subHours(12));

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $shift->refresh();
        $this->assertNotNull($shift->ended_at);
        $this->assertSame(ShiftEndTypeEnum::Assumed, $shift->end_type);
        $this->assertEqualsWithDelta(
            $shift->started_at->timestamp,
            $shift->ended_at->timestamp,
            5,
        );
    }

    #[Test]
    public function command_reads_scans_cold_when_shift_started_more_than_ninety_days_ago(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subDays(120),
        ]);

        $this->insertScan($this->kit, now()->subHours(4), 'clarity.scans_cold');

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $shift->refresh();
        $this->assertNotNull($shift->ended_at);
        $this->assertEqualsWithDelta(
            now()->subHours(4)->timestamp,
            $shift->ended_at->timestamp,
            5,
        );
    }

    #[Test]
    public function a_failing_shift_is_skipped_and_later_shifts_still_close(): void
    {
        $this->requireClickhouse();
        Queue::fake();

        $org = Org::factory()->create();
        $kitA = Kit::factory()->forOrg($org)->create();
        $kitB = Kit::factory()->forOrg($org)->create();

        $shiftA = KitShift::factory()->forKit($kitA)->open()->create([
            'started_at' => now()->subHours(8),
        ]);
        $shiftB = KitShift::factory()->forKit($kitB)->open()->create([
            'started_at' => now()->subHours(8),
        ]);
        $this->insertScan($kitB, now()->subHours(4));

        $this->forceScanLookupFailure($kitA->id);

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        $this->assertNull($shiftA->fresh()->ended_at);
        $shiftB->refresh();
        $this->assertNotNull($shiftB->ended_at);
        $this->assertSame(ShiftEndTypeEnum::Assumed, $shiftB->end_type);
        Queue::assertPushed(SendEndOfShiftSummary::class, fn ($job) => $job->shiftId === $shiftB->id);
        Queue::assertNotPushed(SendEndOfShiftSummary::class, fn ($job) => $job->shiftId === $shiftA->id);
    }

    #[Test]
    public function completed_log_reports_closed_skipped_and_failed_counts(): void
    {
        $this->requireClickhouse();
        Queue::fake();
        Log::spy();

        $org = Org::factory()->create();
        $failingKit = Kit::factory()->forOrg($org)->create();
        $closingKit = Kit::factory()->forOrg($org)->create();
        $skippingKit = Kit::factory()->forOrg($org)->create();

        KitShift::factory()->forKit($failingKit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);
        KitShift::factory()->forKit($closingKit)->open()->create([
            'started_at' => now()->subHours(8),
        ]);
        KitShift::factory()->forKit($skippingKit)->open()->create([
            'started_at' => now()->subHours(2),
        ]);
        $this->insertScan($closingKit, now()->subHours(4));
        $this->insertScan($skippingKit, now()->subHour());

        $this->forceScanLookupFailure($failingKit->id);

        $this->artisan('scout:close-inactive-shifts')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'scout:close-inactive-shifts: completed'
                && ($context['closed'] ?? null) === 1
                && ($context['skipped'] ?? null) === 1
                && ($context['failed'] ?? null) === 1
                && array_key_exists('duration_ms', $context);
        });
    }

    private function requireClickhouse(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'clickhouse') {
            $this->markTestSkipped('Set CLICKHOUSE_DRIVER=clickhouse (and run ClickHouse) to execute this test.');
        }

        DB::connection('clarity_clickhouse')->statement('TRUNCATE TABLE IF EXISTS clarity.sc_positions');
        DB::connection('clarity_clickhouse')->statement('TRUNCATE TABLE IF EXISTS clarity.scans_hot');
        DB::connection('clarity_clickhouse')->statement('TRUNCATE TABLE IF EXISTS clarity.scans_cold');
        $this->scanId = 1;
    }

    /**
     * ClickHouse selects skip Laravel's run() path, so beforeExecuting / queryLog
     * never see them. Spy the HTTP client the same way Bird's Eye does.
     *
     * @param  (callable(string, array<int, mixed>): void)|null  $onSelect
     */
    private function spyClickhouseSelects(?callable $onSelect = null): ClickhouseHttpClient
    {
        $connection = DB::connection('clarity_clickhouse');
        $spy = new class(config('database.connections.clarity_clickhouse'), $onSelect) extends ClickhouseHttpClient
        {
            /** @var list<string> */
            public array $selects = [];

            public function __construct(array $config, private readonly mixed $onSelect)
            {
                parent::__construct($config);
            }

            public function select(string $sql, array $bindings = []): array
            {
                $this->selects[] = $sql;

                if (is_callable($this->onSelect)) {
                    ($this->onSelect)($sql, $bindings);
                }

                return parent::select($sql, $bindings);
            }
        };

        $clientProperty = new ReflectionProperty($connection, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($connection, $spy);

        return $spy;
    }

    private function forceScanLookupFailure(int $kitId): void
    {
        $this->spyClickhouseSelects(function (string $query, array $bindings) use ($kitId): void {
            if (! str_contains($query, 'scanned_at') || ! str_contains(strtolower($query), 'max(')) {
                return;
            }

            $boundKitId = $bindings[1] ?? null;
            if ((int) $boundKitId === $kitId) {
                throw new \RuntimeException('forced activity lookup timeout');
            }
        });
    }

    private function insertScan(Kit $kit, Carbon $scannedAt, string $table = 'clarity.scans_hot'): void
    {
        $at = $scannedAt->copy()->utc()->toDateTimeString();

        DB::connection('clarity_clickhouse')->table($table)->insert([
            'id' => $this->scanId++,
            'kit_id' => $kit->id,
            'org_id' => $kit->org_id,
            'ocr' => 'ABC1234',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => $at,
            'created_at' => $at,
            'lng' => -97.7,
            'lat' => 30.3,
            '_peerdb_is_deleted' => 0,
        ]);
    }

    /** @param array{0: float, 1: float} $point [lng, lat] */
    private function insertScPosition(Carbon $at, array $point = [-97.7400, 30.3000]): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.sc_positions')->insert([
            'kit_mapping' => $this->kit->kit_mapping,
            'geo' => "({$point[0]}, {$point[1]})",
            'timestamp_utc' => $at->toDateTimeString(),
        ]);
    }
}
