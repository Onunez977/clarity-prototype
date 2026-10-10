<?php

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Models\LprAssist;
use App\Clickhouse\Models\MissedOpportunity;
use App\Clickhouse\Queries\Client\BatchMonthlySparklineData;
use App\Clickhouse\Queries\Client\ClientRankedMetricsBatch;
use App\Enums\HotlistStatusEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * With a branch selected, LPR Assists and Missed Opportunities count rows whose hotlist
 * CURRENTLY has that branch (clarity.hotlist FINAL), not the branch_id copied onto the
 * recovery / missed-opportunity row when it was written. Unfiltered SQL is unchanged.
 */
#[Group('clickhouse')]
class LprAssistMissedOpportunityBranchFilterTest extends TestCase
{
    use RequiresClickhouse;

    private int $orgId = 29281;

    private int $peerOrgId = 29282;

    private int $branchA = 101;

    private int $branchB = 202;

    private int $clientX = 501;

    private int $clientY = 502;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();
        Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_lpr_assists_filter_on_the_hotlists_current_branch(): void
    {
        [$start, $end] = $this->window();

        $this->assertSame(2, LprAssist::getTotal($this->orgId, $start, $end, 'UTC', $this->branchA));
        $this->assertSame(1, LprAssist::getTotal($this->orgId, $start, $end, 'UTC', $this->branchB));

        $byClient = LprAssist::groupedByClientIds($this->orgId, [$this->clientX, $this->clientY], $start, $end, 'UTC', $this->branchA);
        $this->assertSame([$this->clientX => 1, $this->clientY => 1], $this->ints($byClient));

        $byMonth = LprAssist::byMonth($this->orgId, $start, $end, 'UTC', $this->branchA);
        $this->assertSame(['2026-10-01' => 2], $this->ints($byMonth));

        $byClientAndMonth = LprAssist::byClientAndMonth($this->orgId, [$this->clientX, $this->clientY], $start, $end, 'UTC', $this->branchA);
        $this->assertSame(1, (int) ($byClientAndMonth[$this->clientX]['2026-10-01'] ?? 0));
        $this->assertSame(1, (int) ($byClientAndMonth[$this->clientY]['2026-10-01'] ?? 0));
    }

    public function test_missed_opportunities_filter_on_the_hotlists_current_branch(): void
    {
        [$start, $end] = $this->window();

        $total = fn (int $branchId): int => (int) MissedOpportunity::query()
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($branchId)
            ->forClient(null)
            ->selectTotal()
            ->value('total');

        $this->assertSame(2, $total($this->branchA));
        $this->assertSame(1, $total($this->branchB));

        $byClient = MissedOpportunity::query()
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->groupByClient()
            ->pluck('total', 'client_org_id')
            ->all();
        $this->assertSame([$this->clientX => 1, $this->clientY => 1], $this->ints($byClient));

        $byMonth = MissedOpportunity::query()
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->forClient($this->clientX)
            ->byMonth()
            ->pluck('total', 'month')
            ->all();
        $this->assertSame(1, array_sum($this->ints($byMonth)));

        $byClientAndMonth = MissedOpportunity::query()
            ->forOrg($this->orgId)
            ->between($start, $end, 'UTC')
            ->forBranch($this->branchA)
            ->groupByClientAndMonth()
            ->get();
        $this->assertSame(2, $byClientAndMonth->sum(fn ($row) => (int) $row->total));
    }

    public function test_batch_queries_use_current_branch_for_both_cards(): void
    {
        [$start, $end] = $this->window();

        // handle() - not query() - so a CH failure surfaces.
        $metrics = ClientRankedMetricsBatch::handle([
            'org_id' => $this->orgId,
            'client_org_ids' => [$this->clientX, $this->clientY],
            'start' => $start,
            'end' => $end,
            'timezone' => 'UTC',
            'branch_id' => $this->branchA,
        ]);

        $this->assertSame(1, (int) $metrics[$this->clientX]['lpr_assists']);
        $this->assertSame(1, (int) $metrics[$this->clientY]['lpr_assists']);
        $this->assertSame(1, (int) $metrics[$this->clientX]['missed']);
        $this->assertSame(1, (int) $metrics[$this->clientY]['missed']);

        $spark = BatchMonthlySparklineData::handle([
            'org_id' => $this->orgId,
            'client_org_ids' => [$this->clientX, $this->clientY],
            'branch_id' => $this->branchA,
            'months' => 3,
            'timezone' => 'UTC',
        ]);

        $thisMonth = 2;
        $this->assertSame(1, $spark[$this->clientX]['lpr_assists'][$thisMonth]);
        $this->assertSame(1, $spark[$this->clientY]['lpr_assists'][$thisMonth]);
        $this->assertSame(1, $spark[$this->clientX]['missed_opportunities'][$thisMonth]);
        $this->assertSame(1, $spark[$this->clientY]['missed_opportunities'][$thisMonth]);
    }

