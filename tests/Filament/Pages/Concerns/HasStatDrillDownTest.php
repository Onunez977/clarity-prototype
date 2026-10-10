<?php

namespace Tests\Filament\Pages\Concerns;

use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Clickhouse\Models\HotlistStatusAggregate;
use App\Clickhouse\Models\MissedOpportunity;
use App\Enums\HotlistStatusEnum;
use App\Filament\Pages\Concerns\HasStatDrillDown;
use App\Filament\Widgets\Client\LprAssistsWidget;
use App\Filament\Widgets\Client\MissedOpportunitiesWidget;
use App\Filament\Widgets\Client\TotalAcceptedWidget;
use App\Filament\Widgets\Client\TotalAssignmentsWidget;
use App\Filament\Widgets\Client\TotalClosedWidget;
use App\Filament\Widgets\Client\TotalDeclinedWidget;
use App\Filament\Widgets\Client\TotalRepossessionsWidget;
use App\Models\Org;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('drilldown')]
class HasStatDrillDownTest extends TestCase
{
    use RequiresClickhouse;

    protected User $user;

    protected Org $clientA;

    protected Org $clientB;

    protected Org $clientC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser(
            attributes: ['dti' => ['timezone' => 'UTC']],
            role: RoleEnum::ADMIN->value,
            pro: true,
        );
        $this->actingAs($this->user);

        $this->clientA = Org::factory()->create(['name' => 'Client A']);
        $this->clientB = Org::factory()->create(['name' => 'Client B']);
        $this->clientC = Org::factory()->create(['name' => 'Client C']);

        // getClientNames() (HasStatDrillDown) now sources the client list from
        // Org::getHotlistClients(), which reads ClickHouse — seed the CH
        // `clarity.hotlist` table (not the Postgres model) so each client appears.
        ClickhouseHotlist::factory()->forOrg($this->user->org)->create([
            'client_org_id' => $this->clientA->id,
            'client_org_name' => $this->clientA->name,
        ]);
        ClickhouseHotlist::factory()->forOrg($this->user->org)->create([
            'client_org_id' => $this->clientB->id,
            'client_org_name' => $this->clientB->name,
        ]);
        ClickhouseHotlist::factory()->forOrg($this->user->org)->create([
            'client_org_id' => $this->clientC->id,
            'client_org_name' => $this->clientC->name,
        ]);

        cache()->forget('hotlist-clients:'.$this->user->org_id);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    protected function seedHotlistStatusAggregate(array $rows): void
    {
        $isClickhouse = config('database.connections.clarity_clickhouse.driver') === 'clickhouse';
        static $hotlistOffset = 1;

        foreach ($rows as $row) {
            $data = array_merge([
                'org_id' => $this->user->org_id,
                'client_org_id' => 0,
                'lienholder_org_id' => 0,
                'branch_id' => 0,
                'old_status' => 0,
                'new_status' => 1,
                'status_hour' => now()->toDateTimeString(),
                'unique_hotlists' => 1,
                'total_changes' => 1,
            ], $row);

            if ($isClickhouse) {
                $uniqueHotlists = (int) $data['unique_hotlists'];
                unset($data['unique_hotlists']);

                $columns = implode(', ', [...array_keys($data), 'unique_hotlists']);
                $placeholders = implode(', ', array_fill(0, count($data), '?'));
                $values = array_values($data);

                // Aggregate `unique_hotlists` stores a uniq() state. To simulate N
                // distinct hotlists in this bucket, build the state from N distinct
                // integers via numbers(offset, N). Offset advances globally so states
                // across rows never share ids — uniqMerge across rows sums to the
                // total count of unique hotlists seeded.
                DB::connection('clarity_clickhouse')->statement(
                    "INSERT INTO hotlist_status_aggregate ({$columns}) SELECT {$placeholders}, uniqState(toInt64(number)) FROM numbers(?, ?)",
                    [...$values, $hotlistOffset, $uniqueHotlists]
                );

                $hotlistOffset += $uniqueHotlists;
            } else {
                DB::connection('clarity_clickhouse')
                    ->table('hotlist_status_aggregate')
                    ->insert($data);
            }
        }
    }

    protected function seedMissedOpportunities(array $rows): void
    {
        static $moId = 1;

        foreach ($rows as $row) {
            DB::connection('clarity_clickhouse')
                ->table('missed_opportunities')
                ->insert(array_merge([
                    // `id` must be unique so ReplacingMergeTree ORDER BY
                    // (hotlist_org_id, month, scanned_at, id) doesn't collapse
                    // rows inserted at the same `now()` into a single surviving row.
                    'id' => $moId++,
                    'hotlist_id' => fake()->unique()->randomNumber(5),
                    'hotlist_org_id' => $this->user->org_id,
                    'scanned_at' => now()->toDateTimeString(),
                    'kit_id' => 1,
                    'kit_org_id' => $this->user->org_id,
                    '_peerdb_is_deleted' => 0,
                    '_version' => 1,
                ], $row));
        }
    }

