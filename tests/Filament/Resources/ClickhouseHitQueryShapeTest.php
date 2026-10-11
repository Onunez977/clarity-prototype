<?php

namespace Tests\Filament\Resources;

use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Enums\HotlistStatusEnum;
use App\Models\Kit;
use App\Models\User;
use App\Support\Hits\HitsListQuery;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * DEV-2614 — query SHAPE invariants for the Hits ClickHouse scopes.
 *
 * Compiled SQL and bindings only; no ClickHouse connection is needed. Tests that assert
 * ClickHouse grammar (backticks, FINAL) skip on the pgsql bridge driver.
 */
class ClickhouseHitQueryShapeTest extends TestCase
{
    private function requireClickhouseGrammar(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'clickhouse') {
            $this->markTestSkipped('Asserts ClickHouse grammar; the pgsql bridge compiles Postgres SQL and final() is a no-op there.');
        }
    }

    private function bare(string $sql): string
    {
        return str_replace(['`', '"'], '', $sql);
    }

    #[Group('clickhouse')]
    public function test_for_dispositions_new_hit_alone_keeps_null_and_the_literal(): void
    {
        $sql = (new ClickhouseHit)->newQuery()->forDispositions(['New Hit'])->toSql();

        $this->assertStringContainsString('disposition IS NULL', $sql);
        $this->assertStringContainsString("'New Hit'", $sql);
    }

    #[Group('clickhouse')]
    public function test_for_dispositions_new_hit_with_others_keeps_null_the_literal_and_the_others(): void
    {
        $sql = (new ClickhouseHit)->newQuery()->forDispositions(['New Hit', 'Accessible'])->toSql();

        $this->assertStringContainsString('disposition IS NULL', $sql);
        $this->assertStringContainsString("'New Hit'", $sql);
        $this->assertStringContainsString("'Accessible'", $sql);
    }

    #[Group('clickhouse')]
    public function test_for_dispositions_without_new_hit_does_not_match_null(): void
    {
        $sql = (new ClickhouseHit)->newQuery()->forDispositions(['Accessible'])->toSql();

        $this->assertStringNotContainsString('IS NULL', $sql);
        $this->assertStringNotContainsString('New Hit', $sql);
        $this->assertStringContainsString("disposition IN ('Accessible')", $sql);
    }

    #[Group('clickhouse')]
    public function test_for_dispositions_null_or_empty_still_matches_only_null(): void
    {
        $this->requireClickhouseGrammar();

        foreach ([null, []] as $input) {
            $sql = (new ClickhouseHit)->newQuery()->forDispositions($input)->toSql();

            $this->assertStringContainsString('`disposition` is null', $sql);
            $this->assertStringNotContainsString('New Hit', $sql);
        }
    }

    #[Group('clickhouse')]
    public function test_valid_disposition_sql_is_unchanged_for_scout(): void
    {
        $this->requireClickhouseGrammar();

        $query = (new ClickhouseHit)->newQuery()->validDisposition();

        $this->assertSame(
            "select * from `hits_cold` where (disposition IS NULL OR disposition IN ('Valid')) and _peerdb_is_deleted = 0",
            $query->toSql(),
        );
        $this->assertSame([], $query->getBindings());
        $this->assertStringNotContainsString('New Hit', $query->toSql());
    }

    #[Group('clickhouse')]
    public function test_actionable_disposition_sql_keeps_null_the_literal_and_accessible(): void
    {
        $this->requireClickhouseGrammar();

        $this->assertSame(
            "select * from `hits_cold` where (disposition IS NULL OR disposition IN ('New Hit','Accessible')) and _peerdb_is_deleted = 0",
            (new ClickhouseHit)->newQuery()->actionableDisposition()->toSql(),
        );
    }

    #[Group('clickhouse')]
    public function test_none_of_the_scopes_add_final(): void
    {
        $queries = [
            (new ClickhouseHit)->newQuery()->forDispositions(['New Hit', 'Accessible']),
            (new ClickhouseHit)->newQuery()->validDisposition(),
        ];

        foreach ($queries as $query) {
            $this->assertStringNotContainsStringIgnoringCase('final', $query->toSql());
        }
    }

    /**
     * @return array{user: User, kit: Kit}
     */
    private function seedHitsScenario(bool $withKit = true): array
    {
        Config::set('app.experimental.clickhouse.hits', true);

        $user = $this->createUser();
        $kit = Kit::factory()->forOrg($user->org)->create();
        Kit::forgetCachedKits((int) $user->org_id);

        if (! $withKit) {
            $kit->delete();
            Kit::forgetCachedKits((int) $user->org_id);
        }

        $this->actingAs($user);

        return compact('user', 'kit');
    }

    private function whereOf(string $sql): string
    {
        $bare = $this->bare($sql);

        return substr($bare, (int) stripos($bare, ' where '));
    }

    #[Group('clickhouse')]
    public function test_the_real_base_query_scopes_by_kit_only_on_the_cold_table(): void
    {
        $this->requireClickhouseGrammar();

        $s = $this->seedHitsScenario();

        $sql = HitsListQuery::baseQuery($s['user'])->toSql();
        $where = $this->whereOf($sql);

        $this->assertStringContainsString('hits_cold', $sql);
        $this->assertStringNotContainsString('hotlist_org_id', $where);
        $this->assertStringContainsString('kit_id in', $where);
        $this->assertStringContainsString((string) $s['kit']->id, $where);
        $this->assertStringContainsString('_peerdb_is_deleted', $where);
        $this->assertStringNotContainsString('0 = 1', $where);
        $this->assertStringNotContainsStringIgnoringCase('final', $sql);
    }

    #[Group('clickhouse')]
    public function test_the_real_base_query_scopes_by_kit_only_on_the_hot_table(): void
    {
        $this->requireClickhouseGrammar();

        $s = $this->seedHitsScenario();

        $sql = HitsListQuery::baseQuery($s['user'], now()->subDays(7)->toDateTimeString())->toSql();
        $where = $this->whereOf($sql);

        $this->assertStringContainsString('hits_hot', $sql);
        $this->assertStringNotContainsString('hotlist_org_id', $where);
        $this->assertStringContainsString('kit_id in', $where);
        $this->assertStringContainsString((string) $s['kit']->id, $where);
        $this->assertStringContainsString('_peerdb_is_deleted', $where);
        // Hot only: FINAL drops stale status re-emissions and duplicate rows (DEV-2614 prod benchmark).
        $this->assertMatchesRegularExpression('/from `?hits_hot`? final where/i', $sql);
    }

    #[Group('clickhouse')]
    public function test_the_real_base_query_keeps_the_nicb_and_hit_type_clauses(): void
    {
        $s = $this->seedHitsScenario();

        $open = $this->whereOf(HitsListQuery::baseQuery($s['user'])->toSql());
        $this->assertStringContainsString('hit_type != ', $open);
        $this->assertStringNotContainsString('hit_type NOT IN', $open);
        $this->assertStringContainsString('integration_id', $open);

        $repossessed = $this->whereOf(HitsListQuery::baseQuery($s['user'], null, HotlistStatusEnum::Repossessed->value)->toSql());
        $this->assertStringContainsString('hit_type NOT IN', $repossessed);
    }

    #[Group('clickhouse')]
    public function test_an_empty_kit_set_still_degrades_to_match_nothing(): void
    {
        $s = $this->seedHitsScenario(withKit: false);

        foreach ([null, now()->subDays(7)->toDateTimeString()] as $windowStart) {
            $where = $this->whereOf(HitsListQuery::baseQuery($s['user'], $windowStart)->toSql());

            $this->assertStringContainsString('0 = 1', $where);
            $this->assertStringNotContainsString('in (0)', $where);
        }
    }

    #[Group('clickhouse')]
    public function test_the_postgres_path_scopes_by_kit(): void
    {
        $s = $this->seedHitsScenario();
        Config::set('app.experimental.clickhouse.hits', false);

        $sql = HitsListQuery::baseQuery($s['user'])->toSql();

        $this->assertStringContainsString('"hits"."kit_id"', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bwhere\b.*\bhotlist_org_id\b/i', $sql);
    }
}
