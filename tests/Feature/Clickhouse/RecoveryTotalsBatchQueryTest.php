<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Models\Recovery;
use App\Clickhouse\Queries\RecoveryTotalsBatchQuery;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * DEV-2470. The properties that only real ClickHouse can demonstrate: `FINAL` dedup, the
 * tombstone filter, `toStartOfMonth(repo_date)` bucketing, and parity with the Eloquent path
 * RecoveryCounts renders on the same grid.
 *
 * Skipped unless CLICKHOUSE_DRIVER=clickhouse. CI runs a real ClickHouse container.
 */
#[Group('clickhouse')]
class RecoveryTotalsBatchQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const ORG = 90001;

    private const OTHER_ORG = 90002;

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();
    }

    /**
     * THE reason this query carries `FINAL` while its scan-side sibling deliberately does not.
     * Recovery rows genuinely mutate, so two CDC versions of one recovery are two rows on disk;
     * measured on production, omitting `FINAL` over-counts an org's 13-month total by 17.7%.
     */
    public function test_final_collapses_a_mutated_recovery_to_one_row(): void
    {
        $id = $this->seedRecovery('2026-07-04');
        $this->seedRecovery('2026-07-04', id: $id, version: 2);

        $this->assertSame(1, $this->window('2026-07-01', '2026-07-31'));
    }

    /**
     * Soft-deleted recoveries do not count -- asserted together with the fact that the tombstone
     * row is genuinely ON DISK, so the fixture cannot quietly become a no-op.
     *
     * Be precise about the MECHANISM, because the obvious reading of this test is wrong: the
     * engine is `ReplacingMergeTree(_version, _peerdb_is_deleted)`, the two-argument form, so
     * `FINAL` alone already drops the tombstone and this test still passes with the query's
     * explicit `_peerdb_is_deleted = 0` predicate removed. That predicate is therefore
     * defence-in-depth rather than the thing under test here -- kept because it is the house
     * convention (okf/concepts/clickhouse-query-patterns.md), because it is what `Recovery`'s
     * FiltersCdcDeletes scope applies and so keeps the Eloquent-parity test below honest, and
     * because it is what still holds if `FINAL` is ever dropped for cost. No test can pin it
     * while `FINAL` is present; saying so here is better than implying one does.
     */
    public function test_soft_deleted_recoveries_are_excluded(): void
    {
        $this->seedRecovery('2026-07-04');
        $this->seedRecovery('2026-07-05', deleted: 1);

        $onDisk = DB::connection('clarity_clickhouse')->select(
            'SELECT count() AS c FROM '.self::clarityDb().'.recoveries WHERE org_id = '.self::ORG
        );

        $this->assertSame(2, (int) $onDisk[0]['c'], 'guard: the tombstone must really be stored, or this test proves nothing');
        $this->assertSame(1, $this->window('2026-07-01', '2026-07-31'));
    }

    public function test_another_orgs_recoveries_do_not_count(): void
    {
        $this->seedRecovery('2026-07-04');
        $this->seedRecovery('2026-07-04', orgId: self::OTHER_ORG);

        $this->assertSame(1, $this->window('2026-07-01', '2026-07-31'));
        $this->assertSame(1, $this->window('2026-07-01', '2026-07-31', orgId: self::OTHER_ORG));
    }

    /** Both bounds inclusive, matching Recovery::scopeBetween() exactly. */
    public function test_the_window_bounds_are_inclusive_on_both_ends(): void
    {
        $this->seedRecovery('2026-07-01');
        $this->seedRecovery('2026-07-31');
        $this->seedRecovery('2026-06-30');
        $this->seedRecovery('2026-08-01');

        $this->assertSame(2, $this->window('2026-07-01', '2026-07-31'));
    }

    /**
     * The series is keyed `Y-m`, NOT the `Y-m-01` that `toStartOfMonth` returns -- the card's
     * numerator leg keys `Y-m`, and normalising here is what removes the key-shape seam the
     * sibling card has to reconcile by hand.
     */
    public function test_the_series_buckets_by_month_and_is_keyed_year_month(): void
    {
        $this->seedRecovery('2026-06-02');
        $this->seedRecovery('2026-06-29');
        $this->seedRecovery('2026-07-15');

        $series = RecoveryTotalsBatchQuery::handle([
            'org_id' => self::ORG,
            'start' => '2026-06-01',
            'end' => '2026-07-31',
        ]);

        $this->assertSame(['2026-06' => 2, '2026-07' => 1], $series);
    }

    /** A month with no repossessions is absent from the result, not a 0 row -- the card defaults it. */
    public function test_a_month_with_no_recoveries_is_absent_from_the_series(): void
    {
        $this->seedRecovery('2026-07-15');

        $series = RecoveryTotalsBatchQuery::handle([
            'org_id' => self::ORG,
            'start' => '2026-06-01',
            'end' => '2026-07-31',
        ]);

        $this->assertArrayNotHasKey('2026-06', $series);
        $this->assertSame(1, $series['2026-07']);
    }

    /**
     * The decision this card is most likely to be challenged on: the denominator uses `count()`
     * so it equals the "Total Repossessions" figure RecoveryCounts renders on the same grid.
     * Pinned against that card's actual Eloquent path rather than restated in a comment.
     */
    public function test_the_window_total_matches_the_eloquent_path_recovery_counts_uses(): void
    {
        $id = $this->seedRecovery('2026-07-04');
        $this->seedRecovery('2026-07-04', id: $id, version: 2);
        $this->seedRecovery('2026-07-09');
        $this->seedRecovery('2026-07-20', deleted: 1);
        $this->seedRecovery('2026-07-22', orgId: self::OTHER_ORG);

        $eloquent = Recovery::query()
            ->forOrg(self::ORG)
            ->between('2026-07-01', '2026-07-31')
            ->count();

        $this->assertSame($eloquent, $this->window('2026-07-01', '2026-07-31'));
    }

    /** The series and the window must not be able to disagree about one span. */
    public function test_the_series_sums_to_the_window_total_over_the_same_span(): void
    {
        $this->seedRecovery('2026-06-02');
        $this->seedRecovery('2026-07-15');
        $this->seedRecovery('2026-07-16');

        $series = RecoveryTotalsBatchQuery::handle([
            'org_id' => self::ORG,
            'start' => '2026-06-01',
            'end' => '2026-07-31',
        ]);

        $this->assertSame($this->window('2026-06-01', '2026-07-31'), array_sum($series));
    }

    public function test_a_malformed_date_is_rejected_rather_than_interpolated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecoveryTotalsBatchQuery::handleRange([
            'org_id' => self::ORG,
            'start' => "2026-07-01' OR 1=1 --",
            'end' => '2026-07-31',
        ]);
    }

    /**
     * Resolve the database rather than hardcoding `clarity`, matching what the query class does
     * through ClickhouseQuery::clarityDb(). A hardcoded prefix here would pass under the literal
     * local-CH naming and break under a per-worktree one -- and it would test a different table
     * than the code under test reads. Round 2, lane 3.
     */
    private static function clarityDb(): string
    {
        return config('database.connections.clarity_clickhouse.driver') === 'pgsql'
            ? 'clarity'
            : config('database.connections.clarity_clickhouse.database');
    }

    private function window(string $start, string $end, int $orgId = self::ORG): int
    {
        return RecoveryTotalsBatchQuery::handleRange([
            'org_id' => $orgId,
            'start' => $start,
            'end' => $end,
        ]);
    }

    private function seedRecovery(
        string $repoDate,
        int $orgId = self::ORG,
        ?int $id = null,
        int $version = 1,
        int $deleted = 0,
    ): int {
        $id ??= $this->nextId++;

        DB::connection('clarity_clickhouse')->table(self::clarityDb().'.recoveries')->insert([
            'id' => $id,
            'org_id' => $orgId,
            'status' => 3,
            'is_staged' => false,
            'created_at' => $repoDate.' 12:00:00',
            'updated_at' => $repoDate.' 12:00:00',
            'repo_date' => $repoDate,
            '_peerdb_synced_at' => $repoDate.' 12:00:00',
            '_version' => $version,
            '_peerdb_is_deleted' => $deleted,
        ]);

        return $id;
    }
}
