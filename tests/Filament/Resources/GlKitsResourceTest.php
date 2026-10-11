<?php

namespace Tests\Filament\Resources;

use App\Filament\Resources\GlKitsResource\Pages\ListGlKits;
use App\Models\Kit;
use App\Models\Org;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\ResourceTestCase;

/**
 * The GL Kits guarantees that need ClickHouse. Rows are keyed by GL name, not by
 * kit id, so records are addressed by that string — Filament treats a non-Model,
 * non-array record argument as a literal key.
 *
 * The ClickHouse-free authorization and tenancy guarantees live in
 * {@see GlKitsResourceAccessTest}.
 */
class GlKitsResourceTest extends ResourceTestCase
{
    use RequiresClickhouse;

    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * AC1/AC5: kit-to-GL is many-to-many, and the whole point of DEV-2486 is that
     * two kit users sharing one camera are one row, not two.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_one_row_per_gl_even_when_two_kits_share_it(): void
    {
        $a = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $b = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        $this->insertCameraName($a->id, 'GL9001');
        $this->insertCameraName($b->id, 'GL9001');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords(['GL9001']);
    }

    /** AC1: the Kit column is gone. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_the_kit_column_is_no_longer_present(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($kit->id, 'GL9001');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnDoesNotExist('name')
            ->assertTableColumnExists('gl');
    }

    /** AC5: newest scan across the GL's kits wins, not the first one found. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_gl_rolls_up_to_the_newest_last_scan_across_its_kits(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $older = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $newer = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        $this->insertCameraName($older->id, 'GL9001');
        $this->insertCameraName($newer->id, 'GL9001');

        // Explicit UTC instants, NOT now()->subHours(): now() re-expresses testNow in
        // APP_TIMEZONE, and these are written into a column the resource parses as UTC.
        // Relative to the frozen (real) now, so nothing rots and nothing depends on APP_TIMEZONE:
        // ->utc() is what makes these safe, not an absolute literal.
        $this->insertLastScan($this->user->org_id, $older->id, now()->utc()->subDays(11));
        $newest = now()->utc()->subHours(3);
        $this->insertLastScan($this->user->org_id, $newer->id, $newest);

        $expected = $newest->copy()
            ->timezone($this->user->timezone)
            ->format(config('app.date_format'));

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('last_scan', $expected, 'GL9001');
    }

    /** AC5: any kit reporting makes the whole GL Reporting. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_gl_reports_if_any_of_its_kits_reports(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $silent = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => 'GL-SILENT-01',
        ]);
        $talking = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => 'GL-TALKING-01',
        ]);

        $this->insertCameraName($silent->id, 'GL9001');
        $this->insertCameraName($talking->id, 'GL9001');
        $this->insertGpsFix('GL-TALKING-01');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('reporting', 'Reporting', 'GL9001');
    }

    /**
     * A GL mixing a definitively-checked kit with an unmapped one renders
     * "Not Reporting": one of its kits WAS checked and came back negative, so the
     * page is not claiming a negative it never established.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_gl_mixing_not_reporting_and_unknown_renders_not_reporting(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $checked = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => 'GL-CHECKED-01',
        ]);
        $unmapped = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => '',
        ]);

        $this->insertCameraName($checked->id, 'GL9001');
        $this->insertCameraName($unmapped->id, 'GL9001');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('reporting', 'Not Reporting', 'GL9001');
    }

    /** AC6: a GL whose kits have no scan rows reads Never, not Unknown. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_gl_with_no_scans_renders_never(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($kit->id, 'GL9001');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('last_scan', 'Never', 'GL9001');
    }

    /**
     * AC6: a GL whose only kit has no scanner mapping was never sent to ClickHouse,
     * so "Not Reporting" would be a claim about a lookup that never happened.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_gl_whose_only_kit_has_no_mapping_renders_unknown(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => '',
        ]);
        $this->insertCameraName($kit->id, 'GL9001');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('reporting', 'Unknown', 'GL9001');
    }

    /**
     * `zod.kit_last_scan`'s sort key is (org_id, kit_id), so a kit that changed
     * orgs keeps a stale row under its former org. A row filed under a different
     * org_id must never surface as this GL's last scan.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_last_scan_row_under_a_different_org_does_not_surface(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($kit->id, 'GL9001');

        $otherOrg = Org::factory()->create();
        $this->insertLastScan($otherOrg->id, $kit->id, now());

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('last_scan', 'Never', 'GL9001');
    }

    /** AC3: ascending puts real timestamps before Never; descending reverses it. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_last_scan_sorts_ascending_and_descending(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $recent = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $old = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $never = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        $this->insertCameraName($recent->id, 'GL-RECENT');
        $this->insertCameraName($old->id, 'GL-OLD');
        $this->insertCameraName($never->id, 'GL-NEVER');

        $this->insertLastScan($this->user->org_id, $recent->id, now()->utc()->subHours(3));
        $this->insertLastScan($this->user->org_id, $old->id, now()->utc()->subDays(30));

        Livewire::test(ListGlKits::class)
            ->sortTable('last_scan')
            ->assertCanSeeTableRecords(['GL-OLD', 'GL-RECENT', 'GL-NEVER'], inOrder: true)
            ->sortTable('last_scan', 'desc')
            ->assertCanSeeTableRecords(['GL-NEVER', 'GL-RECENT', 'GL-OLD'], inOrder: true);
    }

    /** AC3 + the tri-state rank: ascending shows the best-known state first. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_reporting_sorts_best_known_first(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $reporting = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => 'GL-REP-01',
        ]);
        $notReporting = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => 'GL-NOTREP-01',
        ]);
        $unknown = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => '',
        ]);

        // Names chosen so ALPHABETICAL order (ZED, ABEL, MID) differs from RANK order
        // (Reporting, Not Reporting, Unknown). With matching orders this test passes
        // even when the sort is removed entirely.
        $this->insertCameraName($reporting->id, 'GL-ZED-REPORTING');
        $this->insertCameraName($notReporting->id, 'GL-ABEL-NOTREPORTING');
        $this->insertCameraName($unknown->id, 'GL-MID-UNKNOWN');
        $this->insertGpsFix('GL-REP-01');

        Livewire::test(ListGlKits::class)
            ->sortTable('reporting')
            ->assertCanSeeTableRecords(
                ['GL-ZED-REPORTING', 'GL-ABEL-NOTREPORTING', 'GL-MID-UNKNOWN'],
                inOrder: true
            )
            ->sortTable('reporting', 'desc')
            ->assertCanSeeTableRecords(
                ['GL-MID-UNKNOWN', 'GL-ABEL-NOTREPORTING', 'GL-ZED-REPORTING'],
                inOrder: true
            );
    }

    /** AC4: this table defaults to 100 rows, not the app-wide 50. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_the_table_defaults_to_one_hundred_rows_per_page(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($kit->id, 'GL9001');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertSet('tableRecordsPerPage', 100);
    }

    /** The GL column stays searchable, matching the Kit column it replaced. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_the_gl_column_is_searchable(): void
    {
        $wanted = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $other = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        $this->insertCameraName($wanted->id, 'GL9001');
        $this->insertCameraName($other->id, 'GL7777');

        Livewire::test(ListGlKits::class)
            ->searchTable('GL9001')
            ->assertCanSeeTableRecords(['GL9001'])
            ->assertCanNotSeeTableRecords(['GL7777'])
            ->assertCountTableRecords(1);
    }

    /**
     * Moved here from GlKitsResourceAccessTest: asserting which rows RENDER now
     * needs ClickHouse, because row identity comes from it.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_another_orgs_gl_never_appears(): void
    {
        $mine = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($mine->id, 'GL-MINE');

        $otherOrg = Org::factory()->create();
        $theirs = Kit::factory()->forOrg($otherOrg)->create(['kit_type' => 1]);
        $this->insertCameraName($theirs->id, 'GL-THEIRS');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(['GL-MINE'])
            ->assertCanNotSeeTableRecords(['GL-THEIRS']);
    }

    /** Moved here from the access test, for the same reason. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_only_gl_kit_type_kits_contribute_rows(): void
    {
        $gl = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $matrix = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 2]);

        $this->insertCameraName($gl->id, 'GL-GENETEC');
        $this->insertCameraName($matrix->id, 'GL-MATRIX');

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(['GL-GENETEC'])
            ->assertCanNotSeeTableRecords(['GL-MATRIX']);
    }

    /** Moved here from the access test, for the same reason. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_soft_deleted_kits_gl_does_not_appear(): void
    {
        $active = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $deleted = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        $this->insertCameraName($active->id, 'GL-ACTIVE');
        $this->insertCameraName($deleted->id, 'GL-DELETED');

        DB::connection('zod')->table('kit_metadata')->insert([
            'kit_id' => $deleted->id,
            'deleted_at' => now(),
        ]);

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(['GL-ACTIVE'])
            ->assertCanNotSeeTableRecords(['GL-DELETED']);
    }

    /**
     * Pins the per-org memo keying across all THREE batch helpers, including the
     * new GL map. `once()` folds a closure's captured variables into its hash, so
     * each helper must capture $orgId — otherwise the FIRST org resolved in a
     * process wins and every later org is served its map. Deleting `use ($orgId)`
     * makes this fail; without it the suite stays green and the leak is invisible.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_two_orgs_rendered_in_one_process_do_not_share_a_memoized_map(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $mine = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($mine->id, 'GL-TENANT-A');
        $this->insertLastScan($this->user->org_id, $mine->id, now()->utc()->subHours(3));

        // Render org A FIRST so its map is the one sitting in the memo.
        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(['GL-TENANT-A']);

        // Now a second org in the SAME process. createUser() re-points the permissions
        // team id, so it must come after org A's render and before acting as org B.
        $otherUser = $this->createUser(role: RoleEnum::ADMIN->value);
        $theirs = Kit::factory()->forOrg($otherUser->org)->create(['kit_type' => 1]);
        $theirScan = now()->utc()->subDays(2);
        $this->insertCameraName($theirs->id, 'GL-TENANT-B');
        $this->insertLastScan($otherUser->org_id, $theirs->id, $theirScan);

        $this->actingAs($otherUser);
        setPermissionsTeamId($otherUser->org_id);

        // An unkeyed memo would replay org A's GL map and last-scan map here.
        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(['GL-TENANT-B'])
            ->assertCanNotSeeTableRecords(['GL-TENANT-A'])
            ->assertTableColumnStateSet(
                'last_scan',
                $theirScan->copy()
                    ->timezone($otherUser->timezone)
                    ->format(config('app.date_format')),
                'GL-TENANT-B'
            );
    }

    /**
     * DEV-2486's accepted trade-off, pinned as a test rather than left as a
     * surprise: row identity now comes from ClickHouse, so a failed GL lookup
     * renders an EMPTY TABLE — it cannot degrade each row to "Unknown" the way the
     * PostgreSQL-backed version did, because there are no rows. The failure must
     * still reach error tracking.
     *
     * Do NOT set `app.env` here. Leaving it at 'testing' is what makes this test
     * catch a regression to `ClickhouseQuery::query()`, whose own catch swallows to
     * [] in that env — which would produce the same empty table with no log line.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_gl_discovery_failure_renders_zero_rows_and_is_reported(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($kit->id, 'GL9001');

        $this->breakClickhouseConnection('public_clickhouse');

        Log::spy();

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCountTableRecords(0);

        Log::shouldHaveReceived('warning');
    }

    /**
     * The surviving per-lookup "Unknown on a rendered row" case. GL discovery and
     * `zod.kit_last_scan` share the `public_clickhouse` connection, so a last-scan-only
     * failure is no longer reachable by breaking a connection — breaking it empties
     * the table instead. Reporting reads `clarity.sc_positions` on the other
     * connection, so it can still fail alone, and must render "Unknown" rather than
     * collapse into a false "Not Reporting".
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_a_reporting_read_failure_renders_unknown_not_not_reporting(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1,
            'kit_mapping' => 'GL-REPORTING-01',
        ]);
        $this->insertCameraName($kit->id, 'GL9001');

        $this->breakClickhouseConnection('clarity_clickhouse');

        Log::spy();

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('reporting', 'Unknown', 'GL9001')
            ->assertTableColumnStateSet('last_scan', 'Never', 'GL9001');

        Log::shouldHaveReceived('warning')->once();
    }

    private function breakClickhouseConnection(string $connection): void
    {
        config([
            "database.connections.{$connection}.driver" => 'clickhouse',
            "database.connections.{$connection}.host" => '127.0.0.1',
            "database.connections.{$connection}.port" => '1',
            "database.connections.{$connection}.https" => false,
            "database.connections.{$connection}.options" => ['protocol' => 'http'],
            "database.connections.{$connection}.timeout_connect" => 1,
            "database.connections.{$connection}.timeout_query" => 1,
            "database.connections.{$connection}.retries" => 0,
        ]);

        DB::purge($connection);
    }

    private function insertCameraName(int $kitId, string $cameraName): void
    {
        DB::connection('public_clickhouse')->table('aggregate_kit_camera_name')->insert([
            'kit_id' => $kitId,
            'camera_name' => $cameraName,
        ]);
    }

    private function insertGpsFix(string $kitMapping): void
    {
        DB::connection('clarity_clickhouse')->table('sc_positions')->insert([
            'kit_mapping' => $kitMapping,
            'geo' => '(-97.74,30.27)',
            'timestamp_utc' => now()->subDay()->utc()->format('Y-m-d H:i:s'),
            'speed' => 12.5,
            'heading' => 90.0,
        ]);
    }

    /**
     * AC4 + the hand-rolled paginator. Nothing else in this class exceeds one page, so without
     * this an off-by-one-page in the slice() arithmetic ships green: page 1 would silently start
     * at row 101.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_pagination_slices_the_right_rows_on_each_page(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        for ($i = 1; $i <= 105; $i++) {
            $this->insertCameraName($kit->id, sprintf('GL%04d', $i));
        }

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCountTableRecords(105)
            ->assertCanSeeTableRecords(['GL0001', 'GL0100'])
            ->assertCanNotSeeTableRecords(['GL0101'])
            ->call('setPage', 2)
            ->assertCanSeeTableRecords(['GL0101', 'GL0105'])
            ->assertCanNotSeeTableRecords(['GL0001', 'GL0100']);
    }

    /**
     * Moving off Eloquent gave up Paginator::isValidPageNumber(), which used to sanitise this.
     * An unclamped page 0 becomes a NEGATIVE slice offset, which serves the LAST page's rows
     * under a "page 1" footer — wrong rows, no error. A non-numeric page would TypeError.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_an_out_of_range_page_falls_back_to_the_first_page(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        for ($i = 1; $i <= 105; $i++) {
            $this->insertCameraName($kit->id, sprintf('GL%04d', $i));
        }

        foreach ([0, -3, 'abc'] as $page) {
            Livewire::test(ListGlKits::class)
                ->set('paginators.page', $page)
                ->assertSuccessful()
                ->assertCanSeeTableRecords(['GL0001'])
                ->assertCanNotSeeTableRecords(['GL0105']);
        }
    }

    /**
     * Digit-leading camera names are admissible (the GL set is deliberately un-filtered by
     * pattern), and PHP's `<=>` compares two numeric strings NUMERICALLY while falling back to
     * strcmp for mixed pairs — not a total order. Under the default SORT_REGULAR the same GLs
     * re-sort differently as the ClickHouse row order changes.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_digit_leading_gl_names_sort_as_strings_not_numbers(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        foreach (['999', '9', '10', 'Clone'] as $name) {
            $this->insertCameraName($kit->id, $name);
        }

        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(['10', '9', '999', 'Clone'], inOrder: true);
    }

    /**
     * The tri-state is the subtlest thing on this page, and "Unknown" vs "Not Reporting" is not
     * guessable from the badge alone, so each state carries a tooltip explaining it. Pinned
     * because a silently-dropped tooltip looks identical to a working page.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_each_reporting_state_carries_an_explanatory_tooltip(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $reporting = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1, 'kit_mapping' => 'GL-TIP-REP',
        ]);
        $notReporting = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1, 'kit_mapping' => 'GL-TIP-NOTREP',
        ]);
        $unknown = Kit::factory()->forOrg($this->user->org)->create([
            'kit_type' => 1, 'kit_mapping' => '',
        ]);

        $this->insertCameraName($reporting->id, 'GL-TIP-A');
        $this->insertCameraName($notReporting->id, 'GL-TIP-B');
        $this->insertCameraName($unknown->id, 'GL-TIP-C');
        $this->insertGpsFix('GL-TIP-REP');

        $column = Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->instance()
            ->getTable()
            ->getColumn('reporting');

        $this->assertStringContainsString('last 14 days', $column->getTooltip('Reporting'));
        $this->assertStringContainsString('Every kit user', $column->getTooltip('Not Reporting'));

        // Config alone is not enough — TextColumn only emits x-tooltip when filled($tooltip),
        // so assert the RENDERED markup actually carries it for each state.
        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertSee('At least one kit user on this GL', escape: false)
            ->assertSee('Every kit user on this GL was checked', escape: false)
            ->assertSee('No scanner mapping to look up', escape: false);

        // The load-bearing one: Unknown must say WHY it is not "Not Reporting".
        $unknownTip = $column->getTooltip('Unknown');
        $this->assertStringContainsString('scanner mapping', $unknownTip);
        $this->assertStringContainsString('lookup failed', $unknownTip);
        $this->assertStringContainsString('Not Reporting', $unknownTip);

        $this->assertNotEmpty($column->getHeaderTooltip());
    }

    /** Never and Unknown mean different things in the Last Scan column too. */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_last_scan_sentinels_carry_tooltips_but_real_timestamps_do_not(): void
    {
        Carbon::setTestNow(now()->startOfHour());

        $kit = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $this->insertCameraName($kit->id, 'GL-TIP-D');
        $this->insertLastScan($this->user->org_id, $kit->id, now()->utc()->subHours(2));

        $column = Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->instance()
            ->getTable()
            ->getColumn('last_scan');

        $this->assertStringContainsString('No scan has ever been recorded', $column->getTooltip('Never'));
        $this->assertStringContainsString('does not mean', $column->getTooltip('Unknown'));

        // And that the header tooltip reaches the markup (index.blade.php renders that one).
        Livewire::test(ListGlKits::class)
            ->assertSuccessful()
            ->assertSee('The most recent scan across every kit user', escape: false);

        // A real timestamp needs no explanation, so it must render no tooltip at all.
        $this->assertNull($column->getTooltip('Sep 4, 2026 10:06 AM CDT'));

        $this->assertNotEmpty($column->getHeaderTooltip());
    }

    private function insertLastScan(int $orgId, int $kitId, Carbon $lastScan): void
    {
        DB::connection('public_clickhouse')->table('kit_last_scan')->insert([
            'org_id' => $orgId,
            'org_name' => 'Org '.$orgId,
            'kit_id' => $kitId,
            'kit_name' => 'Kit '.$kitId,
            'last_scan' => $lastScan->copy()->utc()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
