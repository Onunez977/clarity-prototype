<?php

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Models\HotlistStatusSource;
use App\Clickhouse\Models\HotlistStatusTransition;
use App\Clickhouse\Queries\Client\BatchMonthlySparklineData;
use App\Clickhouse\Queries\Client\ClientRankedMetricsBatch;
use App\Enums\HotlistStatusEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clickhouse')]
class HotlistStatusTransitionBranchFilterTest extends TestCase
{
    use RequiresClickhouse;

    private int $orgId = 14001;

    private int $peerOrgId = 14002;

    private int $branchA = 101;

    private int $branchB = 202;

    private int $clientX = 501;

    private int $clientY = 502;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();
        // Mid-month UTC so subHours(2|3) from startOfHour never crosses month boundary (#4).
        Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unfiltered_source_stays_on_the_aggregate(): void
    {
        $this->assertFalse(HotlistStatusSource::usesTransitions(null));
        $this->assertFalse(HotlistStatusSource::usesTransitions(0));
        $this->assertSame(
            'uniqMerge(unique_hotlists) AS total',
            HotlistStatusSource::totalExpression(null),
        );
    }

    public function test_branch_filter_counts_exact_transitions_for_that_branch_only(): void
    {
        $now = now()->utc()->startOfHour();
        $this->seedBranchIsolationFixture($now);

        $start = $now->copy()->subDays(2)->toDateTimeString();
        $end = $now->copy()->addHour()->toDateTimeString();

        $branchACount = (int) HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->accepted()
            ->selectRaw(HotlistStatusSource::totalExpression($this->branchA))
            ->value('total');

        $branchBCount = (int) HotlistStatusSource::query($this->branchB)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchB)
            ->accepted()
            ->selectRaw(HotlistStatusSource::totalExpression($this->branchB))
            ->value('total');

