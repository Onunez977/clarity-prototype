<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Models\LprRepossessionExportRow;
use App\Clickhouse\Queries\LprRepossessionsQuery;
use App\Clickhouse\Queries\UniqueLprCaseClaimsQuery;
use App\Filament\Exports\LprRepossessionExporter;
use App\Filament\Widgets\Dashboard\LprRepossessions;
use Carbon\Carbon;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Jobs\ExportCompletion;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Support\EloquentSerializer\EloquentSerializer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InsightLPR\BlackAdam\Enum\BillingEventType;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use LaravelClickhouseEloquent\Connection as ClickhouseConnection;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clickhouse')]
class LprRepossessionsQueryTest extends TestCase
{
    use RequiresClickhouse;

    // UTC calendar month, matching the ticket's own reproduction window.
    private const MONTH_START = '2026-07-01 00:00:00';

    private const MONTH_END = '2026-07-31 23:59:59';

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();

        DB::connection('clarity_clickhouse')->statement('TRUNCATE TABLE IF EXISTS zod.hotlist_json_denormalized');
    }

    public function test_dedup_key_is_the_full_three_column_tuple(): void
    {
        $forwarderA = $this->seedOrg('Forwarder A');
        $forwarderB = $this->seedOrg('Forwarder B');
        $agent = $this->seedOrg('Agent Co');

        // Same agent, same VIN, but via TWO different forwarders -- must be 2, not 1.
        $this->seedClaim(forwarderOrgId: $forwarderA, agentOrgId: $agent, vin: '1FTFW1ET5BFC12345');
        $this->seedClaim(forwarderOrgId: $forwarderB, agentOrgId: $agent, vin: '1FTFW1ET5BFC12345');

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $result['deduped']);
    }

    public function test_insightlpr_excluded_on_forwarder_side(): void
    {
        $insightlpr = $this->seedOrg('InsightLPR, Inc.');
        $normal = $this->seedOrg('Acme Recovery');

        // Exclusion is the frozen is_internal flag on the denormalized row.
        $this->seedClaim(forwarderOrgId: $insightlpr, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', internal: true);
        $this->seedClaim(forwarderOrgId: $normal, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB');

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
    }

    public function test_insightlpr_excluded_on_agent_side(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $insightlprAgent = $this->seedOrg('insight-lpr');
        $normalAgent = $this->seedOrg('Agent Co');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $insightlprAgent, vin: 'AAAAAAAAAAAAAAAAA', internal: true);
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $normalAgent, vin: 'BBBBBBBBBBBBBBBBB');

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
    }

    public function test_titlemax_not_excluded(): void
    {
        $titlemax = $this->seedOrg('TitleMax');

        $this->seedClaim(forwarderOrgId: $titlemax, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA');

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
    }

    public function test_ls_claim_excluded(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        $this->seedClaim(
            forwarderOrgId: $forwarder,
            agentOrgId: 5001,
            vin: 'AAAAAAAAAAAAAAAAA',
            eventType: BillingEventType::LS_CASE_CLAIM->value,
        );

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(0, $result['deduped']);
    }

    public function test_event_type_is_the_discriminator_not_type(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        // A row with a different event_type entirely must be ignored, not just ls-claim.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', eventType: BillingEventType::VIN2PLATE->value);
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB');

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
    }

    public function test_utc_calendar_month_inclusive_boundary(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        // Exactly on each boundary second -- both must count.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', ts: Carbon::parse(self::MONTH_START, 'UTC'));
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB', ts: Carbon::parse(self::MONTH_END, 'UTC'));

        // Just outside the window on either side -- must NOT count.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5003, vin: 'CCCCCCCCCCCCCCCCC', ts: Carbon::parse(self::MONTH_START, 'UTC')->subSecond());
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5004, vin: 'DDDDDDDDDDDDDDDDD', ts: Carbon::parse(self::MONTH_END, 'UTC')->addSecond());

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $result['deduped']);
    }

    public function test_either_side_org_axis(): void
    {
        $orgUnderTest = $this->seedOrg('Org Under Test');
        $otherForwarder = $this->seedOrg('Some Other Forwarder');
        $thirdOrg = $this->seedOrg('Third Party Org');

        // orgUnderTest is the FORWARDER on this claim.
        $this->seedClaim(forwarderOrgId: $orgUnderTest, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA');
        // orgUnderTest is the AGENT on this claim.
        $this->seedClaim(forwarderOrgId: $otherForwarder, agentOrgId: $orgUnderTest, vin: 'BBBBBBBBBBBBBBBBB');
        // orgUnderTest is on NEITHER side -- must be excluded when scoped.
        $this->seedClaim(forwarderOrgId: $otherForwarder, agentOrgId: $thirdOrg, vin: 'CCCCCCCCCCCCCCCCC');

        $result = LprRepossessionsQuery::window($orgUnderTest, $this->start(), $this->end());

        $this->assertSame(2, $result['deduped']);

        $platformWide = LprRepossessionsQuery::window(null, $this->start(), $this->end());
        $this->assertSame(3, $platformWide['deduped']);
    }

    public function test_missing_agent_org_id_counts_in_deduped_but_never_in_db_repos(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        // A missing agent is stored as agent_org_id 0 on the denormalized row.
        //
        // NOTE what this does and does not prove (review round 14, lanes 1 M3 and 3 M1). It pins
        // the OUTCOME for an agent-less claim, but not the `d.agent_org_id != 0` guard: with no
        // hotlist row seeded at all, `h` cannot match under any predicate, so db_repos = 0 comes
        // from "no hotlist exists". The guard itself is pinned by the test below, which seeds the
        // row that makes the two behaviours diverge.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: null, vin: 'AAAAAAAAAAAAAAAAA');

        $result = LprRepossessionsQuery::window($forwarder, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(0, $result['db_repos']);
    }

    /**
     * Pins `AND d.agent_org_id != 0` -- the FIRST sentinel guard, whose sibling `h.id != 0` was
     * closed in round 11 while this one went unexercised until round 14 (lanes 1 M3 and 3 M1 both
     * reproduced the surviving mutant on live ClickHouse).
     *
     * The discriminating row is a status-3 hotlist whose `org_id` is 0. `public_hotlist.org_id` is
     * a non-nullable Int32 in the mirror, so a Postgres NULL arrives as 0 -- the identical
     * mechanism the query's own comment cites for `hotlist_id`. An agent-less claim also carries
     * agent_org_id 0, so without the guard `h.org_id = d.agent_org_id` matches 0 = 0, the hotlist
     * has a real non-zero id so `h.id != 0` passes it through, and a claim with no agent is
     * credited with someone else's repossession.
     */
    public function test_a_status_three_hotlist_with_org_id_zero_is_not_joined_to_an_agentless_claim(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        // The claim has no agent: the denormalized row stores agent_org_id = 0.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: null, vin: 'AAAAAAAAAAAAAAAAA');

        // A hotlist carrying the sentinel org_id, same VIN, genuinely repossessed.
        $sentinelHotlist = $this->seedHotlist(agentOrgId: 0, vin: 'AAAAAAAAAAAAAAAAA');
        $repossessedAt = Carbon::parse('2026-07-09 12:00:00', 'UTC');
        $this->seedStatusLog(
            hotlistId: $sentinelHotlist,
            newStatus: 3,
            createdAt: $repossessedAt
        );
        $this->seedClarityHotlist(id: $sentinelHotlist, firstRepossessedAt: $repossessedAt, orgId: 0);

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped'], 'the agent-less claim is still a billed claim');
        $this->assertSame(
            0,
            $result['db_repos'],
            'an org_id = 0 hotlist must NOT join to an agent_org_id = 0 claim -- without '
            .'`AND d.agent_org_id != 0` the two sentinels match each other and this reads 1'
        );
        $this->assertSame([], LprRepossessionsQuery::rows(null, $this->start(), $this->end()));
    }

    /**
     * Review round 11, all three lanes. clarity_clickhouse sets no join_use_nulls, so ClickHouse's
     * default 0 applies: an UNMATCHED LEFT JOIN yields type defaults, not NULL. So an unmatched
     * hotlist collapses to the sentinel h.id = 0, and the chained clarity.hotlist join then matches
     * a row with id = 0 -- counting a triple that has no status-3 hotlist at all.
     *
     * The `AND h.id != 0` guard on the clarity.hotlist ON clause is what prevents it. Without this
     * fixture the guard can be deleted with the whole suite still green.
     */
    public function test_a_stray_hotlist_id_zero_transition_does_not_count_a_triple_with_no_repo(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agent = $this->seedOrg('Sunrise Agency');

        // A claim whose agent has NO status-3 hotlist for this VIN -> the h join cannot match.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: 'NOREPOVIN0000001');

        // A stray clarity.hotlist row keyed on the sentinel 0, dated inside the floor. An unmatched
        // h.id collapses to 0 and would join this row if the sentinel guard is removed.
        $this->seedClarityHotlist(
            id: 0,
            firstRepossessedAt: Carbon::parse('2026-07-09 12:00:00', 'UTC'),
            orgId: 0,
        );

        $result = LprRepossessionsQuery::window($forwarder, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped'], 'the claim itself must still be deduped');
        $this->assertSame(
            0,
            $result['db_repos'],
            'a triple with no status-3 hotlist must never count, even when a clarity.hotlist '
            .'id = 0 row exists -- the h.id != 0 sentinel guard is missing'
        );
        $this->assertSame([], LprRepossessionsQuery::rows($forwarder, $this->start(), $this->end()));
    }

    /**
     * The counterpart to the test above: the guard must not suppress a GENUINE match.
     */
    public function test_a_real_repo_still_counts_alongside_a_stray_hotlist_id_zero_transition(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agent = $this->seedOrg('Sunrise Agency');

        $repossessedAt = Carbon::parse('2026-07-09 12:00:00', 'UTC');
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: 'REALREPOVIN00001');
        $hotlist = $this->seedHotlist(agentOrgId: $agent, vin: 'REALREPOVIN00001', status: 3);
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 3, createdAt: $repossessedAt);
        $this->seedClarityHotlist(id: $hotlist, firstRepossessedAt: $repossessedAt, orgId: $agent);
        $this->seedClarityHotlist(id: 0, firstRepossessedAt: $repossessedAt, orgId: 0);

        $result = LprRepossessionsQuery::window($forwarder, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(1, $result['db_repos'], 'the h.id != 0 guard must not suppress a real match');
    }

    public function test_repo_transition_exactly_at_claim_date_minus_seven_counts_one_day_earlier_does_not(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agentA = $this->seedOrg('Agent A');
        $agentB = $this->seedOrg('Agent B');
        $claimDate = Carbon::parse('2026-07-15 12:00:00', 'UTC');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agentA, vin: 'AAAAAAAAAAAAAAAAA', ts: $claimDate);
        $hotlistA = $this->seedHotlist(agentOrgId: $agentA, vin: 'AAAAAAAAAAAAAAAAA');
        // Exactly claim_date - 7 -- must count.
        $onWindow = Carbon::parse('2026-07-08 00:00:00', 'UTC');
        $this->seedStatusLog(hotlistId: $hotlistA, newStatus: 3, createdAt: $onWindow);
        $this->seedClarityHotlist(id: $hotlistA, firstRepossessedAt: $onWindow, orgId: $agentA);

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agentB, vin: 'BBBBBBBBBBBBBBBBB', ts: $claimDate);
        $hotlistB = $this->seedHotlist(agentOrgId: $agentB, vin: 'BBBBBBBBBBBBBBBBB');
        // One day earlier than claim_date - 7 -- must NOT count.
        $beforeWindow = Carbon::parse('2026-07-07 00:00:00', 'UTC');
        $this->seedStatusLog(hotlistId: $hotlistB, newStatus: 3, createdAt: $beforeWindow);
        $this->seedClarityHotlist(id: $hotlistB, firstRepossessedAt: $beforeWindow, orgId: $agentB);

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $result['deduped']);
        $this->assertSame(1, $result['db_repos']);

        $rows = LprRepossessionsQuery::rows(null, $this->start(), $this->end());
        $this->assertCount(1, $rows);
        $this->assertSame('AAAAAAAAAAAAAAAAA', $rows[0]['vin']);
    }

    public function test_status3_hotlist_with_no_new_status3_transition_never_counts(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agent = $this->seedOrg('Agent Co');
        $claimDate = Carbon::parse('2026-07-15 12:00:00', 'UTC');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: 'AAAAAAAAAAAAAAAAA', ts: $claimDate);
        $hotlist = $this->seedHotlist(agentOrgId: $agent, vin: 'AAAAAAAAAAAAAAAAA');
        // Transition present, but never TO status 3, so the MV leaves first_repossessed_at null.
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 1, createdAt: Carbon::parse('2026-07-08 00:00:00', 'UTC'));
        $this->seedClarityHotlist(id: $hotlist, firstRepossessedAt: null, orgId: $agent);

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(0, $result['db_repos']);
    }

    public function test_soft_deleted_and_duplicate_version_hotlist_rows_do_not_inflate_db_repos(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agentA = $this->seedOrg('Agent A');
        $agentB = $this->seedOrg('Agent B');
        $claimDate = Carbon::parse('2026-07-15 12:00:00', 'UTC');
        $transitionAt = Carbon::parse('2026-07-08 00:00:00', 'UTC');

        // Pair A: the hotlist's current (highest-version) row is soft-deleted --
        // FINAL + _peerdb_is_deleted = 0 must drop it from the `h` join entirely,
        // even though a matching status=3 transition exists.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agentA, vin: 'AAAAAAAAAAAAAAAAA', ts: $claimDate);
        $hotlistA = $this->seedHotlist(agentOrgId: $agentA, vin: 'AAAAAAAAAAAAAAAAA', version: 1, deleted: false);
        $this->seedHotlist(agentOrgId: $agentA, vin: 'AAAAAAAAAAAAAAAAA', id: $hotlistA, version: 2, deleted: true);
        $this->seedStatusLog(hotlistId: $hotlistA, newStatus: 3, createdAt: $transitionAt);
        $this->seedClarityHotlist(id: $hotlistA, firstRepossessedAt: $transitionAt, orgId: $agentA);

        // Pair B: two non-deleted versions of the same row (status changed from 2 to
        // 3) -- FINAL must collapse them to the latest (status = 3), counting ONCE.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agentB, vin: 'BBBBBBBBBBBBBBBBB', ts: $claimDate);
        $hotlistB = $this->seedHotlist(agentOrgId: $agentB, vin: 'BBBBBBBBBBBBBBBBB', status: 2, version: 1, deleted: false);
        $this->seedHotlist(agentOrgId: $agentB, vin: 'BBBBBBBBBBBBBBBBB', id: $hotlistB, status: 3, version: 2, deleted: false);
        $this->seedStatusLog(hotlistId: $hotlistB, newStatus: 3, createdAt: $transitionAt);
        $this->seedClarityHotlist(id: $hotlistB, firstRepossessedAt: $transitionAt, orgId: $agentB);

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $result['deduped']);
        $this->assertSame(1, $result['db_repos'], 'only pair B (a live, current status=3 row) should count');
    }

    /**
     * View contract. `_peerdb_is_deleted` is not a column on billing_claim_events_v,
     * so this query cannot restate the tombstone predicate. The view is
     * `FINAL` where `_peerdb_is_deleted = 0`. Seeding the flag on the table
     * behind the view must keep the row out. Pointing the query at the base
     * table without that filter would let the row back in.
     */
    public function test_tombstoned_billing_event_is_excluded_even_though_final_would_keep_it(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', deleted: true);
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB');

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
    }

    public function test_monthly_series_buckets_by_utc_calendar_month(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', ts: Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB', ts: Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $series = LprRepossessionsQuery::monthlySeries(null, Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $this->assertSame(1, $series['2026-06-01']['deduped']);
        $this->assertSame(1, $series['2026-07-01']['deduped']);
    }

    /**
     * The `db_repos` aggregate is written TWICE in LprRepossessionsQuery -- once in the bucketed
     * select and once in the unbucketed one -- and only the unbucketed copy had coverage. Review
     * round 13 (lane 1 M1, lane 2 M5a) named the consequence: the card's DEFAULT filter is
     * this_month, whose headline and all 13 sparkline bars read the BUCKETED expression, so a
     * divergence between the two definitions of the card's headline metric passed all 18 tests.
     *
     * Also pins that a bucket with claims but no repossession reads 0 rather than going missing --
     * a real 0 is a legitimate reading -- 4 of 29 entitled orgs read 0 in Jul 2026 (month-specific:
     * Aug 2026 measured 29/29 non-zero).
     */
    public function test_monthly_series_reports_db_repos_per_bucket_not_only_deduped(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agent = $this->seedOrg('Agent Co');

        // June: a claim that DID go on to a recovered transition.
        $repossessedAt = Carbon::parse('2026-06-20 12:00:00', 'UTC');
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: 'AAAAAAAAAAAAAAAAA', ts: Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $hotlist = $this->seedHotlist(agentOrgId: $agent, vin: 'AAAAAAAAAAAAAAAAA');
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 3, createdAt: $repossessedAt);
        $this->seedClarityHotlist(id: $hotlist, firstRepossessedAt: $repossessedAt, orgId: $agent);

        // July: a claim with no hotlist and no transition at all.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: 'BBBBBBBBBBBBBBBBB', ts: Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $series = LprRepossessionsQuery::monthlySeries(null, Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $this->assertSame(1, $series['2026-06-01']['deduped']);
        $this->assertSame(
            1,
            $series['2026-06-01']['db_repos'],
            'the BUCKETED db_repos expression must count the recovered claim, not just deduped'
        );

        $this->assertSame(1, $series['2026-07-01']['deduped']);
        $this->assertSame(
            0,
            $series['2026-07-01']['db_repos'],
            'a bucket with claims but no repossession must read 0, not go missing'
        );
    }

    /**
     * The `{floor}` prefilter on the clarity.hotlist subquery is `window_start - 7 days`, and nothing
     * pinned the `- 7`. Review round 13 flagged it unanimously (lane 1 M2, lane 2 M5b, lane 3 M3):
     * every existing fixture sits INSIDE the window, so deleting `->subDays(7)` filtered
     * nothing and all 18 tests stayed green.
     *
     * The floor exists because the real predicate is `transition >= claim_date - 7`, so a claim in
     * the window's first week can legitimately match a transition from BEFORE the window start.
     * Drop the floor and those repossessions vanish -- i.e. the AC-1 figure under-reports in the
     * first week of every month.
     */
    public function test_a_repossession_up_to_seven_days_before_the_window_start_still_counts(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agent = $this->seedOrg('Agent Co');

        // Claim on the window's second day, so claim_date - 7 (2026-06-25) reaches back past the
        // window start (2026-07-01).
        $repossessedAt = Carbon::parse('2026-06-28 12:00:00', 'UTC');
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: 'AAAAAAAAAAAAAAAAA', ts: Carbon::parse('2026-07-02 12:00:00', 'UTC'));
        $hotlist = $this->seedHotlist(agentOrgId: $agent, vin: 'AAAAAAAAAAAAAAAAA');
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 3, createdAt: $repossessedAt);
        $this->seedClarityHotlist(id: $hotlist, firstRepossessedAt: $repossessedAt, orgId: $agent);

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(
            1,
            $result['db_repos'],
            'a transition 4 days before the window start is within claim_date - 7, so the {floor} '
            .'prefilter must reach back 7 days -- with floor = window_start it is dropped and this reads 0'
        );
    }

    /**
     * `first_repossessed_at` is min() of status-3 transitions. A later transition inside
     * claim_date - 7 must not count when that minimum is still below the predicate.
     */
    public function test_min_first_repossessed_at_below_the_predicate_does_not_count_when_a_later_transition_is_inside(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agent = $this->seedOrg('Agent Co');
        $claimDate = Carbon::parse('2026-07-15 12:00:00', 'UTC');
        $minAt = Carbon::parse('2026-07-01 12:00:00', 'UTC');
        $maxAt = Carbon::parse('2026-07-10 12:00:00', 'UTC');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: 'MINMAXVIN0000001', ts: $claimDate);
        $hotlist = $this->seedHotlist(agentOrgId: $agent, vin: 'MINMAXVIN0000001');
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 3, createdAt: $minAt);
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 3, createdAt: $maxAt);
        $this->seedClarityHotlist(id: $hotlist, firstRepossessedAt: $minAt, orgId: $agent);

        $result = LprRepossessionsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(
            0,
            $result['db_repos'],
            'min(first_repossessed_at) is 2026-07-01, below claim_date - 7 (2026-07-08), so the later '
            .'2026-07-10 status-3 transition must not count'
        );
    }

    public function test_clickhouse_failure_surfaces_instead_of_returning_zero(): void
    {
        config([
            'database.connections.clarity_clickhouse.driver' => 'clickhouse',
            'database.connections.clarity_clickhouse.host' => '127.0.0.1',
            'database.connections.clarity_clickhouse.port' => '1',
            'database.connections.clarity_clickhouse.https' => false,
            'database.connections.clarity_clickhouse.options' => ['protocol' => 'http'],
            'database.connections.clarity_clickhouse.timeout_connect' => 1,
            'database.connections.clarity_clickhouse.timeout_query' => 1,
            'database.connections.clarity_clickhouse.retries' => 0,
        ]);
        DB::purge('clarity_clickhouse');

        $this->expectException(ClickhouseException::class);

        LprRepossessionsQuery::window(null, $this->start(), $this->end());
    }

    /**
     * Drift alarm (q4: reuse the sibling's validated claims/kept CTE text rather
     * than re-deriving it) -- the `deduped` stage of this class must agree with
     * UniqueLprCaseClaimsQuery::window()['deduped'] on an identical fixture.
     */
    public function test_deduped_stage_agrees_with_unique_lpr_case_claims_query(): void
    {
        $forwarderA = $this->seedOrg('Forwarder A');
        $forwarderB = $this->seedOrg('Forwarder B');
        $agent = $this->seedOrg('Agent Co');

        $this->seedClaim(forwarderOrgId: $forwarderA, agentOrgId: $agent, vin: '1FTFW1ET5BFC12345');
        $this->seedClaim(forwarderOrgId: $forwarderB, agentOrgId: $agent, vin: '1FTFW1ET5BFC12345');
        $this->seedClaim(forwarderOrgId: $forwarderA, agentOrgId: $agent, vin: '2FTFW1ET5BFC12346', success: false);

        $repossessions = LprRepossessionsQuery::window(null, $this->start(), $this->end());
        $claims = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        // Without this, 0 === 0 passes and the drift alarm stops alarming -- which is exactly
        // what a truncation race produces when the fixture never lands. Review round 13 (lane 3
        // L7) caught it.
        $this->assertGreaterThan(0, $claims['deduped'], 'precondition: the fixture actually landed');
        $this->assertSame($claims['deduped'], $repossessions['deduped']);
    }

    public function test_aggregate_sql_stays_byte_identical(): void
    {
        $start = Carbon::parse('2026-07-01 00:00:00', 'UTC');
        $end = Carbon::parse('2026-07-31 23:59:59', 'UTC');
        $through = Carbon::parse('2026-07-15 12:00:00', 'UTC');

        $window = $this->normalizeDbNames(LprRepossessionsQuery::windowSql(0, $start, $end));
        $series = $this->normalizeDbNames(LprRepossessionsQuery::monthlySeriesSql(0, $through));

        $this->assertSame('b1ad016dfb9c833680b26ec06b5c7b080cc53069', sha1($window));
        $this->assertSame('a84d44d0b48bacc65e787eb9031e03abc8d47d0c', sha1($series));
        $this->assertStringNotContainsString('cityHash64', $window);
        $this->assertStringNotContainsString('cityHash64', $series);
        $this->assertStringNotContainsString('repo_agent_name', $window);
        $this->assertStringNotContainsString('hotlist_json_denormalized', $window);
        $this->assertStringNotContainsString('public_orgs', $window);
        $this->assertDoesNotMatchRegularExpression('/maxRows\s*\(/', file_get_contents(app_path('Clickhouse/Queries/LprRepossessionsQuery.php')) ?: '');
    }

    public function test_listing_row_count_matches_window_and_month_bucket(): void
    {
        $forwarder = $this->seedOrg('Forwarder A');
        $agent = $this->seedOrg('Agent Co');
        $this->seedRecoveredClaim($forwarder, $agent, 'PARITYVIN00000001');

        $window = LprRepossessionsQuery::window(null, $this->start(), $this->end());
        $this->assertGreaterThan(0, $window['db_repos']);

        $rows = LprRepossessionsQuery::rows(null, $this->start(), $this->end());
        $this->assertCount($window['db_repos'], $rows);

        $series = LprRepossessionsQuery::monthlySeries(null, Carbon::parse('2026-07-15 12:00:00', 'UTC'));
        $this->assertGreaterThan(0, $series['2026-07-01']['db_repos']);
        $this->assertCount($series['2026-07-01']['db_repos'], $rows);

        $scopedWindow = LprRepossessionsQuery::window($forwarder, $this->start(), $this->end());
        $this->assertCount($scopedWindow['db_repos'], LprRepossessionsQuery::rows($forwarder, $this->start(), $this->end()));
    }

    public function test_listing_is_one_row_per_triple_not_per_vin(): void
    {
        $forwarder = $this->seedOrg('Forwarder A');
        $agentA = $this->seedOrg('Agent A');
        $agentB = $this->seedOrg('Agent B');
        $vin = 'SAMEVIN0000000001';

        $this->seedRecoveredClaim($forwarder, $agentA, $vin);
        $this->seedRecoveredClaim($forwarder, $agentB, $vin);

        $rows = LprRepossessionsQuery::rows(null, $this->start(), $this->end());

        $this->assertCount(2, $rows);
        $this->assertSame([$vin, $vin], array_column($rows, 'vin'));
        $this->assertSame(2, LprRepossessionsQuery::window(null, $this->start(), $this->end())['db_repos']);
    }

    public function test_listing_collapses_hotlist_fan_out_and_keeps_the_earliest_plate(): void
    {
        $forwarder = $this->seedOrg('Forwarder A');
        $agent = $this->seedOrg('Agent Co');
        $vin = 'FANOUTVIN00000001';
        $claimAt = Carbon::parse('2026-07-15 12:00:00', 'UTC');
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: $vin, ts: $claimAt);

        $later = $this->seedHotlist(agentOrgId: $agent, vin: $vin, id: 500, plate: 'LATERPLATE');
        $earlier = $this->seedHotlist(agentOrgId: $agent, vin: $vin, id: 800, plate: 'EARLYPLATE');
        $laterAt = Carbon::parse('2026-07-20 12:00:00', 'UTC');
        $earlierAt = Carbon::parse('2026-07-10 12:00:00', 'UTC');
        $this->seedStatusLog(hotlistId: $later, newStatus: 3, createdAt: $laterAt);
        $this->seedStatusLog(hotlistId: $earlier, newStatus: 3, createdAt: $earlierAt);
        $this->seedClarityHotlist(id: $later, firstRepossessedAt: $laterAt, orgId: $agent);
        $this->seedClarityHotlist(id: $earlier, firstRepossessedAt: $earlierAt, orgId: $agent);
        $this->seedCaseNumber($earlier, 'RDN-800');

        $rows = LprRepossessionsQuery::rows(null, $this->start(), $this->end());

        $this->assertCount(1, $rows);
        $this->assertSame(1, LprRepossessionsQuery::window(null, $this->start(), $this->end())['db_repos']);
        $this->assertSame('EARLYPLATE', $rows[0]['plate']);
        $this->assertSame('RDN-800', $rows[0]['case_number']);
        $this->assertSame('Agent Co', $rows[0]['claimed_by']);
        $this->assertSame('', $rows[0]['repo_agent_name']);
        $this->assertSame($forwarder.'|'.$agent.'|'.$vin, $rows[0]['id']);
    }

    public function test_listing_counts_a_triple_once_when_the_window_straddles_a_month(): void
    {
        $forwarder = $this->seedOrg('Forwarder A');
        $agent = $this->seedOrg('Agent Co');
        $vin = 'STRADDLEVIN000001';
        $repoAt = Carbon::parse('2026-08-01 12:00:00', 'UTC');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: $vin, ts: Carbon::parse('2026-07-30 12:00:00', 'UTC'));
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: $vin, ts: Carbon::parse('2026-08-02 12:00:00', 'UTC'));
        $hotlist = $this->seedHotlist(agentOrgId: $agent, vin: $vin, plate: 'STRADDLE');
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 3, createdAt: $repoAt);
        $this->seedClarityHotlist(id: $hotlist, firstRepossessedAt: $repoAt, orgId: $agent);

        $start = Carbon::parse('2026-07-28 00:00:00', 'UTC');
        $end = Carbon::parse('2026-08-04 23:59:59', 'UTC');

        $this->assertSame(1, LprRepossessionsQuery::window(null, $start, $end)['db_repos']);
        $this->assertCount(1, LprRepossessionsQuery::rows(null, $start, $end));
    }

    public function test_listing_projects_decoration_and_a_blank_repo_agent_name(): void
    {
        $forwarder = $this->seedOrg('Forwarder A');
        $agent = $this->seedOrg('Sunrise Agency');
        $this->seedRecoveredClaim($forwarder, $agent, 'DECORVIN000000001', plate: 'PLATE1', caseNumber: '');
        $this->seedRecoveredClaim($forwarder, $agent, 'DECORVIN000000002', plate: 'PLATE2', caseNumber: 'CASE-2');

        $rows = LprRepossessionsQuery::rows(null, $this->start(), $this->end());
        $byVin = [];
        foreach ($rows as $row) {
            $byVin[$row['vin']] = $row;
        }

        $this->assertSame('PLATE1', $byVin['DECORVIN000000001']['plate']);
        $this->assertSame('', (string) $byVin['DECORVIN000000001']['case_number']);
        $this->assertSame('Sunrise Agency', $byVin['DECORVIN000000001']['claimed_by']);
        $this->assertSame('', $byVin['DECORVIN000000001']['repo_agent_name']);
        $this->assertSame('CASE-2', $byVin['DECORVIN000000002']['case_number']);
        $this->assertSame('', $byVin['DECORVIN000000002']['repo_agent_name']);

        $sql = LprRepossessionsQuery::rowsSql(null, $this->start(), $this->end());
        $this->assertStringContainsString("concat(toString(g.forwarder_org_id),'|',toString(g.agent_org_id),'|',g.vin) AS id", $sql);
        $this->assertStringContainsString("'' AS repo_agent_name", $sql);
        $this->assertStringNotContainsString('cityHash64', $sql);
        $this->assertStringNotContainsString('remote_order_id', $sql);
        $this->assertStringNotContainsString('rdn_agents', $sql);
    }

    public function test_string_triple_key_round_trips_through_find_and_the_export_query(): void
    {
        $forwarder = $this->seedOrg('Forwarder A');
        $agent = $this->seedOrg('Agent Co');
        $vin = 'ROUNDTRIPVIN00001';
        $this->seedRecoveredClaim($forwarder, $agent, $vin, plate: 'RTPLATE', caseNumber: 'RT-1');

        $rows = LprRepossessionsQuery::rows(null, $this->start(), $this->end());
        $this->assertCount(1, $rows);
        $id = (string) $rows[0]['id'];
        $this->assertSame($forwarder.'|'.$agent.'|'.$vin, $id);
        $this->assertDoesNotMatchRegularExpression('/^\d+$/', $id);

        $listing = fn () => LprRepossessionExportRow::query()->fromRaw(
            '('.LprRepossessionsQuery::rowsSql(null, $this->start(), $this->end()).') AS lpr_repo_export'
        );

        $found = $listing()->find([$id]);
        $this->assertCount(1, $found);
        $this->assertSame($id, (string) $found->first()->getKey());
        $this->assertSame('RTPLATE', $found->first()->plate);
        $this->assertSame('RT-1', $found->first()->case_number);
        $this->assertSame('Agent Co', $found->first()->claimed_by);
        $this->assertSame('', (string) $found->first()->repo_agent_name);

        $this->assertSame(1, $listing()->toBase()->getCountForPagination());

        $chunked = [];
        $listing()->toBase()->orderedChunkById(
            100,
            function ($records) use (&$chunked): void {
                foreach ($records as $record) {
                    $chunked[] = (string) (is_array($record) ? $record['id'] : $record->id);
                }
            },
            column: 'lpr_repo_export.id',
            alias: 'id',
        );
        $this->assertSame([$id], $chunked);

        $restored = app(EloquentSerializer::class)->unserialize(
            app(EloquentSerializer::class)->serialize($listing())
        );
        $this->assertCount(1, $restored->find([$id]));
    }

    public function test_export_completes_for_ten_thousand_and_one_rows_when_one_in_list_exceeds_max_query_size(): void
    {
        $rowCount = 10001;
        $chunkSize = (new ReflectionClass(LprRepossessions::class))->getConstant('EXPORT_CHUNK_SIZE');
        $this->assertSame(10000, $chunkSize);

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $forwarder = (int) $user->org_id;
        $agent = 2_000_000_001;
        $this->insertOrg($forwarder, 'Forwarder A');
        $this->insertOrg($agent, 'Agent Co');
        $this->seedRecoveredClaimBatch($forwarder, $agent, $rowCount);

        $rows = LprRepossessionsQuery::rows($forwarder, $this->start(), $this->end());
        $this->assertCount($rowCount, $rows);

        $ids = array_map(strval(...), array_column($rows, 'id'));
        sort($ids, SORT_STRING);
        $firstChunk = array_slice($ids, 0, $chunkSize);

        $listing = fn () => LprRepossessionExportRow::query()->fromRaw(
            '('.LprRepossessionsQuery::rowsSql($forwarder, $this->start(), $this->end()).') AS lpr_repo_export'
        );

        $singleInBytes = strlen($this->boundClickhouseSql($listing()->whereKey($firstChunk)));
        $this->assertGreaterThan(262144, $singleInBytes);

        $found = $listing()->find($ids);
        $this->assertCount($rowCount, $found);

        $query = $listing();
        $totalRows = (int) $query->toBase()->getCountForPagination();
        $this->assertSame($rowCount, $totalRows);

        $columnMap = [
            'vin' => 'VIN',
            'plate' => 'Plate',
            'case_number' => 'RDN Case',
            'claimed_by' => 'Claimed By',
            'repo_agent_name' => 'Repo Agent Name',
        ];

        Storage::fake('s3');

        $export = new Export;
        $export->user()->associate($user);
        $export->exporter = LprRepossessionExporter::class;
        $export->total_rows = $totalRows;
        $export->file_disk = 's3';
        $export->file_name = 'lpr-repossession-export-test';
        $export->save();
        $export->unsetRelation('user');

        $serialized = app(EloquentSerializer::class)->serialize($query);

        Bus::chain([
            Bus::batch([
                new PrepareCsvExport(
                    export: $export,
                    query: $serialized,
                    columnMap: $columnMap,
                    options: [],
                    chunkSize: $chunkSize,
                ),
            ])->allowFailures(),
            new ExportCompletion(
                export: $export,
                columnMap: $columnMap,
                formats: [ExportFormat::Csv],
                options: [],
                authGuard: 'web',
            ),
        ])->dispatch();

        $export->refresh();
        $this->assertSame($rowCount, $export->total_rows);
        $this->assertSame($rowCount, $export->successful_rows);
        $this->assertSame($rowCount, $export->processed_rows);
        $this->assertNotNull($export->completed_at);

        $disk = Storage::disk('s3');
        $directory = $export->getFileDirectory();
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $disk->get($directory.'/headers.csv')) ?? '';
        $this->assertSame(
            ['VIN', 'Plate', 'RDN Case', 'Claimed By', 'Repo Agent Name'],
            str_getcsv(trim($header)),
        );

        $dataFiles = collect($disk->files($directory))
            ->reject(fn (string $path): bool => str_ends_with($path, 'headers.csv'))
            ->sort()
            ->values();
        $this->assertCount(2, $dataFiles);

        $written = [];
        $perFile = [];
        foreach ($dataFiles as $path) {
            $fileRows = [];
            foreach (preg_split("/\r\n|\n|\r/", trim($disk->get($path))) as $line) {
                if ($line === '') {
                    continue;
                }
                $record = str_getcsv($line);
                $fileRows[] = $record;
                $written[] = $record;
            }
            $perFile[] = count($fileRows);
        }

        $this->assertSame([10000, 1], $perFile);
        $this->assertCount($rowCount, $written);

        $expected = [];
        foreach (array_column($rows, 'vin') as $vin) {
            $expected[] = [(string) $vin, 'PLT', '', 'Agent Co', ''];
        }
        $this->assertEqualsCanonicalizing($expected, $written);
    }

    private function boundClickhouseSql(Builder $query): string
    {
        $connection = $query->getConnection();
        $this->assertInstanceOf(ClickhouseConnection::class, $connection);

        return $connection->getClient()->bindParams($query->toSql(), $query->getBindings()).' FORMAT JSON';
    }

    private function insertOrg(int $id, string $name): void
    {
        DB::connection('clarity_clickhouse')->table('zod.public_orgs')->insert([
            'id' => $id,
            'name' => $name,
            'type' => 1,
            '_peerdb_is_deleted' => 0,
            '_peerdb_version' => 1,
        ]);
    }

    private function seedRecoveredClaimBatch(int $forwarder, int $agent, int $count): void
    {
        $claimAt = Carbon::parse('2026-07-15 12:00:00', 'UTC');
        $repoAt = Carbon::parse('2026-07-16 12:00:00', 'UTC');
        $syncedAt = $claimAt->toDateTimeString();
        $now = now()->utc()->toDateTimeString();
        $eventType = BillingEventType::CASE_CLAIM->value;

        $claims = [];
        $hotlists = [];
        $clarityHotlists = [];
        $statusLogs = [];

        for ($i = 1; $i <= $count; $i++) {
            $vin = sprintf('L%016d', $i);

            $claims[] = [
                'id' => $i,
                'org_id' => $forwarder,
                'agent_org_id' => $agent,
                'vin' => $vin,
                'hotlist_id' => $i,
                'event_type' => $eventType,
                'success' => true,
                'is_internal' => 0,
                'event_date' => $claimAt->toDateString(),
                'ts' => $claimAt->timestamp,
                'api_key_id' => 0,
                '_peerdb_synced_at' => $syncedAt,
                '_peerdb_api_key_synced_at' => $syncedAt,
                '_version' => 1,
                '_peerdb_is_deleted' => 0,
            ];

            $hotlists[] = [
                'id' => $i,
                'integration_id' => 1,
                'status' => 3,
                'org_id' => $agent,
                'client_org_id' => $agent,
                'order_type' => 1,
                'is_staged' => false,
                'plate' => 'PLT',
                'us_state' => '',
                'vin' => $vin,
                'vehicle_year' => '',
                'vehicle_make' => '',
                'vehicle_model' => '',
                'lookback_days' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                '_peerdb_is_deleted' => 0,
                '_peerdb_version' => 1,
            ];

            $clarityHotlists[] = [
                'id' => $i,
                'org_id' => $agent,
                'status' => 3,
                'order_type' => 1,
                'is_staged' => false,
                'created_at' => $now,
                'updated_at' => $now,
                '_peerdb_synced_at' => $now,
                '_version' => 1,
                '_peerdb_is_deleted' => 0,
                'first_repossessed_at' => $repoAt->toDateTimeString(),
            ];

            $statusLogs[] = [
                'id' => $this->nextId++,
                'hotlist_id' => $i,
                'old_status' => 1,
                'new_status' => 3,
                'created_at' => $repoAt->toDateTimeString(),
                '_peerdb_is_deleted' => 0,
                '_peerdb_version' => 1,
            ];
        }

        $this->insertClickhouseRows('clarity.billing_claim_events', $claims);
        $this->insertClickhouseRows('zod.public_hotlist', $hotlists);
        $this->insertClickhouseRows('zod.public_hotlist_status_log', $statusLogs);
        $this->insertClickhouseRows('clarity.hotlist', $clarityHotlists);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertClickhouseRows(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::connection('clarity_clickhouse')->table($table)->insert($chunk);
        }
    }

    private function normalizeDbNames(string $sql): string
    {
        return strtr($sql, [
            (string) config('database.connections.clarity_clickhouse.database') => 'clarity',
            (string) config('database.connections.public_clickhouse.database') => 'zod',
        ]);
    }

    private function seedRecoveredClaim(
        int $forwarder,
        int $agent,
        string $vin,
        ?Carbon $claimAt = null,
        ?Carbon $repoAt = null,
        string $plate = '',
        ?string $caseNumber = null,
    ): int {
        $claimAt ??= Carbon::parse('2026-07-15 12:00:00', 'UTC');
        $repoAt ??= Carbon::parse('2026-07-16 12:00:00', 'UTC');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $agent, vin: $vin, ts: $claimAt);
        $hotlist = $this->seedHotlist(agentOrgId: $agent, vin: $vin, plate: $plate);
        $this->seedStatusLog(hotlistId: $hotlist, newStatus: 3, createdAt: $repoAt);
        $this->seedClarityHotlist(id: $hotlist, firstRepossessedAt: $repoAt, orgId: $agent);

        if ($caseNumber !== null) {
            $this->seedCaseNumber($hotlist, $caseNumber);
        }

        return $hotlist;
    }

    private function seedCaseNumber(int $hotlistId, ?string $caseNumber): void
    {
        DB::connection('clarity_clickhouse')->table('zod.hotlist_json_denormalized')->insert([
            'hotlist_id' => $hotlistId,
            'case_number' => $caseNumber,
            '_peerdb_synced_at' => now()->utc()->toDateTimeString(),
            '_peerdb_is_deleted' => 0,
            '_peerdb_version' => 1,
        ]);
    }

    private function start(): Carbon
    {
        return Carbon::parse(self::MONTH_START, 'UTC');
    }

    private function end(): Carbon
    {
        return Carbon::parse(self::MONTH_END, 'UTC');
    }

    private function seedOrg(string $name): int
    {
        $id = $this->nextId++;

        DB::connection('clarity_clickhouse')->table('zod.public_orgs')->insert([
            'id' => $id,
            'name' => $name,
            'type' => 1,
            '_peerdb_is_deleted' => 0,
            '_peerdb_version' => 1,
        ]);

        return $id;
    }

    private function seedClaim(
        int $forwarderOrgId,
        ?int $agentOrgId,
        string $vin,
        bool $success = true,
        string $eventType = BillingEventType::CASE_CLAIM->value,
        ?Carbon $ts = null,
        bool $deleted = false,
        bool $internal = false,
    ): void {
        $moment = ($ts ?? $this->start())->clone()->utc();

        DB::connection('clarity_clickhouse')->table('clarity.billing_claim_events')->insert([
            'id' => $this->nextId++,
            'org_id' => $forwarderOrgId,
            'agent_org_id' => $agentOrgId ?? 0,
            'vin' => $vin,
            'hotlist_id' => 999,
            'event_type' => $eventType,
            'success' => $success,
            'is_internal' => $internal ? 1 : 0,
            'event_date' => $moment->toDateString(),
            'ts' => $moment->timestamp,
            'api_key_id' => 0,
            '_peerdb_synced_at' => $moment->toDateTimeString(),
            '_peerdb_api_key_synced_at' => $moment->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => $deleted ? 1 : 0,
        ]);
    }

    private function seedHotlist(
        int $agentOrgId,
        string $vin,
        int $status = 3,
        int $version = 1,
        bool $deleted = false,
        ?int $id = null,
        string $plate = '',
    ): int {
        $id ??= $this->nextId++;
        $now = now()->toDateTimeString();

        DB::connection('clarity_clickhouse')->table('zod.public_hotlist')->insert([
            'id' => $id,
            'integration_id' => 1,
            'status' => $status,
            'org_id' => $agentOrgId,
            'client_org_id' => $agentOrgId,
            'order_type' => 1,
            'is_staged' => false,
            'plate' => $plate,
            'us_state' => '',
            'vin' => $vin,
            'vehicle_year' => '',
            'vehicle_make' => '',
            'vehicle_model' => '',
            'lookback_days' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            '_peerdb_is_deleted' => $deleted ? 1 : 0,
            '_peerdb_version' => $version,
        ]);

        return $id;
    }

    private function seedClarityHotlist(int $id, ?Carbon $firstRepossessedAt, int $orgId): void
    {
        $now = now()->utc()->toDateTimeString();

        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            'id' => $id,
            'org_id' => $orgId,
            'status' => 3,
            'order_type' => 1,
            'is_staged' => false,
            'created_at' => $now,
            'updated_at' => $now,
            '_peerdb_synced_at' => $now,
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
            'first_repossessed_at' => $firstRepossessedAt?->clone()->utc()->toDateTimeString(),
        ]);
    }

    private function seedStatusLog(
        int $hotlistId,
        int $newStatus,
        Carbon $createdAt,
        int $oldStatus = 1,
        bool $deleted = false,
    ): void {
        DB::connection('clarity_clickhouse')->table('zod.public_hotlist_status_log')->insert([
            'id' => $this->nextId++,
            'hotlist_id' => $hotlistId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'created_at' => $createdAt->toDateTimeString(),
            '_peerdb_is_deleted' => $deleted ? 1 : 0,
            '_peerdb_version' => 1,
        ]);
    }
}
