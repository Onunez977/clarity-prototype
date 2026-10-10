<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Queries\UniqueLprCaseClaimsQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\BillingEventType;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * DEV-2465: the pre-claim-scan numerator added on top of UniqueLprCaseClaimsQuery's
 * existing claims funnel. See docs/investigations/DEV-2465/measure-rate-card.sql for
 * the canonical, measured SQL shape these tests pin.
 */
#[Group('clickhouse')]
class UniqueScansToCaseClaimsQueryTest extends TestCase
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

    public function test_hitless_claim_is_not_counted_as_pre_claim_join_miss_guard(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');

        // No hits_cold row at all for this hotlist_id -- the LEFT JOIN misses entirely.
        $this->seedClaim($forwarder, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 100);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(
            0,
            $result['pre_claim'],
            'without the h.hotlist_id != 0 guard, the 1970-01-01 default-fill makes a hitless claim look pre-claim',
        );
    }

    public function test_pre_claim_requires_strictly_before_claim_timestamp(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($forwarder, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 200, ts: $claimTs);
        $this->seedHit(200, $claimTs->clone()->subSecond(), $forwarder);

        $this->seedClaim($forwarder, 5002, 'BBBBBBBBBBBBBBBBB', hotlistId: 201, ts: $claimTs);
        $this->seedHit(201, $claimTs->clone(), $forwarder);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(2, $result['deduped']);
        $this->assertSame(1, $result['pre_claim'], 'a hit at (not before) the claim instant must not count');
    }

    public function test_triple_grain_numerator_cannot_exceed_denominator(): void
    {
        $forwarder = $this->seedOrg('Forwarder Co');
        $agent = $this->seedOrg('Agent Co');
        $vin = '1HGCM82633A004352';
        $claimTs = $this->start()->clone()->addHour();

        // Same (forwarder, agent, vin) triple claimed via TWO different hotlist orders.
        $this->seedClaim($forwarder, $agent, $vin, hotlistId: 300, ts: $claimTs);
        $this->seedClaim($forwarder, $agent, $vin, hotlistId: 301, ts: $claimTs);

        // Both orders have a pre-claim hit -- if the numerator were counted per-order
        // (rather than per-triple) this would count 2, pushing the rate above 100%.
        $this->seedHit(300, $claimTs->clone()->subMinute(), $forwarder);
        $this->seedHit(301, $claimTs->clone()->subMinute(), $forwarder);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped'], 'the triple must collapse to ONE billed claim');
        $this->assertSame(1, $result['pre_claim'], 'the triple must collapse to ONE pre-claim, not 2');
    }

    public function test_insightlpr_excluded_titlemax_not_excluded_from_pre_claim(): void
    {
        $insightlpr = $this->seedOrg('InsightLPR, Inc.');
        $titlemax = $this->seedOrg('TitleMax');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($insightlpr, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 400, ts: $claimTs, internal: true);
        $this->seedHit(400, $claimTs->clone()->subMinute(), $insightlpr);

        $this->seedClaim($titlemax, 5002, 'BBBBBBBBBBBBBBBBB', hotlistId: 401, ts: $claimTs);
        $this->seedHit(401, $claimTs->clone()->subMinute(), $titlemax);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped'], 'the is_internal claim must not be counted at all');
        $this->assertSame(1, $result['pre_claim'], 'titlemax must NOT be excluded from the counting path');
    }

    /**
     * H1 REGRESSION (review round 1, unanimous). The order is owned by the FORWARDER
     * (hotlist_org_id = forwarder), but the query is scoped to the AGENT org. The removed
     * `hotlist_org_id = {org}` predicate on the `h` CTE would have excluded this hit
     * entirely once the AGENT-scoped query ran, silently zeroing its pre_claim credit even
     * though the claim itself is correctly counted (agent_org_id = {org} in `kept`). This
     * test MUST fail if that predicate is reintroduced -- verified by temporarily
     * re-adding it, watching this assertion fail, then removing it again.
     */
    public function test_org_scoped_agent_side_gets_pre_claim_credit_when_hotlist_org_is_forwarder(): void
    {
        $forwarder = $this->seedOrg('Forwarder Owns Order');
        $agent = $this->seedOrg('Agent Claims It');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($forwarder, $agent, 'AAAAAAAAAAAAAAAAA', hotlistId: 700, ts: $claimTs);
        // The order (and its hit) belongs to the FORWARDER, not the agent we scope by below.
        $this->seedHit(700, $claimTs->clone()->subMinute(), $forwarder);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans($agent, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped'], 'the agent side must still be counted in the denominator');
        $this->assertSame(
            1,
            $result['pre_claim'],
            'the AGENT-side org must still get numerator credit even though the order is owned by the forwarder',
        );
    }

    /** Symmetric case: the order is owned by the AGENT, scoped to the FORWARDER org. */
    public function test_org_scoped_forwarder_side_gets_pre_claim_credit_when_hotlist_org_is_agent(): void
    {
        $forwarder = $this->seedOrg('Forwarder Claims It');
        $agent = $this->seedOrg('Agent Owns Order');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($forwarder, $agent, 'BBBBBBBBBBBBBBBBB', hotlistId: 701, ts: $claimTs);
        // The order (and its hit) belongs to the AGENT, not the forwarder we scope by below.
        $this->seedHit(701, $claimTs->clone()->subMinute(), $agent);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans($forwarder, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped'], 'the forwarder side must still be counted in the denominator');
        $this->assertSame(
            1,
            $result['pre_claim'],
            'the FORWARDER-side org must still get numerator credit even though the order is owned by the agent',
        );
    }

    /** Tenant isolation: a THIRD org's claim/hit must not leak into an org-scoped query. */
    public function test_org_scoped_query_does_not_leak_a_third_orgs_claim(): void
    {
        $forwarder = $this->seedOrg('Scoped Forwarder');
        $agent = $this->seedOrg('Scoped Agent');
        $other = $this->seedOrg('Unrelated Third Org');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($forwarder, $agent, 'CCCCCCCCCCCCCCCCC', hotlistId: 702, ts: $claimTs);
        $this->seedHit(702, $claimTs->clone()->subMinute(), $forwarder);

        // A wholly unrelated org's claim, with no participation from $forwarder or $agent.
        $this->seedClaim($other, 5099, 'DDDDDDDDDDDDDDDDD', hotlistId: 703, ts: $claimTs);
        $this->seedHit(703, $claimTs->clone()->subMinute(), $other);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans($forwarder, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped'], 'the third org claim must not leak into the scoped denominator');
        $this->assertSame(1, $result['pre_claim'], 'the third org hit must not leak into the scoped numerator');
    }

    public function test_zero_denominator_window_returns_zero_without_error(): void
    {
        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(0, $result['deduped']);
        $this->assertSame(0, $result['pre_claim']);
    }

    public function test_monthly_series_with_pre_claim_scans_buckets_by_utc_month(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $juneTs = Carbon::parse('2026-06-15 12:00:00', 'UTC');
        $julyTs = Carbon::parse('2026-07-15 12:00:00', 'UTC');

        $this->seedClaim($forwarder, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 600, ts: $juneTs);
        $this->seedHit(600, $juneTs->clone()->subHour(), $forwarder);

        // No hit at all for the July claim.
        $this->seedClaim($forwarder, 5002, 'BBBBBBBBBBBBBBBBB', hotlistId: 601, ts: $julyTs);

        $series = UniqueLprCaseClaimsQuery::monthlySeriesWithPreClaimScans(null, Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $this->assertSame(['deduped', 'pre_claim'], array_keys($series['2026-06-01']));
        $this->assertSame(1, $series['2026-06-01']['pre_claim']);
        $this->assertSame(0, $series['2026-07-01']['pre_claim']);
    }

    public function test_window_and_monthly_series_still_return_exactly_the_original_three_keys(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $this->seedClaim($forwarder, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 500);

        $window = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());
        $this->assertSame(['all_claims', 'successful', 'deduped'], array_keys($window));

        $series = UniqueLprCaseClaimsQuery::monthlySeries(null, $this->end());
        foreach ($series as $bucket) {
            $this->assertSame(['all_claims', 'successful', 'deduped'], array_keys($bucket));
        }
    }

    /**
     * AC-2's real invariant, which the key-shape test above does NOT cover (review round 5).
     * The two paths compute `deduped` with DIFFERENT expressions over different CTEs:
     * window() uses uniqExactIf((forwarder_org_id, agent_org_id, vin), success AND vin != '')
     * over `kept`, while the DEV-2465 path uses uniqExact(trip) over `flagged`. They are
     * provably equal today -- `oc` groups exactly that filtered triple, and `h` is unique on
     * hotlist_id so the LEFT JOIN cannot fan out -- but nothing pinned it, so an edit to
     * either expression would silently make this card's denominator disagree with the card two
     * rows above it in the same grid. That "near-duplicate that disagrees" is the exact trap
     * the ticket cites, so assert the equality directly, on both seams.
     */
    public function test_the_denominator_matches_dev_2463s_deduped_exactly_on_both_seams(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $agent = $this->seedOrg('Agent Co');

        // A deliberately awkward mix: a shared VIN across two agents (two distinct triples),
        // one duplicate claim of the same triple (must collapse), one hitless order, one
        // unsuccessful claim (excluded from both), and one hotlist-less claim.
        $this->seedClaim($forwarder, $agent, 'AAAAAAAAAAAAAAAAA', hotlistId: 700);
        $this->seedClaim($forwarder, null, 'AAAAAAAAAAAAAAAAA', hotlistId: 701);
        $this->seedClaim($forwarder, $agent, 'AAAAAAAAAAAAAAAAA', hotlistId: 702);
        $this->seedClaim($forwarder, $agent, 'BBBBBBBBBBBBBBBBB', hotlistId: 703);
        $this->seedClaim($forwarder, $agent, 'CCCCCCCCCCCCCCCCC', hotlistId: 704, success: false);
        $this->seedClaim($forwarder, $agent, 'DDDDDDDDDDDDDDDDD', hotlistId: 0);

        $this->seedHit(700, $this->start()->copy()->subDay(), $forwarder);
        $this->seedHit(703, $this->start()->copy()->addDays(2), $forwarder);

        $base = UniqueLprCaseClaimsQuery::window(null, $this->start(), $this->end());
        $extended = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(
            $base['deduped'],
            $extended['deduped'],
            'the card denominator must be the SAME number DEV-2463 renders, not a near-duplicate',
        );

        $baseSeries = UniqueLprCaseClaimsQuery::monthlySeries(null, $this->end());
        $extendedSeries = UniqueLprCaseClaimsQuery::monthlySeriesWithPreClaimScans(null, $this->end());

        // Both directions: iterating $baseSeries alone would not notice an EXTRA bucket
        // appearing on the extended side, and indexing a missing one would surface as an
        // undefined-key error rather than a clean failure (review round 6).
        $this->assertSame(
            array_keys($baseSeries),
            array_keys($extendedSeries),
            'both seams must bucket the same 13 months, or the per-bucket comparison below is not comparing like with like',
        );

        foreach ($baseSeries as $bucket => $row) {
            $this->assertSame(
                $row['deduped'],
                $extendedSeries[$bucket]['deduped'],
                "bucket {$bucket}: the monthly denominator must match DEV-2463's exactly",
            );
        }

        // Guard the guard: a test that compared 0 to 0 would pass vacuously.
        $this->assertGreaterThan(0, $base['deduped'], 'precondition: the fixture must produce a non-zero denominator');
    }

    /**
     * AC-3, re-derived (review round 3, S5(c)). The original version of this test seeded two
     * IDENTICAL duplicate rows, which any hits-side aggregate (count/max/sum, not just min())
     * resolves the same way -- it could not fail even if `min()` were replaced by a different
     * aggregate, so it pinned nothing. This version seeds two DISTINCT hits for the same
     * order: one before the claim, one after. min(scanned_at) must resolve to the EARLIER
     * (pre-claim) reading; max() would instead pick the later, post-claim reading and flip
     * this false.
     */
    public function test_pre_claim_uses_the_earliest_hit_not_any_hit(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($forwarder, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 800, ts: $claimTs);

        $this->seedHit(800, $claimTs->clone()->subMinute(), $forwarder);
        $this->seedHit(800, $claimTs->clone()->addMinute(), $forwarder);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(
            1,
            $result['pre_claim'],
            'min() must resolve to the EARLIER hit; max() would pick the later, post-claim hit and give 0',
        );
    }

    /**
     * S3: hits_cold.scanned_at is non-Nullable with no DDL default, but
     * _peerdb_scan_synced_at DEFAULTs to toDateTime('1970-01-01') -- a row synced before its
     * scan facet lands can carry scanned_at at that 1970 default, which precedes every claim.
     * Without the explicit `scanned_at > toDateTime(0)` guard, min() would return it and the
     * order would count as pre-claim even though no real sighting supports that.
     */
    public function test_epoch_zero_scanned_at_does_not_count_as_pre_claim(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($forwarder, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 800, ts: $claimTs);
        $this->seedHit(800, Carbon::createFromTimestamp(0, 'UTC'), $forwarder);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(
            0,
            $result['pre_claim'],
            'a hits_cold row carrying the 1970 _peerdb_scan_synced_at default must not count as a pre-claim sighting',
        );
    }

    /**
     * S5(a): `h.hotlist_id != 0` guards a claim whose denormalized hotlist_id is 0.
     * Without this guard, the join-miss default-fill on the `h` side is the only thing
     * standing between a hotlist-less claim and a false pre-claim credit.
     */
    public function test_claim_with_no_assigned_hotlist_id_is_not_counted_as_pre_claim(): void
    {
        $forwarder = $this->seedOrg('Acme Recovery');
        $claimTs = $this->start()->clone()->addHour();

        $this->seedClaim($forwarder, 5001, 'AAAAAAAAAAAAAAAAA', hotlistId: 0, ts: $claimTs);

        // A REAL hits_cold row carrying hotlist_id = 0, scanned before the claim -- the most
        // adversarial input for a hotlist-less claim, since the join can match on 0 => 0
        // rather than merely missing. This test asserts the BEHAVIOUR (such a claim never
        // counts as pre-claim); it does not pin a particular conjunct, because h.hotlist_id
        // != 0 is sufficient on its own -- the join is an equality, so a 0 => 0 match still
        // yields h.hotlist_id = 0 (round 4, T3).
        $this->seedHit(0, $claimTs->clone()->subDay(), $forwarder);

        $result = UniqueLprCaseClaimsQuery::windowWithPreClaimScans(null, $this->start(), $this->end());

        $this->assertSame(1, $result['deduped']);
        $this->assertSame(
            0,
            $result['pre_claim'],
            'a claim with no assigned hotlist_id (0) must never count as pre-claim, even when '
            .'a hits_cold row also carries hotlist_id = 0',
        );
    }

    /**
     * Review round 2, R1. The scan count is verified by RENDERING the SQL and counting
     * occurrences of `billing_claim_events_v` in the fully-inlined text -- ClickHouse
     * inlines a CTE at EVERY reference, so a naive `FROM claims` string count is blind to a CTE
     * (like `oc`) that is itself referenced more than once. The view name must not appear in
     * SQL comments, or this count inflates. window()/monthlySeries() must stay at a single
     * scan. Review round 3 (S1) deleted the `stats` CTE. The pre-claim variants still land at
     * 2 because `oc` is referenced twice -- once by `flagged` and once by `h`'s pruning
     * subquery -- and `claims` is inlined once into each of those two `oc` expansions.
     */
    public function test_kept_expansion_counts_are_the_true_reflection_measured_floor(): void
    {
        $render = new \ReflectionMethod(UniqueLprCaseClaimsQuery::class, 'sql');
        $render->setAccessible(true);

        $countBaseTableRefs = fn (bool $bucketed, bool $withPreClaimScans): int => substr_count(
            $this->fullyInlineCtes($render->invoke(null, $bucketed, $withPreClaimScans)),
            'billing_claim_events_v',
        );

        $this->assertSame(1, $countBaseTableRefs(false, false), 'window()');
        $this->assertSame(1, $countBaseTableRefs(true, false), 'monthlySeries()');
        $this->assertSame(2, $countBaseTableRefs(false, true), 'windowWithPreClaimScans() -- 2, via `oc`\'s two references (flagged\'s aggregate and h\'s pruning IN-subquery), NOT via a separate all_claims/successful pass (stats was deleted, review round 3 S1).');
        $this->assertSame(2, $countBaseTableRefs(true, true), 'monthlySeriesWithPreClaimScans() -- same shape as the non-bucketed variant.');
    }

    /**
     * AC-3, made mutation-proof (review round 6). Until now "every aggregate uses uniqExact; a
     * bare count() in the numerator is a defect" was enforced by review reading alone -- swapping
     * uniqExact(trip) for count(trip) would have inflated the numerator by the duplicate factor
     * (measured 1.54x on this population) with the whole suite still green, because every other
     * test seeds fixtures too small for the two to disagree.
     *
     * Asserted against the FULLY-INLINED render so a count() hidden inside a CTE cannot escape,
     * and scoped to the pre-claim branch only -- the DEV-2463 branch's count()/countIf() are its
     * documented all_claims/successful funnel stages and must stay.
     */
    public function test_the_pre_claim_numerator_contains_no_bare_count_aggregate(): void
    {
        $render = new \ReflectionMethod(UniqueLprCaseClaimsQuery::class, 'sql');
        $render->setAccessible(true);

        foreach ([false, true] as $bucketed) {
            $sql = $this->fullyInlineCtes($render->invoke(null, $bucketed, true));

            // Strip SQL comments first: the shipped SQL discusses count()/countIf() in prose.
            $code = preg_replace('/--[^\n]*/', '', $sql);

            $label = $bucketed ? 'monthlySeriesWithPreClaimScans()' : 'windowWithPreClaimScans()';
            $this->assertSame(
                0,
                preg_match_all('/(?<![a-zA-Z_])count\s*\(/i', $code),
                $label.' must not use count()/countIf() anywhere in the pre-claim path -- '
                .'a bare count() over hits inflates the numerator ~1.54x on this population',
            );
            $this->assertGreaterThan(
                0,
                preg_match_all('/uniqExact(If)?\s*\(/i', $code),
                $label.' must aggregate with uniqExact -- precondition, so the assertion above cannot pass vacuously',
            );
        }
    }

    /**
     * Expands every CTE reference in `$sql` to the point where the base table name is
     * visible as many times as ClickHouse will actually read it -- i.e. it counts `FROM
     * <cte>` / `JOIN <cte>` occurrences (each a genuine re-materialisation), NOT bare
     * `alias.column` references to an already-joined CTE (which cost nothing extra). This is
     * the invariant the previous verification missed: a `FROM kept` string count is blind to
     * `kept` being reached twice through a doubly-referenced intermediate CTE like `oc`.
     */
    private function fullyInlineCtes(string $sql): string
    {
        $pos = 0;
        $len = \strlen($sql);
        $ws = '(?:\s|--[^\n]*)*';

        if (! preg_match('/^\s*WITH\s*/i', $sql, $m)) {
            return $sql;
        }
        $pos += \strlen($m[0]);

        $ctes = [];
        while (preg_match('/\G'.$ws.'([A-Za-z_][A-Za-z0-9_]*)\s+AS\s*\(/i', $sql, $m, 0, $pos)) {
            $name = $m[1];
            $pos += \strlen($m[0]);
            $bodyStart = $pos;
            $depth = 1;

            while ($depth > 0 && $pos < $len) {
                $depth += match ($sql[$pos]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $pos++;
            }

            $ctes[$name] = substr($sql, $bodyStart, $pos - $bodyStart - 1);

            if (! preg_match('/\G'.$ws.',/', $sql, $m2, 0, $pos)) {
                break;
            }
            $pos += \strlen($m2[0]);
        }

        $expandRefs = function (string $text, array $allowed) use (&$ctes): string {
            return preg_replace_callback(
                '/\b(FROM|JOIN)\s+([A-Za-z_][A-Za-z0-9_]*)\b/i',
                fn (array $m) => \in_array($m[2], $allowed, true) ? $m[1].' ('.$ctes[$m[2]].')' : $m[0],
                $text,
            );
        };

        $names = array_keys($ctes);
        foreach ($names as $i => $name) {
            $ctes[$name] = $expandRefs($ctes[$name], \array_slice($names, 0, $i));
        }

        return $expandRefs(substr($sql, $pos), $names);
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
        int $hotlistId,
        bool $success = true,
        ?Carbon $ts = null,
        bool $internal = false,
    ): void {
        $moment = ($ts ?? $this->start())->clone()->utc();

        DB::connection('clarity_clickhouse')->table('clarity.billing_claim_events')->insert([
            'id' => $this->nextId++,
            'org_id' => $forwarderOrgId,
            'agent_org_id' => $agentOrgId ?? 0,
            'vin' => $vin,
            'hotlist_id' => $hotlistId,
            'event_type' => BillingEventType::CASE_CLAIM->value,
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

    private function seedHit(int $hotlistId, Carbon $scannedAt, int $hotlistOrgId, ?int $id = null): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')->insert([
            'id' => $id ?? $this->nextId++,
            'hotlist_id' => $hotlistId,
            'scan_id' => $this->nextId++,
            'hit_type' => 'L',
            'hit_created_at' => now()->timestamp,
            'ocr' => 'ABC1234',
            // A true UTC epoch, NOT a naive toDateTimeString(): DateTime columns with no
            // explicit timezone parameter interpret a naive string in the SERVER's
            // timezone. An integer is always read as a UTC Unix timestamp regardless of
            // server timezone, so boundary assertions against a true epoch can't flip on
            // a non-UTC CH server.
            'scanned_at' => $scannedAt->clone()->utc()->timestamp,
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => 1,
            'kit_name' => 'Test Kit',
            'kit_org_id' => $hotlistOrgId,
            'hotlist_org_id' => $hotlistOrgId,
            '_peerdb_synced_at' => now()->timestamp,
            '_peerdb_hotlist_synced_at' => now()->timestamp,
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ]);
    }
}