        // Exact counts pin NULL/0 branch, deleted, and cross-org rows out of the total.
        $this->assertSame(2, $branchACount);
        $this->assertSame(1, $branchBCount);
        $this->assertTrue(HotlistStatusSource::usesTransitions($this->branchA));
        $this->assertInstanceOf(
            HotlistStatusTransition::class,
            HotlistStatusSource::query($this->branchA)->getModel(),
        );
    }

    public function test_branch_selected_scopes_group_by_client_month_and_select_total(): void
    {
        $now = now()->utc()->startOfHour();
        $this->seedTwoBranchTwoClientFixture($now);

        $start = $now->copy()->subDays(40)->toDateTimeString();
        $end = $now->copy()->addHour()->toDateTimeString();

        $byClient = HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->forClients([$this->clientX, $this->clientY])
            ->openAssignments()
            ->groupByClient()
            ->pluck('total', 'client_org_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->assertSame(2, $byClient[$this->clientX] ?? 0);
        $this->assertSame(1, $byClient[$this->clientY] ?? 0);

        $forClientTotal = (int) HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->forClient($this->clientX)
            ->accepted()
            ->selectTotal()
            ->value('total');

        $this->assertSame(2, $forClientTotal);

        $byMonth = HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->forClient($this->clientX)
            ->acceptedOpen()
            ->byMonth()
            ->pluck('total', 'month')
            ->map(fn ($v) => (int) $v)
            ->all();

        $thisMonth = $now->copy()->startOfMonth()->toDateString();
        $this->assertSame(2, $byMonth[$thisMonth] ?? 0);

        $byClientAndMonth = HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->forClients([$this->clientX, $this->clientY])
            ->openAssignments()
            ->groupByClientAndMonth()
            ->get();

        $grouped = [];
        foreach ($byClientAndMonth as $row) {
            $grouped[(int) $row->client_org_id][(string) $row->month] = (int) $row->total;
        }

        $this->assertSame(2, $grouped[$this->clientX][$thisMonth] ?? 0);
        $this->assertSame(1, $grouped[$this->clientY][$thisMonth] ?? 0);

        // Must fail if HotlistStatusSource::query() is reverted to the aggregate (no rows).
        $this->assertInstanceOf(
            HotlistStatusTransition::class,
            HotlistStatusSource::query($this->branchA)->getModel(),
        );
    }

    public function test_branch_selected_client_ranked_metrics_batch_counts_per_client(): void
    {
        $now = now()->utc()->startOfHour();
        $this->seedTwoBranchTwoClientFixture($now);

        $start = $now->copy()->subDays(40)->toDateTimeString();
        $end = $now->copy()->addHour()->toDateTimeString();

        // handle() — not query() — so a CH failure surfaces instead of ClickhouseQuery's
        // testing-env empty catch.
        $metrics = ClientRankedMetricsBatch::handle([
            'org_id' => $this->orgId,
            'client_org_ids' => [$this->clientX, $this->clientY],
            'start' => $start,
            'end' => $end,
            'timezone' => 'UTC',
            'branch_id' => $this->branchA,
        ]);

        $this->assertSame(2, (int) ($metrics[$this->clientX]['assignments'] ?? 0));
        $this->assertSame(2, (int) ($metrics[$this->clientX]['accepted'] ?? 0));
        $this->assertSame(1, (int) ($metrics[$this->clientX]['repos'] ?? 0));

        $this->assertSame(1, (int) ($metrics[$this->clientY]['assignments'] ?? 0));
        $this->assertSame(1, (int) ($metrics[$this->clientY]['accepted'] ?? 0));
        $this->assertSame(0, (int) ($metrics[$this->clientY]['repos'] ?? 0));

        // Branch B must not leak into branch A batch numbers.
        $branchB = ClientRankedMetricsBatch::handle([
            'org_id' => $this->orgId,
            'client_org_ids' => [$this->clientX, $this->clientY],
            'start' => $start,
            'end' => $end,
            'timezone' => 'UTC',
            'branch_id' => $this->branchB,
        ]);

        $this->assertSame(1, (int) ($branchB[$this->clientX]['assignments'] ?? 0));
        $this->assertSame(0, (int) ($branchB[$this->clientY]['assignments'] ?? 0));
    }

    public function test_branch_selected_batch_monthly_sparkline_counts_per_client_and_month(): void
    {
        $now = Carbon::now('UTC');
        $this->seedTwoBranchTwoClientFixture($now);

        $spark = BatchMonthlySparklineData::handle([
            'org_id' => $this->orgId,
            'client_org_ids' => [$this->clientX, $this->clientY],
            'branch_id' => $this->branchA,
            'months' => 3,
            'timezone' => 'UTC',
        ]);

        $thisMonthIndex = 2; // normalizeData oldest→newest; last slot is current month
        $this->assertSame(2, (int) ($spark[$this->clientX]['assignments'][$thisMonthIndex] ?? 0));
        $this->assertSame(1, (int) ($spark[$this->clientX]['repossessions'][$thisMonthIndex] ?? 0));
        $this->assertSame(1, (int) ($spark[$this->clientY]['assignments'][$thisMonthIndex] ?? 0));
        $this->assertSame(0, (int) ($spark[$this->clientY]['repossessions'][$thisMonthIndex] ?? 0));
    }

    public function test_unfiltered_client_ranked_metrics_batch_handle_with_null_and_zero_branch(): void
    {
        $now = now()->utc()->startOfHour();
        $this->seedUnfilteredAggregateFixture($now);

        $start = $now->copy()->subDays(2)->toDateTimeString();
        $end = $now->copy()->addHour()->toDateTimeString();

        $this->assertFalse(HotlistStatusSource::usesTransitions(null));
        $this->assertFalse(HotlistStatusSource::usesTransitions(0));

        foreach ([null, 0] as $branchId) {
            // handle() — named applyJoin:false on Aggregate must not throw (signature parity).
            $metrics = ClientRankedMetricsBatch::handle([
                'org_id' => $this->orgId,
                'client_org_ids' => [$this->clientX, $this->clientY],
                'start' => $start,
                'end' => $end,
                'timezone' => 'UTC',
                'branch_id' => $branchId,
            ]);

            $this->assertSame(2, (int) ($metrics[$this->clientX]['assignments'] ?? 0), 'branch_id='.var_export($branchId, true));
            $this->assertSame(2, (int) ($metrics[$this->clientX]['accepted'] ?? 0));
            $this->assertSame(1, (int) ($metrics[$this->clientX]['repos'] ?? 0));
            $this->assertSame(1, (int) ($metrics[$this->clientY]['assignments'] ?? 0));
            $this->assertSame(1, (int) ($metrics[$this->clientY]['accepted'] ?? 0));
            $this->assertSame(0, (int) ($metrics[$this->clientY]['repos'] ?? 0));
        }
    }

    public function test_unfiltered_batch_monthly_sparkline_handle_with_null_branch(): void
    {
        $now = Carbon::now('UTC');
        $this->seedUnfilteredAggregateFixture($now);

        $spark = BatchMonthlySparklineData::handle([
            'org_id' => $this->orgId,
            'client_org_ids' => [$this->clientX, $this->clientY],
            'branch_id' => null,
            'months' => 3,
            'timezone' => 'UTC',
        ]);

        $thisMonthIndex = 2; // normalizeData oldest→newest; last slot is current month
        $this->assertSame(2, (int) ($spark[$this->clientX]['assignments'][$thisMonthIndex] ?? 0));
        $this->assertSame(1, (int) ($spark[$this->clientX]['repossessions'][$thisMonthIndex] ?? 0));
        $this->assertSame(1, (int) ($spark[$this->clientY]['assignments'][$thisMonthIndex] ?? 0));
        $this->assertSame(0, (int) ($spark[$this->clientY]['repossessions'][$thisMonthIndex] ?? 0));
    }

    public function test_branch_selected_drilldown_accepted_by_month_for_client(): void
    {
        $now = now()->utc()->startOfHour();
        $this->seedTwoBranchTwoClientFixture($now);

        $start = $now->copy()->subDays(40)->toDateTimeString();
        $end = $now->copy()->addHour()->toDateTimeString();

        // Mirrors HasStatDrillDown::getTotalAcceptedData clientOrgId branch path.
        $monthlyTrend = HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->forClient($this->clientX)
            ->accepted()
            ->byMonth()
            ->get()
            ->map(fn ($row) => [
                'month' => Carbon::parse($row->month)->format('M Y'),
                'value' => (int) $row->total,
            ])
            ->toArray();

        $this->assertNotEmpty($monthlyTrend);
        $this->assertSame(2, (int) $monthlyTrend[0]['value']);

        $chartByMonth = HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->forClient($this->clientX)
            ->acceptedOpen()
            ->byMonth()
            ->pluck('total', 'month')
            ->map(fn ($v) => (int) $v)
            ->all();

        $thisMonth = $now->copy()->startOfMonth()->toDateString();
        $this->assertSame(2, $chartByMonth[$thisMonth] ?? 0);
    }

    public function test_midnight_ended_range_includes_hotlist_created_in_last_hour_bucket(): void
    {
        // end = midnight → last inclusive hour bucket is [end, end+1h). Hotlist created
        // inside that bucket must match transitions toStartOfHour(created_at) <= end.
        $end = Carbon::parse('2026-10-01 00:00:00', 'UTC');
        $inLastHour = $end->copy()->addMinutes(30); // 00:30 — same hour bucket as end
        $start = $end->copy()->subDays(7)->toDateTimeString();
        $endStr = $end->toDateTimeString();
        $created = $inLastHour->toDateTimeString();

        $this->insertHotlist(
            hotlistId: 20,
            orgId: $this->orgId,
            clientOrgId: $this->clientX,
            branchId: $this->branchA,
            createdAt: $created,
            updatedAt: $created,
        );
        $this->insertAcceptedTransition(
            id: 20,
            orgId: $this->orgId,
            hotlistId: 20,
            createdAt: $created,
            syncedAt: $created,
        );

        $count = (int) HotlistStatusSource::query($this->branchA)
            ->forOrg($this->orgId)
            ->between($start, $endStr, 'UTC')
            ->forBranch($this->branchA)
            ->accepted()
            ->selectRaw(HotlistStatusSource::totalExpression($this->branchA))
            ->value('total');

        $this->assertSame(1, $count);
    }

    /**
     * Two accepted on branch A, one on branch B, plus NULL/0/deleted/cross-org distractors.
     */
    private function seedBranchIsolationFixture(Carbon $now): void
    {
        $created = $now->copy()->subDay()->toDateTimeString();
        $updated = $now->toDateTimeString();

        foreach (
            [
                // Real branch A (2) and B (1)
                [1, $this->orgId, $this->clientX, $this->branchA, 0],
                [2, $this->orgId, $this->clientX, $this->branchA, 0],
                [3, $this->orgId, $this->clientX, $this->branchB, 0],
                // NULL branch_id must not count under a real branch
                [4, $this->orgId, $this->clientX, null, 0],
                // branch_id 0 must not count under a real branch
                [5, $this->orgId, $this->clientX, 0, 0],
                // Soft-deleted must not count
                [6, $this->orgId, $this->clientX, $this->branchA, 1],
                // Peer org sharing the same branch id must not leak
                [7, $this->peerOrgId, $this->clientX, $this->branchA, 0],
            ] as [$hotlistId, $orgId, $clientOrgId, $branchId, $deleted]
        ) {
            $this->insertHotlist(
                hotlistId: $hotlistId,
                orgId: $orgId,
                clientOrgId: $clientOrgId,
                branchId: $branchId,
                createdAt: $created,
                updatedAt: $updated,
                deleted: $deleted,
            );
        }

        foreach ([1, 2, 3, 4, 5, 6, 7] as $hotlistId) {
            $orgId = $hotlistId === 7 ? $this->peerOrgId : $this->orgId;
            $this->insertAcceptedTransition(
                id: $hotlistId,
                orgId: $orgId,
                hotlistId: $hotlistId,
                createdAt: $now->copy()->subHours(2)->toDateTimeString(),
                syncedAt: $updated,
            );
        }
    }

    /**
     * Branch A: clientX two open+accepted (+ one repo), clientY one open+accepted.
     * Branch B: clientX one open+accepted (isolation).
     */
    private function seedTwoBranchTwoClientFixture(Carbon $now): void
    {
        $created = $now->copy()->subDay()->toDateTimeString();
        $updated = $now->toDateTimeString();
        $transitionAt = $now->copy()->subHours(3)->toDateTimeString();

        // id 10,11 = clientX branch A open/accepted; 12 = clientX branch A repo
        // id 13 = clientY branch A open/accepted
        // id 14 = clientX branch B open/accepted
        // distractors: NULL branch, deleted, peer org
        foreach (
            [
                [10, $this->orgId, $this->clientX, $this->branchA, 0],
                [11, $this->orgId, $this->clientX, $this->branchA, 0],
                [12, $this->orgId, $this->clientX, $this->branchA, 0],
                [13, $this->orgId, $this->clientY, $this->branchA, 0],
                [14, $this->orgId, $this->clientX, $this->branchB, 0],
                [15, $this->orgId, $this->clientX, null, 0],
                [16, $this->orgId, $this->clientX, $this->branchA, 1],
                [17, $this->peerOrgId, $this->clientX, $this->branchA, 0],
            ] as [$hotlistId, $orgId, $clientOrgId, $branchId, $deleted]
        ) {
            $this->insertHotlist(
                hotlistId: $hotlistId,
                orgId: $orgId,
                clientOrgId: $clientOrgId,
                branchId: $branchId,
                createdAt: $created,
                updatedAt: $updated,
                deleted: $deleted,
            );
        }

        foreach ([10, 11, 13, 14, 15, 16, 17] as $hotlistId) {
            $orgId = $hotlistId === 17 ? $this->peerOrgId : $this->orgId;
            $this->insertAcceptedTransition(
                id: $hotlistId,
                orgId: $orgId,
                hotlistId: $hotlistId,
                createdAt: $transitionAt,
                syncedAt: $updated,
            );
        }

        // Repossession on hotlist 12 (branch A, clientX)
        DB::connection('clarity_clickhouse')->statement(
            'INSERT INTO hotlist_status_transitions
                (id, org_id, hotlist_id, created_at, old_status, new_status, _peerdb_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                12,
                $this->orgId,
                12,
                $transitionAt,
                HotlistStatusEnum::Open->value,
                HotlistStatusEnum::Repossessed->value,
                $updated,
            ]
        );
    }

    /**
     * Aggregate rows for the unfiltered (branch_id null/0) ClientTable path.
     * clientX: 2 open/accepted + 1 repo; clientY: 1 open/accepted.
     */
    private function seedUnfilteredAggregateFixture(Carbon $now): void
    {
        $statusHour = $now->copy()->subHours(2)->toDateTimeString();
        $offset = 9000;

        foreach (
            [
                // clientX — two accepted opens (old Other → Open)
                [$this->clientX, HotlistStatusEnum::Other->value, HotlistStatusEnum::Open->value, 2],
                // clientX — one repossession
                [$this->clientX, HotlistStatusEnum::Open->value, HotlistStatusEnum::Repossessed->value, 1],
                // clientY — one accepted open
                [$this->clientY, HotlistStatusEnum::Other->value, HotlistStatusEnum::Open->value, 1],
            ] as [$clientOrgId, $oldStatus, $newStatus, $unique]
        ) {
            $data = [
                'org_id' => $this->orgId,
                'client_org_id' => $clientOrgId,
                'lienholder_org_id' => 0,
                'branch_id' => 0,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'status_hour' => $statusHour,
                'total_changes' => $unique,
            ];
            $columns = implode(', ', [...array_keys($data), 'unique_hotlists']);
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            DB::connection('clarity_clickhouse')->statement(
                "INSERT INTO hotlist_status_aggregate ({$columns}) SELECT {$placeholders}, uniqState(toInt64(number)) FROM numbers(?, ?)",
                [...array_values($data), $offset, $unique]
            );
            $offset += $unique;
        }
    }

    private function insertHotlist(
        int $hotlistId,
        int $orgId,
        int $clientOrgId,
        ?int $branchId,
        string $createdAt,
        string $updatedAt,
        int $deleted = 0,
    ): void {
        DB::connection('clarity_clickhouse')->statement(
            'INSERT INTO hotlist
                (id, org_id, client_org_id, status, branch_id, is_staged, created_at, updated_at, _peerdb_synced_at, _version, _peerdb_is_deleted)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 1, ?)',
            [
                $hotlistId,
                $orgId,
                $clientOrgId,
                HotlistStatusEnum::Open->value,
                $branchId,
                $createdAt,
                $updatedAt,
                $updatedAt,
                $deleted,
            ]
        );
    }

    private function insertAcceptedTransition(
        int $id,
        int $orgId,
        int $hotlistId,
        string $createdAt,
        string $syncedAt,
    ): void {
        DB::connection('clarity_clickhouse')->statement(
            'INSERT INTO hotlist_status_transitions
                (id, org_id, hotlist_id, created_at, old_status, new_status, _peerdb_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $orgId,
                $hotlistId,
                $createdAt,
                HotlistStatusEnum::Other->value,
                HotlistStatusEnum::Open->value,
                $syncedAt,
            ]
        );
    }
}
