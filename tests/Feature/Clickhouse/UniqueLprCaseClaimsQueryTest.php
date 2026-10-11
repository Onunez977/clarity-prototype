<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Queries\UniqueLprCaseClaimsQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\BillingEventType;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clickhouse')]
class UniqueLprCaseClaimsQueryTest extends TestCase
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
    }

    public function test_dedup_key_is_the_full_three_column_tuple(): void
    {
        $forwarderA = $this->seedOrg('Forwarder A');
        $forwarderB = $this->seedOrg('Forwarder B');
        $agent = 5001;

        $this->seedClaim(forwarderOrgId: $forwarderA, agentOrgId: $agent, vin: '1FTFW1ET5BFC12345');
        $this->seedClaim(forwarderOrgId: $forwarderB, agentOrgId: $agent, vin: '1FTFW1ET5BFC12345');

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        // Same agent, same VIN, but via TWO different forwarders -- the stale
        // "distinct (vin, agent)" docblock prose would collapse this to 1.
        $this->assertSame(2, $result['all_claims']);
        $this->assertSame(2, $result['successful']);
        $this->assertSame(2, $result['deduped']);
    }

    public function test_insightlpr_excluded_on_forwarder_side(): void
    {
        $insightlpr = $this->seedOrg('InsightLPR, Inc.');
        $normal = $this->seedOrg('Acme Recovery');

        // Exclusion is the frozen is_internal flag on the denormalized row.
        $this->seedClaim(forwarderOrgId: $insightlpr, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', internal: true);
        $this->seedClaim(forwarderOrgId: $normal, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB');

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['all_claims']);
        $this->assertSame(1, $result['successful']);
        $this->assertSame(1, $result['deduped']);
    }

    public function test_insightlpr_excluded_on_agent_side(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $insightlprAgent = $this->seedOrg('insight-lpr');
        $normalAgent = 5002;

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $insightlprAgent, vin: 'AAAAAAAAAAAAAAAAA', internal: true);
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: $normalAgent, vin: 'BBBBBBBBBBBBBBBBB');

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['all_claims']);
        $this->assertSame(1, $result['deduped']);
    }

    public function test_titlemax_not_excluded(): void
    {
        $titlemax = $this->seedOrg('TitleMax');

        $this->seedClaim(forwarderOrgId: $titlemax, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA');

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['all_claims']);
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

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(0, $result['all_claims']);
    }

    public function test_event_type_is_the_discriminator_not_type(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        // A row with a different event_type entirely must be ignored, not just ls-claim.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', eventType: BillingEventType::VIN2PLATE->value);
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB');

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['all_claims']);
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

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $result['all_claims']);
    }

    public function test_either_side_org_axis(): void
    {
        $orgUnderTest = $this->seedOrg('Org Under Test');
        $otherForwarder = $this->seedOrg('Some Other Forwarder');

        // orgUnderTest is the FORWARDER on this claim.
        $this->seedClaim(forwarderOrgId: $orgUnderTest, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA');
        // orgUnderTest is the AGENT on this claim.
        $this->seedClaim(forwarderOrgId: $otherForwarder, agentOrgId: $orgUnderTest, vin: 'BBBBBBBBBBBBBBBBB');
        // orgUnderTest is on NEITHER side -- must be excluded when scoped.
        $this->seedClaim(forwarderOrgId: $otherForwarder, agentOrgId: 5002, vin: 'CCCCCCCCCCCCCCCCC');

        $result = UniqueLprCaseClaimsQuery::window($orgUnderTest, $this->start(), $this->end());

        $this->assertSame(2, $result['all_claims']);

        $platformWide = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());
        $this->assertSame(3, $platformWide['all_claims']);
    }

    public function test_missing_agent_org_id_counts_forwarder_side_only_without_double_counting(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        // A missing agent is stored as agent_org_id 0 on the denormalized row.
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: null, vin: 'AAAAAAAAAAAAAAAAA');

        $result = UniqueLprCaseClaimsQuery::window($forwarder, $this->start(), $this->end());

        $this->assertSame(1, $result['all_claims']);
        $this->assertSame(1, $result['deduped']);
    }

    public function test_empty_vin_excluded_from_deduped_but_counted_in_all_claims(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5001, vin: '');

        $result = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(1, $result['all_claims']);
        $this->assertSame(1, $result['successful']);
        $this->assertSame(0, $result['deduped']);
    }

    public function test_monthly_series_buckets_by_utc_calendar_month(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5001, vin: 'AAAAAAAAAAAAAAAAA', ts: Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $this->seedClaim(forwarderOrgId: $forwarder, agentOrgId: 5002, vin: 'BBBBBBBBBBBBBBBBB', ts: Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $series = UniqueLprCaseClaimsQuery::monthlySeries(null, Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $this->assertSame(1, $series['2026-06-01']['all_claims']);
        $this->assertSame(1, $series['2026-07-01']['all_claims']);
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

        UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());
    }

    private function start(): Carbon
    {
        return Carbon::parse(self::MONTH_START, 'UTC');
    }

    private function end(): Carbon
    {
        return Carbon::parse(self::MONTH_END, 'UTC');
    }

    /**
     * REGRESSION (review round 1, finding M4). Without this, replacing the whole
     * uniqExactIf((forwarder_org_id, agent_org_id, vin), success AND vin != '') expression
     * with a bare countIf(vin != '') -- i.e. removing BOTH the dedup and the success
     * filter, the two central counting rules of this card -- passed all 12 tests. Verified
     * by mutation: the mutant is green without the three tests below.
     */
    #[Group('clickhouse')]
    public function test_two_identical_triples_dedup_to_one(): void
    {
        $forwarder = $this->seedOrg('Forwarder Co');
        $agent = $this->seedOrg('Agent Co');

        // Same (forwarder, agent, vin) claimed twice -- e.g. a retried request.
        $this->seedClaim($forwarder, $agent, '1HGCM82633A004352');
        $this->seedClaim($forwarder, $agent, '1HGCM82633A004352');

        $r = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $r['all_claims'], 'both raw events must count');
        $this->assertSame(2, $r['successful'], 'both are successful');
        $this->assertSame(1, $r['deduped'], 'the identical triple must collapse to ONE billed claim');
    }

    #[Group('clickhouse')]
    public function test_failed_claims_count_in_all_but_not_successful_or_deduped(): void
    {
        $forwarder = $this->seedOrg('Forwarder Co');
        $agent = $this->seedOrg('Agent Co');

        $this->seedClaim($forwarder, $agent, '1HGCM82633A004352', success: true);
        $this->seedClaim($forwarder, $agent, '2HGCM82633A004353', success: false);

        $r = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $r['all_claims'], 'all_claims is raw -- failures included');
        $this->assertSame(1, $r['successful'], 'only the successful one');
        $this->assertSame(1, $r['deduped'], 'the failed claim must NOT reach the billed count');
    }

    #[Group('clickhouse')]
    public function test_same_vin_same_forwarder_two_agents_are_two_distinct_claims(): void
    {
        $forwarder = $this->seedOrg('Forwarder Co');
        $agentA = $this->seedOrg('Agent A');
        $agentB = $this->seedOrg('Agent B');

        // Same VIN, same forwarder, DIFFERENT agents -- the agent column must participate
        // in the grouping, so these are two billed claims, not one.
        $this->seedClaim($forwarder, $agentA, '1HGCM82633A004352');
        $this->seedClaim($forwarder, $agentB, '1HGCM82633A004352');

        $r = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());

        $this->assertSame(2, $r['deduped'], 'agent_org_id must be part of the dedup key');
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
            '_peerdb_is_deleted' => 0,
        ]);
    }
}