    /**
     * Seed `$count` cohort-transition hotlists for the given client by writing to
     * the two tables the cohort queries read: `clarity.hotlist` (identity) and
     * `zod.public_hotlist_status_log` (status transitions). Each hotlist gets a
     * pair of log rows — one entering Open (new_status=1), one transitioning
     * (new_status=$newStatus, old_status=$oldStatus) — matching the cohort HAVING
     * clause. Use $newStatus=5 for closed cohort, $newStatus=4 for declined cohort.
     */
    protected function seedCohortTransition(int $clientOrgId, int $count, int $oldStatus = 1, int $newStatus = 5): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'clickhouse') {
            return;
        }

        static $hotlistIdCounter = 1_000_000;
        static $logIdCounter = 1;

        $now = now()->toDateTimeString();
        $hotlistRows = [];
        $logRows = [];

        for ($i = 0; $i < $count; $i++) {
            $hotlistId = $hotlistIdCounter++;

            $hotlistRows[] = [
                'id' => $hotlistId,
                'org_id' => $this->user->org_id,
                'client_org_id' => $clientOrgId,
                'branch_id' => 0,
                'status' => $newStatus,
                'is_staged' => false,
                'created_at' => $now,
                'updated_at' => $now,
                '_peerdb_synced_at' => $now,
                '_peerdb_is_deleted' => 0,
                '_version' => 1,
            ];

            $logRows[] = [
                'id' => $logIdCounter++,
                'hotlist_id' => $hotlistId,
                'old_status' => 0,
                'new_status' => 1,
                'created_at' => $now,
                '_peerdb_is_deleted' => 0,
                '_peerdb_version' => 1,
            ];

            $logRows[] = [
                'id' => $logIdCounter++,
                'hotlist_id' => $hotlistId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'created_at' => $now,
                '_peerdb_is_deleted' => 0,
                '_peerdb_version' => 1,
            ];
        }

        // Batch insert (one round-trip per table) instead of 3×$count separate
        // inserts — the clickhouse-eloquent connection opens a fresh TCP/TLS
        // connection per statement, so this is the dominant cost for large
        // noise cohorts (e.g. count: 99). See DEV-2102.
        DB::connection('clarity_clickhouse')->table('hotlist')->insert($hotlistRows);
        DB::connection('clarity_clickhouse')->table('zod.public_hotlist_status_log')->insert($logRows);
    }

    protected function seedClosedCohort(int $clientOrgId, int $count, int $oldStatus = 1): void
    {
        $this->seedCohortTransition($clientOrgId, $count, $oldStatus, newStatus: 5);
    }

    protected function seedDeclinedCohort(int $clientOrgId, int $count, int $oldStatus = 1): void
    {
        $this->seedCohortTransition($clientOrgId, $count, $oldStatus, newStatus: 4);
    }

    /**
     * Create a minimal page instance (uses HasStatDrillDown directly).
     * Avoids Livewire::test() which can roll back clarity_clickhouse savepoints.
     */
    protected function makePageInstance(?int $clientId = null, ?array $batchOverride = null): object
    {
        return new class($clientId, $batchOverride)
        {
            use HasStatDrillDown;

            public ?int $clientId;

            public array $filters = [];

            private ?array $batchOverride;

            public function __construct(?int $clientId, ?array $batchOverride)
            {
                $this->clientId = $clientId;
                $this->batchOverride = $batchOverride;
            }

            protected function getAnalyticsPage(): string
            {
                return 'test';
            }

            protected function fetchBatchMetrics(int $orgId, ?int $branchId): array
            {
                if ($this->batchOverride !== null) {
                    return $this->batchOverride;
                }

                return [$this->getClientNames(), []];
            }

            protected function resolveWidgetValue(string $widgetClass, int $orgId, string $start, string $end, ?int $branchId = null, ?int $clientOrgId = null): mixed
            {
                // When batch override is provided, derive total from batch metrics
                // instead of querying the widget (which has no real data).
                if ($this->batchOverride !== null) {
                    $metricKeyMap = [
                        MissedOpportunitiesWidget::class => 'missed',
                        TotalAssignmentsWidget::class => 'assignments',
                        TotalRepossessionsWidget::class => 'repos',
                        TotalAcceptedWidget::class => 'accepted',
                        TotalClosedWidget::class => 'closed',
                        TotalDeclinedWidget::class => 'declined',
                        LprAssistsWidget::class => 'lpr_assists',
                    ];

                    $key = $metricKeyMap[$widgetClass] ?? null;

                    if ($key) {
                        [, $batchMetrics] = $this->batchOverride;

                        return collect($batchMetrics)->sum(fn ($m) => (int) ($m[$key] ?? 0));
                    }
                }

                try {
                    $widget = new $widgetClass;
                    $widget->org_id = $orgId;
                    $widget->branch_id = $branchId;
                    $widget->client_org_id = $clientOrgId;

                    return $widget->getModelQueryForDates($start, $end);
                } catch (QueryException $e) {
                    // Some widget queries (LPR Assists, Days to Repo) reference tables
                    // without PG polyfills — fall back to 0.
                    return 0;
                }
            }
        };
    }

    protected function buildBatchResult(array $clientMetrics): array
    {
        $clientNames = [];
        $batchMetrics = [];

        foreach ($clientMetrics as [$clientOrg, $metrics]) {
            $clientNames[$clientOrg->id] = $clientOrg->name;
            $batchMetrics[$clientOrg->id] = array_merge([
                'client_org_id' => $clientOrg->id,
                'assignments' => 0,
                'accepted' => 0,
                'closed' => 0,
                'declined' => 0,
                'repos' => 0,
                'missed' => 0,
                'lpr_assists' => 0,
            ], $metrics);
        }

        return [$clientNames, $batchMetrics];
    }

    protected function callMethod(object $instance, string $method): mixed
    {
        return (new \ReflectionMethod($instance, $method))->invoke($instance);
    }

    /**
     * Create the client_days_to_repo_all table in PG for testing.
     * This table has no permanent polyfill in the testing schema.
     */
    protected function createDaysToRepoTable(): void
    {
        // Driver-branch: pgsql polyfill uses integer/timestamp; real CH needs MergeTree.
        if (config('database.connections.clarity_clickhouse.driver') === 'clickhouse') {
            DB::connection('clarity_clickhouse')->statement('
                CREATE TABLE IF NOT EXISTS client_days_to_repo_all (
                    org_id Int32,
                    client_org_id Int32 DEFAULT 0,
                    branch_id Int64 DEFAULT 0,
                    hour_timestamp DateTime,
                    sum_days_to_repo_all Float64 DEFAULT 0,
                    count_repos_all UInt64 DEFAULT 0
                ) ENGINE = MergeTree ORDER BY (org_id, client_org_id, branch_id, hour_timestamp)
            ');

            return;
        }

        DB::connection('clarity_clickhouse')->statement('
            CREATE TABLE IF NOT EXISTS clarity.client_days_to_repo_all (
                org_id integer NOT NULL,
                client_org_id integer NOT NULL DEFAULT 0,
                branch_id bigint NOT NULL DEFAULT 0,
                hour_timestamp timestamp NOT NULL,
                sum_days_to_repo_all numeric NOT NULL DEFAULT 0,
                count_repos_all bigint NOT NULL DEFAULT 0
            )
        ');
    }

    // ──────────────────────────────────────────────
    // Missed Opportunities — Org Drilldown
    // ──────────────────────────────────────────────

    public function test_missed_ops_org_drilldown_uses_correct_metric_key(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['missed' => 3, 'assignments' => 10]],
            [$this->clientB, ['missed' => 2, 'assignments' => 8]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getMissedOpportunitiesData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(3, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(2, $data['clientTable'][1]['value']);
        $this->assertEquals('5', $data['summaryValue']);
        $this->assertEquals('total missed opportunities', $data['summaryLabel']);
    }

    public function test_missed_ops_org_drilldown_filters_zero_values(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['missed' => 3]],
            [$this->clientB, ['missed' => 0]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getMissedOpportunitiesData');

        $this->assertCount(1, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(3, $data['clientTable'][0]['value']);
    }

    public function test_missed_ops_org_drilldown_sorts_descending(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['missed' => 2]],
            [$this->clientB, ['missed' => 5]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getMissedOpportunitiesData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(5, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(2, $data['clientTable'][1]['value']);
    }

    // ──────────────────────────────────────────────
    // Missed Opportunities — Client Drilldown
    // ──────────────────────────────────────────────

    public function test_missed_ops_client_drilldown_returns_correct_total(): void
    {
        $this->seedMissedOpportunities([
            ['hotlist_id' => 100, 'client_org_id' => $this->clientA->id],
            ['hotlist_id' => 101, 'client_org_id' => $this->clientA->id],
            ['hotlist_id' => 102, 'client_org_id' => $this->clientA->id],
            ['hotlist_id' => 100, 'client_org_id' => $this->clientA->id],
        ]);

        $instance = $this->makePageInstance(clientId: $this->clientA->id);
        $data = $this->callMethod($instance, 'getMissedOpportunitiesData');

        $this->assertEquals('3', $data['summaryValue']);
        $this->assertEquals('bg-danger-500', $data['monthlyTrendColor']);
        $this->assertEmpty($data['clientTable']);
        $this->assertEquals('total missed opportunities', $data['summaryLabel']);
    }

    /**
     * Create a page instance that runs the real MissedOpportunity::groupByClient()
     * query (via getMissedMetrics) but stubs assignment/lpr metrics to zero.
     */
    protected function makePageInstanceWithRealMissedBatch(?int $clientId = null): object
    {
        return new class($clientId)
        {
            use HasStatDrillDown;

            public ?int $clientId;

            public array $filters = [];

            public function __construct(?int $clientId)
            {
                $this->clientId = $clientId;
            }

            protected function getAnalyticsPage(): string
            {
                return 'test';
            }

            protected function resolveWidgetValue(string $widgetClass, int $orgId, string $start, string $end, ?int $branchId = null, ?int $clientOrgId = null): mixed
            {
                try {
                    $widget = new $widgetClass;
                    $widget->org_id = $orgId;
                    $widget->branch_id = $branchId;
                    $widget->client_org_id = $clientOrgId;

                    return $widget->getModelQueryForDates($start, $end);
                } catch (QueryException $e) {
                    return 0;
                }
            }

            protected function fetchBatchMetrics(int $orgId, ?int $branchId): array
            {
                $clientNames = $this->getClientNames();
                $clientOrgIds = array_keys($clientNames);

                $timezone = auth()->user()?->timezone ?? 'UTC';
                $dateType = $this->filters['date_toggles'] ?? $this->filters['date_type'] ?? 'this_month';
                $batchDates = $this->getDateRangeForType($dateType, timezone: $timezone);

                $start = $batchDates['start']->copy()->startOfDay()->utc()->toDateTimeString();
                $end = $batchDates['end']->copy()->endOfDay()->utc()->toDateTimeString();

                // Run the real missed metrics query against ClickHouse/PG
                $missedMetrics = MissedOpportunity::query()
                    ->forOrg($orgId)
                    ->between($start, $end)
                    ->forBranch($branchId)
                    ->whereIntegerInRaw('client_org_id', $clientOrgIds)
                    ->groupByClient()
                    ->pluck('total', 'client_org_id')
                    ->toArray();

                // Stub all other metrics to zero
                $batchMetrics = [];
                foreach ($clientOrgIds as $id) {
                    $batchMetrics[$id] = [
                        'client_org_id' => $id,
                        'assignments' => 0,
                        'accepted' => 0,
                        'closed' => 0,
                        'declined' => 0,
                        'repos' => 0,
                        'missed' => $missedMetrics[$id] ?? 0,
                        'lpr_assists' => 0,
                    ];
                }

                return [$clientNames, $batchMetrics];
            }
        };
    }

    // ──────────────────────────────────────────────
    // Missed Opportunities — Org Drilldown (real data)
    // ──────────────────────────────────────────────

    public function test_missed_ops_org_drilldown_matches_batch_query(): void
    {
        // Client A: 3 distinct hotlist_ids
        $this->seedMissedOpportunities([
            ['hotlist_id' => 100, 'client_org_id' => $this->clientA->id],
            ['hotlist_id' => 101, 'client_org_id' => $this->clientA->id],
            ['hotlist_id' => 102, 'client_org_id' => $this->clientA->id],
        ]);

        // Client B: 2 distinct hotlist_ids (200 appears twice, deduped by countDistinct)
        $this->seedMissedOpportunities([
            ['hotlist_id' => 200, 'client_org_id' => $this->clientB->id],
            ['hotlist_id' => 200, 'client_org_id' => $this->clientB->id],
            ['hotlist_id' => 201, 'client_org_id' => $this->clientB->id],
        ]);

        $instance = $this->makePageInstanceWithRealMissedBatch();
        $data = $this->callMethod($instance, 'getMissedOpportunitiesData');

        // Per-client values come from real countDistinct(hotlist_id)
        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(3, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(2, $data['clientTable'][1]['value']);

        // Summary = sum of per-client distinct counts (3 + 2 = 5)
        $this->assertEquals('5', $data['summaryValue']);
        $this->assertEquals('total missed opportunities', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Missed Opportunities — Client Drilldown (real data, distinct count verified)
    // ──────────────────────────────────────────────

    public function test_missed_ops_client_drilldown_deduplicates_hotlist_ids(): void
    {
        // Client B: 3 rows but only 2 distinct hotlist_ids
        $this->seedMissedOpportunities([
            ['hotlist_id' => 300, 'client_org_id' => $this->clientB->id],
            ['hotlist_id' => 300, 'client_org_id' => $this->clientB->id],
            ['hotlist_id' => 301, 'client_org_id' => $this->clientB->id],
        ]);

        // Noise: Client A data should be excluded
        $this->seedMissedOpportunities([
            ['hotlist_id' => 400, 'client_org_id' => $this->clientA->id],
            ['hotlist_id' => 401, 'client_org_id' => $this->clientA->id],
        ]);

        $instance = $this->makePageInstance(clientId: $this->clientB->id);
        $data = $this->callMethod($instance, 'getMissedOpportunitiesData');

        // Widget query: countDistinct(hotlist_id) for client B only = 2
        $this->assertEquals('2', $data['summaryValue']);
        $this->assertEquals('total missed opportunities', $data['summaryLabel']);
        $this->assertEquals('bg-danger-500', $data['monthlyTrendColor']);
        $this->assertEmpty($data['clientTable']);

        // Monthly trend should have entries
        $this->assertNotEmpty($data['monthlyTrend']);
        foreach ($data['monthlyTrend'] as $entry) {
            $this->assertArrayHasKey('month', $entry);
            $this->assertArrayHasKey('value', $entry);
        }
    }

    // ──────────────────────────────────────────────
    // Total Assignments — Org Drilldown
    // ──────────────────────────────────────────────

    public function test_total_assignments_org_drilldown_uses_assignments_key(): void
    {
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'old_status' => 90, 'unique_hotlists' => 3],
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'old_status' => 5, 'unique_hotlists' => 2],
            ['client_org_id' => $this->clientB->id, 'new_status' => 1, 'old_status' => 0, 'unique_hotlists' => 3],
        ]);

        $batch = $this->buildBatchResult([
            [$this->clientA, ['assignments' => 5, 'accepted' => 3]],
            [$this->clientB, ['assignments' => 3, 'accepted' => 3]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalAssignmentsData');

        $clientARow = collect($data['clientTable'])->firstWhere('client_name', $this->clientA->name);
        $clientBRow = collect($data['clientTable'])->firstWhere('client_name', $this->clientB->name);

        $this->assertNotNull($clientARow);
        $this->assertNotNull($clientBRow);
        $this->assertEquals(5, $clientARow['value']);
        $this->assertEquals(3, $clientBRow['value']);
        $this->assertEquals('total assignments', $data['summaryLabel']);
    }

    public function test_total_assignments_org_drilldown_summary_uses_widget_total(): void
    {
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'unique_hotlists' => 5],
            ['client_org_id' => $this->clientB->id, 'new_status' => 1, 'unique_hotlists' => 3],
        ]);

        $batch = $this->buildBatchResult([
            [$this->clientA, ['assignments' => 5]],
            [$this->clientB, ['assignments' => 3]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalAssignmentsData');

        $this->assertEquals('8', $data['summaryValue']);
        $this->assertEquals('total assignments', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Assignments — Client Drilldown
    // ──────────────────────────────────────────────

    public function test_total_assignments_client_drilldown_returns_monthly_trend(): void
    {
        // Seed hotlist_status_aggregate for the summary value (TotalClientAssignmentsWidget queries this)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'unique_hotlists' => 1, 'status_hour' => now()->toDateTimeString()],
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'unique_hotlists' => 1, 'status_hour' => now()->toDateTimeString()],
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'unique_hotlists' => 1, 'status_hour' => now()->toDateTimeString()],
        ]);

        // Seed hotlist for the monthly trend (getAssignmentMonthlyTrend queries this)
        DB::connection('clarity_clickhouse')->table('hotlist')->insert([
            ['org_id' => $this->user->org_id, 'client_org_id' => $this->clientA->id, 'status' => 1, 'created_at' => now()->toDateTimeString(), '_peerdb_synced_at' => now()->toDateTimeString(), '_version' => 1, '_peerdb_is_deleted' => 0],
            ['org_id' => $this->user->org_id, 'client_org_id' => $this->clientA->id, 'status' => 1, 'created_at' => now()->toDateTimeString(), '_peerdb_synced_at' => now()->toDateTimeString(), '_version' => 1, '_peerdb_is_deleted' => 0],
            ['org_id' => $this->user->org_id, 'client_org_id' => $this->clientA->id, 'status' => 1, 'created_at' => now()->toDateTimeString(), '_peerdb_synced_at' => now()->toDateTimeString(), '_version' => 1, '_peerdb_is_deleted' => 0],
        ]);

        $instance = $this->makePageInstance(clientId: $this->clientA->id);
        $data = $this->callMethod($instance, 'getTotalAssignmentsData');

        $this->assertEquals('3', $data['summaryValue']);
        $this->assertEquals('total assignments', $data['summaryLabel']);
        $this->assertEquals('bg-success-500', $data['monthlyTrendColor']);
        $this->assertEmpty($data['clientTable']);

        foreach ($data['monthlyTrend'] as $entry) {
            $this->assertArrayHasKey('month', $entry);
            $this->assertArrayHasKey('value', $entry);
        }
    }

    // ──────────────────────────────────────────────
    // Total Assignments — Filtering & Sorting
    // ──────────────────────────────────────────────

    public function test_total_assignments_org_drilldown_filters_and_sorts(): void
    {
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientB->id, 'new_status' => 1, 'unique_hotlists' => 8],
            ['client_org_id' => $this->clientC->id, 'new_status' => 1, 'unique_hotlists' => 3],
        ]);

        $batch = $this->buildBatchResult([
            [$this->clientA, ['assignments' => 0]],
            [$this->clientB, ['assignments' => 8]],
            [$this->clientC, ['assignments' => 3]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalAssignmentsData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(8, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientC->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(3, $data['clientTable'][1]['value']);
    }

    // ──────────────────────────────────────────────
    // Helper: Real Assignment Batch (HSA-based)
    // ──────────────────────────────────────────────

    /**
     * Create a page instance that runs real HotlistStatusAggregate queries
     * for assignment-related metrics but stubs missed and lpr_assists to 0.
     */
    protected function makePageInstanceWithRealAssignmentBatch(?int $clientId = null): object
    {
        return new class($clientId)
        {
            use HasStatDrillDown;

            public ?int $clientId;

            public array $filters = [];

            public function __construct(?int $clientId)
            {
                $this->clientId = $clientId;
            }

            protected function getAnalyticsPage(): string
            {
                return 'test';
            }

            protected function fetchBatchMetrics(int $orgId, ?int $branchId): array
            {
                $clientNames = $this->getClientNames();
                $clientOrgIds = array_keys($clientNames);

                $timezone = auth()->user()?->timezone ?? 'UTC';
                $dateType = $this->filters['date_toggles'] ?? $this->filters['date_type'] ?? 'this_month';
                $batchDates = $this->getDateRangeForType($dateType, timezone: $timezone);

                $start = $batchDates['start']->copy()->startOfDay()->utc()->toDateTimeString();
                $end = $batchDates['end']->copy()->endOfDay()->utc()->toDateTimeString();

                // Run real HSA queries grouped by client for each metric
                $baseQuery = fn () => HotlistStatusAggregate::query()
                    ->forOrg($orgId)
                    ->between($start, $end)
                    ->forBranch($branchId)
                    ->whereIntegerInRaw('client_org_id', $clientOrgIds);

                $assignments = $baseQuery()->openAssignments()->groupByClient()->pluck('total', 'client_org_id')->toArray();
                $repos = $baseQuery()->repossessions()->groupByClient()->pluck('total', 'client_org_id')->toArray();
                $accepted = $baseQuery()->accepted()->groupByClient()->pluck('total', 'client_org_id')->toArray();
                $closed = $baseQuery()->closedCohort()->groupByClient()->pluck('total', 'client_org_id')->toArray();
                $declined = $baseQuery()->declined()->groupByClient()->pluck('total', 'client_org_id')->toArray();

                $batchMetrics = [];
                foreach ($clientOrgIds as $id) {
                    $batchMetrics[$id] = [
                        'client_org_id' => $id,
                        'assignments' => $assignments[$id] ?? 0,
                        'accepted' => $accepted[$id] ?? 0,
                        'closed' => $closed[$id] ?? 0,
                        'declined' => $declined[$id] ?? 0,
                        'repos' => $repos[$id] ?? 0,
                        'missed' => 0,
                        'lpr_assists' => 0,
                    ];
                }

                return [$clientNames, $batchMetrics];
            }
        };
    }

    // ──────────────────────────────────────────────
    // Total Repossessions — Org Drilldown (mocked)
    // ──────────────────────────────────────────────

    public function test_total_repos_org_drilldown_uses_correct_metric_key(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['repos' => 7, 'assignments' => 10]],
            [$this->clientB, ['repos' => 4, 'assignments' => 8]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalRepossessionsData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(7, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(4, $data['clientTable'][1]['value']);
        $this->assertEquals('total repossessions', $data['summaryLabel']);
    }

    public function test_total_repos_org_drilldown_filters_zero_and_sorts(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['repos' => 2]],
            [$this->clientB, ['repos' => 0]],
            [$this->clientC, ['repos' => 5]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalRepossessionsData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientC->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(5, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(2, $data['clientTable'][1]['value']);
    }

    // ──────────────────────────────────────────────
    // Total Repossessions — Org Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_repos_org_drilldown_matches_real_aggregate_query(): void
    {
        // Client A: 5 repossessions (new_status=3)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 3, 'unique_hotlists' => 3],
            ['client_org_id' => $this->clientA->id, 'new_status' => 3, 'unique_hotlists' => 2],
        ]);

        // Client B: 4 repossessions
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientB->id, 'new_status' => 3, 'unique_hotlists' => 4],
        ]);

        // Noise: non-repo status rows should not count
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'unique_hotlists' => 10],
        ]);

        $instance = $this->makePageInstanceWithRealAssignmentBatch();
        $data = $this->callMethod($instance, 'getTotalRepossessionsData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(5, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(4, $data['clientTable'][1]['value']);
        $this->assertEquals('total repossessions', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Repossessions — Client Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_repos_client_drilldown_returns_monthly_trend(): void
    {
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 3, 'unique_hotlists' => 3],
            ['client_org_id' => $this->clientA->id, 'new_status' => 3, 'unique_hotlists' => 2],
        ]);

        // Noise: different client
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientB->id, 'new_status' => 3, 'unique_hotlists' => 10],
        ]);

        $instance = $this->makePageInstance(clientId: $this->clientA->id);
        $data = $this->callMethod($instance, 'getTotalRepossessionsData');

        $this->assertEquals('5', $data['summaryValue']);
        $this->assertEquals('bg-primary-500', $data['monthlyTrendColor']);
        $this->assertEmpty($data['clientTable']);
        $this->assertEquals('total repossessions', $data['summaryLabel']);

        $this->assertNotEmpty($data['monthlyTrend']);
        foreach ($data['monthlyTrend'] as $entry) {
            $this->assertArrayHasKey('month', $entry);
            $this->assertArrayHasKey('value', $entry);
        }
    }

    // ──────────────────────────────────────────────
    // Total Accepted — Org Drilldown (mocked)
    // ──────────────────────────────────────────────

    public function test_total_accepted_org_drilldown_uses_correct_metric_key(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['accepted' => 6, 'repos' => 2]],
            [$this->clientB, ['accepted' => 3, 'repos' => 1]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalAcceptedData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(6, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(3, $data['clientTable'][1]['value']);
        $this->assertEquals('total accepted', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Accepted — Org Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_accepted_org_drilldown_matches_real_aggregate_query(): void
    {
        // Accepted pattern: new_status=1 (Open), old_status IN (0=Other, 90=NewFromClient)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'old_status' => 0, 'unique_hotlists' => 4],
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'old_status' => 90, 'unique_hotlists' => 2],
        ]);

        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientB->id, 'new_status' => 1, 'old_status' => 0, 'unique_hotlists' => 3],
        ]);

        // Noise: new_status=1 but old_status=5 (not accepted pattern)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'old_status' => 5, 'unique_hotlists' => 100],
        ]);

        $instance = $this->makePageInstanceWithRealAssignmentBatch();
        $data = $this->callMethod($instance, 'getTotalAcceptedData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(6, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(3, $data['clientTable'][1]['value']);
        $this->assertEquals('total accepted', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Accepted — Client Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_accepted_client_drilldown_returns_monthly_trend(): void
    {
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientB->id, 'new_status' => 1, 'old_status' => 0, 'unique_hotlists' => 5],
            ['client_org_id' => $this->clientB->id, 'new_status' => 1, 'old_status' => 90, 'unique_hotlists' => 3],
        ]);

        // Noise: different client
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'old_status' => 0, 'unique_hotlists' => 99],
        ]);

        $instance = $this->makePageInstance(clientId: $this->clientB->id);
        $data = $this->callMethod($instance, 'getTotalAcceptedData');

        $this->assertEquals('8', $data['summaryValue']);
        $this->assertEquals('bg-success-500', $data['monthlyTrendColor']);
        $this->assertEmpty($data['clientTable']);
        $this->assertEquals('total accepted', $data['summaryLabel']);

        $this->assertNotEmpty($data['monthlyTrend']);
        foreach ($data['monthlyTrend'] as $entry) {
            $this->assertArrayHasKey('month', $entry);
            $this->assertArrayHasKey('value', $entry);
        }
    }

    // ──────────────────────────────────────────────
    // Total Accepted — Client Drilldown (branch-selected consumer)
    // ──────────────────────────────────────────────

    public function test_branch_selected_total_accepted_calls_has_stat_drill_down_consumer(): void
    {
        $this->setupRequiresClickhouse();
        Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00', 'UTC'));

        try {
            $branchA = 101;
            $branchB = 202;
            $now = Carbon::now('UTC')->startOfHour();
            $created = $now->copy()->subDay()->toDateTimeString();
            $transitionAt = $now->copy()->subHours(3)->toDateTimeString();
            $updated = $now->toDateTimeString();

            // Two accepted on branch A for clientA; one accepted on branch B (must not leak).
            foreach (
                [
                    [9101, $this->clientA->id, $branchA],
                    [9102, $this->clientA->id, $branchA],
                    [9103, $this->clientA->id, $branchB],
                ] as [$hotlistId, $clientOrgId, $branchId]
            ) {
                DB::connection('clarity_clickhouse')->statement(
                    'INSERT INTO hotlist
                        (id, org_id, client_org_id, status, branch_id, is_staged, created_at, updated_at, _peerdb_synced_at, _version, _peerdb_is_deleted)
                     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 1, 0)',
                    [
                        $hotlistId,
                        $this->user->org_id,
                        $clientOrgId,
                        HotlistStatusEnum::Open->value,
                        $branchId,
                        $created,
                        $updated,
                        $updated,
                    ]
                );
                DB::connection('clarity_clickhouse')->statement(
                    'INSERT INTO hotlist_status_transitions
                        (id, org_id, hotlist_id, created_at, old_status, new_status, _peerdb_synced_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $hotlistId,
                        $this->user->org_id,
                        $hotlistId,
                        $transitionAt,
                        HotlistStatusEnum::Other->value,
                        HotlistStatusEnum::Open->value,
                        $updated,
                    ]
                );
            }

            $instance = $this->makePageInstance(clientId: $this->clientA->id);
            $instance->filters = [
                'branch_id' => $branchA,
                'date_toggles' => 'custom',
                'start' => $now->copy()->subDays(2)->toDateTimeString(),
                'end' => $now->copy()->addHour()->toDateTimeString(),
            ];

            // Must go through HasStatDrillDown::getTotalAcceptedData (not a mirrored chain).
            $data = $this->callMethod($instance, 'getTotalAcceptedData');

            $this->assertSame('2', $data['summaryValue']);
            $this->assertSame('total accepted', $data['summaryLabel']);
            $this->assertNotEmpty($data['monthlyTrend']);
            $this->assertSame(2, (int) $data['monthlyTrend'][0]['value']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ──────────────────────────────────────────────
    // Total Closed — Org Drilldown (mocked)
    // ──────────────────────────────────────────────

    public function test_total_closed_org_drilldown_uses_correct_metric_key(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['closed' => 4]],
            [$this->clientB, ['closed' => 2]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalClosedData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(4, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(2, $data['clientTable'][1]['value']);
        $this->assertEquals('total closed', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Closed — Org Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_closed_org_drilldown_matches_real_aggregate_query(): void
    {
        // Closed-cohort pattern: new_status=5 (Closed), old_status IN (1=Open, 2=Hold)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 5, 'old_status' => 1, 'unique_hotlists' => 3],
            ['client_org_id' => $this->clientA->id, 'new_status' => 5, 'old_status' => 2, 'unique_hotlists' => 1],
        ]);

        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientB->id, 'new_status' => 5, 'old_status' => 1, 'unique_hotlists' => 5],
        ]);

        // Noise: new_status=5 but old_status=0 (outside the closed-cohort pattern)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 5, 'old_status' => 0, 'unique_hotlists' => 100],
        ]);

        $instance = $this->makePageInstanceWithRealAssignmentBatch();
        $data = $this->callMethod($instance, 'getTotalClosedData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(5, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(4, $data['clientTable'][1]['value']);
        $this->assertEquals('total closed', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Closed — Client Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_closed_client_drilldown_returns_monthly_trend(): void
    {
        // TotalClosedWidget routes through CohortClosedQuery, which reads
        // zod.public_hotlist_status_log joined against clarity.hotlist — not
        // hotlist_status_aggregate. Seed the real tables accordingly.
        $this->seedClosedCohort($this->clientA->id, count: 3, oldStatus: 1);
        $this->seedClosedCohort($this->clientA->id, count: 2, oldStatus: 2);

        // Noise: clientB's closed cohort must not bleed into A's drilldown.
        $this->seedClosedCohort($this->clientB->id, count: 99, oldStatus: 1);

        $instance = $this->makePageInstance(clientId: $this->clientA->id);
        $data = $this->callMethod($instance, 'getTotalClosedData');

        $this->assertEquals('5', $data['summaryValue']);
        $this->assertEquals('bg-danger-500', $data['monthlyTrendColor']);
        $this->assertEmpty($data['clientTable']);
        $this->assertEquals('total closed', $data['summaryLabel']);

        $this->assertNotEmpty($data['monthlyTrend']);
        foreach ($data['monthlyTrend'] as $entry) {
            $this->assertArrayHasKey('month', $entry);
            $this->assertArrayHasKey('value', $entry);
        }
    }

    // ──────────────────────────────────────────────
    // Total Declined — Org Drilldown (mocked)
    // ──────────────────────────────────────────────

    public function test_total_declined_org_drilldown_uses_correct_metric_key(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['declined' => 4]],
            [$this->clientB, ['declined' => 2]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getTotalDeclinedData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(4, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(2, $data['clientTable'][1]['value']);
        $this->assertEquals('total declined', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Declined — Org Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_declined_org_drilldown_matches_real_aggregate_query(): void
    {
        // Declined-cohort pattern: new_status=4, old_status IN (1=Open, 2=Hold)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 4, 'old_status' => 1, 'unique_hotlists' => 3],
            ['client_org_id' => $this->clientA->id, 'new_status' => 4, 'old_status' => 2, 'unique_hotlists' => 1],
        ]);

        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientB->id, 'new_status' => 4, 'old_status' => 1, 'unique_hotlists' => 5],
        ]);

        // Noise: new_status=4 but old_status=0 (outside declined-cohort pattern)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 4, 'old_status' => 0, 'unique_hotlists' => 100],
        ]);

        $instance = $this->makePageInstanceWithRealAssignmentBatch();
        $data = $this->callMethod($instance, 'getTotalDeclinedData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(5, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(4, $data['clientTable'][1]['value']);
        $this->assertEquals('total declined', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Total Declined — Client Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_total_declined_client_drilldown_returns_monthly_trend(): void
    {
        // TotalDeclinedWidget routes through CohortDeclinedQuery, which reads
        // zod.public_hotlist_status_log joined against clarity.hotlist — not
        // hotlist_status_aggregate. Seed the real tables accordingly.
        $this->seedDeclinedCohort($this->clientA->id, count: 3, oldStatus: 1);
        $this->seedDeclinedCohort($this->clientA->id, count: 2, oldStatus: 2);

        // Noise: clientB's declined cohort must not bleed into A's drilldown.
        $this->seedDeclinedCohort($this->clientB->id, count: 99, oldStatus: 1);

        $instance = $this->makePageInstance(clientId: $this->clientA->id);
        $data = $this->callMethod($instance, 'getTotalDeclinedData');

        $this->assertEquals('5', $data['summaryValue']);
        $this->assertEquals('bg-danger-500', $data['monthlyTrendColor']);
        $this->assertEmpty($data['clientTable']);
        $this->assertEquals('total declined', $data['summaryLabel']);

        $this->assertNotEmpty($data['monthlyTrend']);
        foreach ($data['monthlyTrend'] as $entry) {
            $this->assertArrayHasKey('month', $entry);
            $this->assertArrayHasKey('value', $entry);
        }
    }

    // ──────────────────────────────────────────────
    // LPR Assists — Org Drilldown (mocked, no test table)
    // ──────────────────────────────────────────────

    public function test_lpr_assists_org_drilldown_uses_correct_metric_key(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['lpr_assists' => 8]],
            [$this->clientB, ['lpr_assists' => 3]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getLprAssistsData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(8, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(3, $data['clientTable'][1]['value']);
        $this->assertEquals('total LPR assists', $data['summaryLabel']);
    }

    public function test_lpr_assists_org_drilldown_filters_zero_and_sorts(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['lpr_assists' => 2]],
            [$this->clientB, ['lpr_assists' => 0]],
            [$this->clientC, ['lpr_assists' => 7]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getLprAssistsData');

        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientC->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(7, $data['clientTable'][0]['value']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(2, $data['clientTable'][1]['value']);
    }

    // ──────────────────────────────────────────────
    // Avg Recovery Rate — Org Drilldown (mocked)
    // ──────────────────────────────────────────────

    public function test_avg_recovery_rate_org_drilldown_calculates_rates(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['assignments' => 100, 'repos' => 25]],
            [$this->clientB, ['assignments' => 50, 'repos' => 30]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getAvgRecoveryRateData');

        // Client B has higher rate (60%) than Client A (25%), sorted desc
        $this->assertCount(2, $data['clientTable']);
        $this->assertEquals($this->clientB->name, $data['clientTable'][0]['client_name']);
        $this->assertEquals(60.0, $data['clientTable'][0]['rate']);
        $this->assertEquals(50, $data['clientTable'][0]['assignments']);
        $this->assertEquals(30, $data['clientTable'][0]['repossessions']);
        $this->assertEquals($this->clientA->name, $data['clientTable'][1]['client_name']);
        $this->assertEquals(25.0, $data['clientTable'][1]['rate']);

        // Overall rate: 55/150 = 36.7%
        $this->assertEquals('36.7%', $data['summaryValue']);
        $this->assertEquals('overall recovery rate', $data['summaryLabel']);
    }

    public function test_avg_recovery_rate_org_drilldown_excludes_zero_activity(): void
    {
        $batch = $this->buildBatchResult([
            [$this->clientA, ['assignments' => 50, 'repos' => 10]],
            [$this->clientB, ['assignments' => 0, 'repos' => 0]],
            [$this->clientC, ['assignments' => 20, 'repos' => 5]],
        ]);

        $instance = $this->makePageInstance(batchOverride: $batch);
        $data = $this->callMethod($instance, 'getAvgRecoveryRateData');

        // Client B (0 assignments, 0 repos) should be excluded
        $this->assertCount(2, $data['clientTable']);
        $clientNames = array_column($data['clientTable'], 'client_name');
        $this->assertNotContains($this->clientB->name, $clientNames);
    }

    // ──────────────────────────────────────────────
    // Avg Recovery Rate — Client Drilldown (real HSA data)
    // ──────────────────────────────────────────────

    public function test_avg_recovery_rate_client_drilldown_shows_bar_chart(): void
    {
        // Seed open assignments for client A: new_status=1 (Open)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'unique_hotlists' => 10],
            ['client_org_id' => $this->clientA->id, 'new_status' => 1, 'unique_hotlists' => 10],
        ]);

        // Seed repossessions for client A: new_status=3 (Repossessed)
        $this->seedHotlistStatusAggregate([
            ['client_org_id' => $this->clientA->id, 'new_status' => 3, 'unique_hotlists' => 5],
        ]);

        $instance = $this->makePageInstance(clientId: $this->clientA->id);
        $data = $this->callMethod($instance, 'getAvgRecoveryRateData');

        // barChart should have 3 entries
        $this->assertCount(3, $data['barChart']);
        $this->assertEquals('Total Assignments', $data['barChart'][0]['label']);
        $this->assertEquals(20, $data['barChart'][0]['value']);
        $this->assertEquals('Total Repossessions', $data['barChart'][1]['label']);
        $this->assertEquals(5, $data['barChart'][1]['value']);
        $this->assertEquals('Recovery Rate', $data['barChart'][2]['label']);
        $this->assertEquals('25%', $data['barChart'][2]['value']);

        $this->assertEquals('25%', $data['summaryValue']);
        $this->assertEquals('recovery rate', $data['summaryLabel']);
        $this->assertEmpty($data['clientTable']);
    }

    // ──────────────────────────────────────────────
    // Days to Repo — Org Drilldown (structure test, mock)
    // ──────────────────────────────────────────────

    public function test_days_to_repo_org_drilldown_structure(): void
    {
        $this->createDaysToRepoTable();

        $instance = $this->makePageInstance();
        $data = $this->callMethod($instance, 'getDaysToRepoData');

        $this->assertArrayHasKey('dates', $data);
        $this->assertArrayHasKey('barChart', $data);
        $this->assertArrayHasKey('monthlyTrend', $data);
        $this->assertArrayHasKey('clientTable', $data);
        $this->assertArrayHasKey('clientTableColumns', $data);
        $this->assertArrayHasKey('summaryValue', $data);
        $this->assertArrayHasKey('summaryLabel', $data);
        $this->assertEquals('avg days to repo', $data['summaryLabel']);
    }

    // ──────────────────────────────────────────────
    // Days to Repo — Client Drilldown (structure test, mock)
    // ──────────────────────────────────────────────

    public function test_days_to_repo_client_drilldown_structure(): void
    {
        $this->createDaysToRepoTable();

        $instance = $this->makePageInstance(clientId: $this->clientA->id);
        $data = $this->callMethod($instance, 'getDaysToRepoData');

        $this->assertArrayHasKey('dates', $data);
        $this->assertArrayHasKey('barChart', $data);
        $this->assertArrayHasKey('monthlyTrend', $data);
        $this->assertArrayHasKey('monthlyTrendColor', $data);
        $this->assertArrayHasKey('clientTable', $data);
        $this->assertArrayHasKey('summaryValue', $data);
        $this->assertArrayHasKey('summaryLabel', $data);
        $this->assertEquals('bg-warning-500', $data['monthlyTrendColor']);
        $this->assertEquals('avg days to repo', $data['summaryLabel']);
        $this->assertEmpty($data['clientTable']);
    }
}
