<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scout;

use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Org;
use App\Models\Scan;
use App\Services\Scout\EnsureOpenShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Shift materialization + window inference (DEV-1916 §6, highest risk).
 *
 * `started_at` is inferred from the kit's recent scan history: the earliest
 * scan in the current continuous activity window, where a >=3h gap starts a
 * new window. See {@see EnsureOpenShift}.
 */
class EnsureOpenShiftTest extends TestCase
{
    private Org $org;

    private Kit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-27 12:00:00'));

        $this->org = Org::factory()->create();
        $this->kit = Kit::factory()->forOrg($this->org)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function scanAt(Carbon $at): void
    {
        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => $at,
            'created_at' => $at,
        ]);
    }

    private function service(): EnsureOpenShift
    {
        return app(EnsureOpenShift::class);
    }

    #[Group('clarity-scout')]
    public function test_for_kit_infers_start_from_earliest_scan_in_a_continuous_run(): void
    {
        $this->scanAt(now()->subHours(2));
        $this->scanAt(now()->subMinutes(90));
        $this->scanAt(now()->subMinutes(45));

        $shift = $this->service()->forKit($this->kit);

        $this->assertTrue($shift->started_at->equalTo(now()->subHours(2)));
    }

    #[Group('clarity-scout')]
    public function test_for_kit_splits_on_a_three_hour_internal_gap(): void
    {
        // Early burst, then a 4-hour gap, then the active run.
        $this->scanAt(now()->subHours(6));
        $this->scanAt(now()->subHours(2));
        $this->scanAt(now()->subMinutes(90));

        $shift = $this->service()->forKit($this->kit);

        // The window after the >=3h gap starts at the first post-gap scan.
        $this->assertTrue($shift->started_at->equalTo(now()->subHours(2)));
    }

    #[Group('clarity-scout')]
    public function test_for_kit_starts_now_when_latest_scan_is_stale(): void
    {
        $this->scanAt(now()->subHours(6));
        $this->scanAt(now()->subHours(5));

        $shift = $this->service()->forKit($this->kit);

        $this->assertTrue($shift->started_at->equalTo(now()));
    }

    #[Group('clarity-scout')]
    public function test_for_kit_starts_now_when_there_is_no_recent_activity(): void
    {
        $shift = $this->service()->forKit($this->kit);

        $this->assertTrue($shift->started_at->equalTo(now()));
    }

    #[Group('clarity-scout')]
    public function test_for_kit_clamps_inferred_start_to_the_previous_closed_shift_end(): void
    {
        KitShift::factory()->forKit($this->kit)->confirmed()->create([
            'ended_at' => now()->subHour(),
        ]);

        $this->scanAt(now()->subHours(2));
        $this->scanAt(now()->subMinutes(90));
        $this->scanAt(now()->subMinutes(30));

        $shift = $this->service()->forKit($this->kit);

        $this->assertTrue($shift->started_at->equalTo(now()->subHour()));
    }

    #[Group('clarity-scout')]
    public function test_for_kit_returns_the_existing_open_shift_without_creating_a_second(): void
    {
        $existing = KitShift::factory()->forKit($this->kit)->open()->create([
            'started_at' => now()->subHours(1),
        ]);

        $shift = $this->service()->forKit($this->kit);

        $this->assertSame($existing->id, $shift->id);
        $this->assertSame(1, KitShift::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Group('clarity-scout')]
    public function test_current_open_returns_the_existing_open_shift(): void
    {
        $existing = KitShift::factory()->forKit($this->kit)->open()->create();

        $shift = $this->service()->currentOpenForKit($this->kit);

        $this->assertNotNull($shift);
        $this->assertSame($existing->id, $shift->id);
    }

    #[Group('clarity-scout')]
    public function test_current_open_returns_null_when_no_recent_activity(): void
    {
        $this->assertNull($this->service()->currentOpenForKit($this->kit));
        $this->assertSame(0, KitShift::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Group('clarity-scout')]
    public function test_current_open_opens_a_fresh_shift_after_a_confirmed_end(): void
    {
        // Previous shift closed 5h ago; the driver scans again 30m ago.
        KitShift::factory()->forKit($this->kit)->confirmed()->create([
            'ended_at' => now()->subHours(5),
        ]);
        $this->scanAt(now()->subMinutes(30));

        $shift = $this->service()->currentOpenForKit($this->kit);

        $this->assertNotNull($shift);
        $this->assertNull($shift->ended_at);
        $this->assertTrue($shift->started_at->equalTo(now()->subMinutes(30)));
    }

    #[Group('clarity-scout')]
    public function test_current_open_ignores_activity_before_the_last_closed_shift(): void
    {
        KitShift::factory()->forKit($this->kit)->confirmed()->create([
            'ended_at' => now()->subHour(),
        ]);
        // The only scan predates the close, so it isn't "new" activity.
        $this->scanAt(now()->subHours(2));

        $this->assertNull($this->service()->currentOpenForKit($this->kit));
    }

    #[Group('clarity-scout')]
    public function test_scan_activity_lookups_do_not_join_timezones(): void
    {
        // The new-activity `exists()` check and the started_at inference `pluck()`
        // both read scan history; neither needs the ScanTimezoneScope spatial
        // join, which spatial-joins timezones per row on the zod read-replica.
        $this->scanAt(now()->subMinutes(30));

        DB::connection('zod')->flushQueryLog();
        DB::connection('zod')->enableQueryLog();

        // currentOpenForKit -> hasNewActivity (exists) -> forKit -> inferStartedAt (pluck).
        $this->service()->currentOpenForKit($this->kit);

        $offending = collect(DB::connection('zod')->getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'scanned_at')
                && (stripos($sql, 'timezones') !== false || stripos($sql, 'st_within') !== false));

        $this->assertTrue(
            $offending->isEmpty(),
            'EnsureOpenShift scan-activity lookups should not join timezones; offending: '.$offending->implode(' | '),
        );
    }
}