    public function test_unfiltered_counts_every_row_and_adds_no_hotlist_subquery(): void
    {
        [$start, $end] = $this->window();

        foreach ([null, 0] as $branchId) {
            $this->assertSame(7, LprAssist::getTotal($this->orgId, $start, $end, 'UTC', $branchId));

            $query = MissedOpportunity::query()
                ->forOrg($this->orgId)
                ->between($start, $end, 'UTC')
                ->forBranch($branchId)
                ->selectTotal();

            $this->assertStringNotContainsString('hotlist FINAL', $query->toSql());
            $this->assertStringNotContainsString('branch_id', $query->toSql());
            $this->assertSame(7, (int) $query->value('total'));
        }
    }

    public function test_missed_opportunity_for_branch_requires_for_org(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MissedOpportunity::query()->forBranch($this->branchA);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(): array
    {
        return ['2026-10-01 00:00:00', '2026-10-31 23:59:59'];
    }

    private function ints(array $values): array
    {
        return array_map('intval', $values);
    }

    /**
     * Hotlist current branch vs the branch copied onto recoveries / missed_opportunities:
     *   1 A (copied 0)  2 A (copied B)  3 B (copied A)  4 NULL (copied A)  5 0 (copied A)
     *   6 A but deleted (copied A)  7 A but peer org (copied A)
     */
    private function seedFixture(): void
    {
        $rows = [
            // id, hotlist org, client, current branch, deleted, copied branch
            [1, $this->orgId, $this->clientX, $this->branchA, 0, 0],
            [2, $this->orgId, $this->clientY, $this->branchA, 0, $this->branchB],
            [3, $this->orgId, $this->clientX, $this->branchB, 0, $this->branchA],
            [4, $this->orgId, $this->clientX, null, 0, $this->branchA],
            [5, $this->orgId, $this->clientX, 0, 0, $this->branchA],
            [6, $this->orgId, $this->clientX, $this->branchA, 1, $this->branchA],
            [7, $this->peerOrgId, $this->clientX, $this->branchA, 0, $this->branchA],
        ];

        $ch = DB::connection('clarity_clickhouse');

        foreach ($rows as [$id, $hotlistOrgId, $clientOrgId, $currentBranch, $deleted, $copiedBranch]) {
            $ch->statement(
                'INSERT INTO hotlist
                    (id, org_id, client_org_id, status, branch_id, is_staged, created_at, updated_at, _peerdb_synced_at, _version, _peerdb_is_deleted)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 1, ?)',
                [$id, $hotlistOrgId, $clientOrgId, HotlistStatusEnum::Repossessed->value, $currentBranch,
                    '2026-09-01 10:00:00', '2026-10-14 10:00:00', '2026-10-14 10:00:00', $deleted]
            );

            // Recovery and missed-opportunity rows all belong to the org under test so only
            // the hotlist subquery decides branch membership.
            $ch->statement(
                'INSERT INTO recoveries
                    (id, org_id, client_org_id, is_staged, created_at, updated_at, branch_id, repo_date, _version, _peerdb_is_deleted)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?, 1, 0)',
                [$id, $this->orgId, $clientOrgId, '2026-09-01 10:00:00', '2026-10-10 10:00:00', $copiedBranch, '2026-10-10']
            );

            $ch->statement(
                'INSERT INTO recovery_scans
                    (recovery_id, hit_id, scan_id, hit_type, kit_id, ocr, scanned_at, _peerdb_synced_at, _peerdb_version, _peerdb_is_deleted)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, 1, 0)',
                [$id, 9000 + $id, 8000 + $id, 'M', 'ABC'.$id, '2026-10-09 10:00:00', '2026-10-09 10:00:00']
            );

            $ch->table('missed_opportunities')->insert([
                'id' => 7000 + $id,
                'hotlist_id' => $id,
                'hotlist_org_id' => $this->orgId,
                'client_org_id' => $clientOrgId,
                'branch_id' => $copiedBranch,
                'scanned_at' => '2026-10-12 10:00:00',
                'kit_id' => 1,
                'kit_org_id' => $this->orgId,
                '_peerdb_is_deleted' => 0,
                '_version' => 1,
            ]);
        }
    }
}
