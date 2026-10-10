<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Clickhouse\Queries\BillingClaimDedup;
use App\Clickhouse\Queries\ClickhouseQuery;
use App\Clickhouse\Queries\LprRepossessionsQuery;
use App\Clickhouse\Queries\UniqueLprCaseClaimsQuery;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Driver-free contract for the shared dedup triple and the two claim SQLs.
 * Feature tests that seed ClickHouse skip when the driver is not clickhouse;
 * these assertions still run.
 */
#[Group('clickhouse')]
class BillingClaimDedupTest extends TestCase
{
    public function test_triple_contains_forwarder_org_id_and_helper_does_not_emit_uniq(): void
    {
        $triple = BillingClaimDedup::tripleSql();

        $this->assertSame('(forwarder_org_id, agent_org_id, vin)', $triple);
        $this->assertStringContainsString('forwarder_org_id', $triple);
        $this->assertStringContainsString('agent_org_id', $triple);
        $this->assertStringContainsString('vin', $triple);

        $helper = file_get_contents(app_path('Clickhouse/Queries/BillingClaimDedup.php'));
        $this->assertIsString($helper);
        $this->assertStringNotContainsString('uniq', $helper);
        $this->assertStringNotContainsString('function __construct', $helper);
    }

    public function test_deduped_cte_filters_success_and_non_empty_vin(): void
    {
        $cte = BillingClaimDedup::dedupedCteSql();

        $this->assertStringContainsString('FROM claims', $cte);
        $this->assertStringContainsString('WHERE success', $cte);
        $this->assertStringContainsString("vin != ''", $cte);
        $this->assertStringContainsString('min(claim_date) AS claim_date', $cte);
        $this->assertStringContainsString('GROUP BY bucket, forwarder_org_id, agent_org_id, vin', $cte);
        $this->assertStringNotContainsString('uniq', $cte);
    }

    public function test_both_rendered_claim_sqls_filter_is_internal_and_read_the_view_once(): void
    {
        $start = Carbon::parse('2026-07-01 00:00:00', 'UTC');
        $end = Carbon::parse('2026-07-31 23:59:59', 'UTC');

        $claims = UniqueLprCaseClaimsQuery::windowSql(0, $start, $end);
        $preClaim = UniqueLprCaseClaimsQuery::windowWithPreClaimScansSql(0, $start, $end);
        $repos = LprRepossessionsQuery::windowSql(0, $start, $end);

        foreach ([$claims, $preClaim, $repos] as $sql) {
            $this->assertStringContainsString('is_internal = 0', $sql);
            $this->assertStringContainsString('billing_claim_events_v', $sql);
            $this->assertStringContainsString("toDate('2026-07-01')", $sql);
            $this->assertStringContainsString("toDate('2026-07-31')", $sql);
            $this->assertStringContainsString('uniqExact', $sql);
            $this->assertStringNotContainsString('uniq(', $sql);
            $this->assertStringNotContainsString('JSONExtract', $sql);
            $this->assertStringNotContainsString('public_api_keys', $sql);
            $this->assertStringNotContainsString('public_billing_events', $sql);
            $this->assertStringNotContainsString('clarity.orgs', $sql);
            $this->assertStringNotContainsString('public_orgs', $sql);
            $this->assertStringNotContainsString('insightlpr', $sql);
            $this->assertSame(1, substr_count($sql, 'billing_claim_events_v'));
        }

        $this->assertStringContainsString('uniqExactIf('.BillingClaimDedup::tripleSql(), $claims);
        $this->assertStringContainsString('count()', $claims);
        $this->assertStringContainsString('countIf(success)', $claims);
        $this->assertStringContainsString('(0 = 0 OR org_id = 0 OR agent_org_id = 0)', $claims);

        $this->assertSame(2, substr_count($preClaim, 'FROM oc'));
        $this->assertStringContainsString('hits_cold', $preClaim);
        $this->assertStringContainsString('h.hotlist_id != 0', $preClaim);

        $this->assertStringContainsString(BillingClaimDedup::dedupedCteSql(), $repos);
        $this->assertSame(1, substr_count($repos, 'FROM deduped'));
        $this->assertStringContainsString('d.agent_org_id != 0', $repos);
        $this->assertStringContainsString('h.id != 0', $repos);
        $this->assertStringContainsString('public_hotlist FINAL', $repos);
        $this->assertDoesNotMatchRegularExpression(
            '/\.hotlist\s+FINAL/',
            $repos,
            'FINAL stays on public_hotlist; clarity.hotlist must not use FINAL'
        );
        $this->assertStringNotContainsString('public_hotlist_status_log', $repos);
        $this->assertStringContainsString('first_repossessed_at', $repos);
        $this->assertStringContainsString('.hotlist', $repos);
        $this->assertMatchesRegularExpression('/\.hotlist\s+WHERE/', $repos);
        $this->assertStringContainsString("toDate('2026-06-24')", $repos);

        $this->assertFalse(is_subclass_of(UniqueLprCaseClaimsQuery::class, ClickhouseQuery::class));
        $this->assertFalse(is_subclass_of(LprRepossessionsQuery::class, ClickhouseQuery::class));

        foreach ([
            app_path('Clickhouse/Queries/UniqueLprCaseClaimsQuery.php'),
            app_path('Clickhouse/Queries/LprRepossessionsQuery.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('ClickhouseQuery::query', $source);
            $this->assertStringNotContainsString('type IN (1, 2)', $source);
            $this->assertStringNotContainsString('type IN (1,2)', $source);
            $this->assertStringContainsString('does not read `clarity.orgs`', $source);
            $this->assertStringContainsString('is_internal = 0', $source);
        }
    }
}
