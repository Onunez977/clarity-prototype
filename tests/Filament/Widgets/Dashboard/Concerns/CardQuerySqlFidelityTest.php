<?php

namespace Tests\Filament\Widgets\Dashboard\Concerns;

use App\Clickhouse\Queries\HitPlateStatsBatchQuery;
use App\Clickhouse\Queries\KitScanPathsQuery;
use App\Clickhouse\Queries\LprRepossessionsQuery;
use App\Clickhouse\Queries\RecoveryLeaderQuery;
use App\Clickhouse\Queries\RecoveryTotalsBatchQuery;
use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Clickhouse\Queries\ScanStatsBatchQuery;
use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use App\Clickhouse\Queries\UniqueLprCaseClaimsQuery;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Keeps the SQL the dashboard DISPLAYS equal to the SQL it RUNS.
 *
 * This deliberately does NOT execute the statements and compare. That was tried: the pgsql
 * test bridge is a schema bridge, not a dialect translator, so ClickHouse-only syntax
 * (`GROUP BY ... WITH ROLLUP`, `uniqExactMerge`, `toStartOfMonth`) is a Postgres syntax error.
 * The statements throw, QueryExecuted never fires, and a DB::listen harness records nothing --
 * which passes every comparison vacuously. Fidelity against real data was instead measured by
 * hand against ClickHouse Cloud for all 16 cards x 4 date modes.
 *
 * What IS mechanically checkable, and is what actually prevents drift, is the structure the
 * design rests on: one copy of each statement in a `*Sql()` builder, and an executing entry
 * point that gets its SQL from that builder rather than rebuilding it.
 */
class CardQuerySqlFidelityTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public static function cardProvider(): array
    {
        $cases = [];

        foreach (CompanyMetricsOverview::cards() as $key => $class) {
            foreach (['today', 'last_7d', 'this_month', 'last_month'] as $date) {
                $cases["{$key} / {$date}"] = [$class, $date];
            }
        }

        return $cases;
    }

    /**
     * Every card explains its headline on every date mode, without touching ClickHouse.
     * Building a statement must stay query-free -- the Query section renders inside the
     * dashboard's own request.
     */
    #[Group('dashboard')]
    #[DataProvider('cardProvider')]
    public function test_every_card_declares_a_usable_statement(string $class, string $date): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $card = new $class;
        $card->date = $date;

        if (property_exists($card, 'filters')) {
            $card->filters = ['date_toggles' => $date];
        }

        $statements = $card->getCardQueryStatements();

        $this->assertNotEmpty($statements, "{$class} declared no statement for {$date}");

        foreach ($statements as $statement) {
            $this->assertNotSame('', trim($statement->sql));
            $this->assertNotSame('', trim($statement->label));
            // The org must be bound in -- an unscoped statement would read the whole fleet.
            $this->assertStringContainsString((string) $user->org_id, $statement->sql);
        }
    }

    /**
     * DEV-2586 (review-2 D1): the two leaderboard queries that feed a NAMED card must carry the
     * house blank-kit-name guard, in the query, not in PHP.
     *
     * `clarity.hits_cold.kit_name` is `String DEFAULT ''` and `clarity.kits.name` is unguarded,
     * so a kit carrying the column default renders a blank label. The repo already fixed this
     * twice -- {@see KitScanPathsQuery::handle()} and
     * {@see ScanPlateStatsBatchQuery} -- and
     * `okf/concepts/scout-clickhouse-queries.md:47` documents it. The guard yields an actionable
     * `Kit 1234`; a PHP-side `?? 'N/A'` cannot even fire, because the key is present and its
     * value is the empty string.
     *
     * Asserted on the SQL text rather than by executing: the pgsql test bridge is a schema
     * bridge, not a dialect translator (see this class's own header), so `if()`/`concat()` are
     * not runnable here.
     */
    #[Group('dashboard')]
    public function test_named_leaderboard_queries_guard_a_blank_kit_name_in_sql(): void
    {
        $recovery = RecoveryLeaderQuery::handleSql(['org_id' => 1, 'start' => '2026-08-01', 'end' => '2026-08-31']);

        $this->assertStringContainsString(
            "if(k.name = '', concat('Kit ', toString(k.id)), k.name)",
            $recovery,
            'RecoveryLeaderQuery must not select a bare k.name: DEV-2586 puts that value on the card.'
        );

        $args = ['org_id' => 1, 'start' => '2026-08-01 00:00:00', 'end' => '2026-08-31 23:59:59', 'timezone' => 'UTC'];

        foreach ([
            StagingLiveHitLeaderQuery::handleRangeSql($args),
        ] as $sql) {
            $this->assertStringContainsString(
                "if(anyLast(kit_name) = '', concat('Kit ', toString(kit_id)), anyLast(kit_name))",
                $sql,
                'StagingLiveHitLeaderQuery must guard the blank kit_name default in SQL.'
            );
            // The guard wraps the AGGREGATE, not the row -- evaluated once per group rather than
            // once per scanned row (ScanPlateStatsBatchQuery:226-232 records why).
            $this->assertStringNotContainsString("anyLast(if(kit_name = ''", $sql);
        }
    }

    public static function builderProvider(): array
    {
        return [
            'ScanStatsBatchQuery::handle' => [ScanStatsBatchQuery::class, 'handle', 'handleSql'],
            'ScanPlateStatsBatchQuery::handle' => [ScanPlateStatsBatchQuery::class, 'handle', 'handleSql'],
            'ScanPlateStatsBatchQuery::handleRange' => [ScanPlateStatsBatchQuery::class, 'handleRange', 'handleRangeSql'],
            'HitPlateStatsBatchQuery::handleRange' => [HitPlateStatsBatchQuery::class, 'handleRange', 'handleRangeSql'],
            'RecoveryTotalsBatchQuery::handle' => [RecoveryTotalsBatchQuery::class, 'handle', 'handleSql'],
            'RecoveryTotalsBatchQuery::handleRange' => [RecoveryTotalsBatchQuery::class, 'handleRange', 'handleRangeSql'],
            'RecoveryLeaderQuery::handle' => [RecoveryLeaderQuery::class, 'handle', 'handleSql'],
            'StagingLiveHitLeaderQuery::handleRange' => [StagingLiveHitLeaderQuery::class, 'handleRange', 'handleRangeSql'],
            'LprRepossessionsQuery::window' => [LprRepossessionsQuery::class, 'window', 'windowSql'],
            'LprRepossessionsQuery::monthlySeries' => [LprRepossessionsQuery::class, 'monthlySeries', 'monthlySeriesSql'],
            'UniqueLprCaseClaimsQuery::window' => [UniqueLprCaseClaimsQuery::class, 'window', 'windowSql'],
            'UniqueLprCaseClaimsQuery::monthlySeries' => [UniqueLprCaseClaimsQuery::class, 'monthlySeries', 'monthlySeriesSql'],
            'UniqueLprCaseClaimsQuery::windowWithPreClaimScans' => [UniqueLprCaseClaimsQuery::class, 'windowWithPreClaimScans', 'windowWithPreClaimScansSql'],
            'UniqueLprCaseClaimsQuery::monthlySeriesWithPreClaimScans' => [UniqueLprCaseClaimsQuery::class, 'monthlySeriesWithPreClaimScans', 'monthlySeriesWithPreClaimScansSql'],
        ];
    }

    /**
     * The executing method must take its SQL FROM the builder the Query section displays.
     * The moment one of these rebuilds a heredoc inline, the dashboard starts showing a
     * statement that no longer produces the number above it -- and nothing else would catch it.
     */
    #[Group('dashboard')]
    #[Group('clickhouse')]
    #[DataProvider('builderProvider')]
    public function test_the_executing_method_delegates_to_the_displayed_builder(
        string $class,
        string $executor,
        string $builder,
    ): void {
        $this->assertTrue(method_exists($class, $builder), "{$class}::{$builder}() is missing");

        $body = $this->bodyOf($class, $executor);

        $this->assertStringContainsString(
            "::{$builder}(",
            $body,
            "{$class}::{$executor}() no longer delegates to {$builder}()",
        );
        $this->assertStringNotContainsString(
            '<<<SQL',
            $body,
            "{$class}::{$executor}() builds SQL inline again -- it must come from {$builder}()",
        );
    }

    private function bodyOf(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
