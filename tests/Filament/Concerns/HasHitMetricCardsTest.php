<?php

declare(strict_types=1);

namespace Tests\Filament\Concerns;

use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Clickhouse\Queries\ClickhouseQuery;
use App\Enums\HitTypeEnum;
use App\Filament\Concerns\HasHitMetricCards;
use App\Models\Hit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The parts of the DEV-2546 metric cards that can be pinned WITHOUT rendering the page or
 * reaching a real ClickHouse server.
 *
 * Everything here drives the trait through a bare host object, so none of it skips: the
 * page-level tests need the ClickHouse pgsql bridge schema (HitsResourceTest) or a real
 * ClickHouse (HitsResourceMetricCardsClickhouseTest), and the driver case in particular
 * was previously covered ONLY by a test that markTestSkipped's on exactly the driver
 * setting that broke it.
 */
#[Group('concerns')]
#[Group('hits')]
class HasHitMetricCardsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The cache key is org-scoped, so the trait needs a real viewer behind it — the same
        // condition the Hits page itself runs under.
        $this->actingAs($this->createUser());
    }

    // ---------------------------------------------------------------------
    // Driver-aware SQL
    // ---------------------------------------------------------------------

    /**
     * `uniqExact()` is ClickHouse-only. Under `CLICKHOUSE_DRIVER=pgsql` — what .env.testing
     * ships, what RequiresClickhouse names as the local default, and what qa-prep falls back
     * to without Docker — the model is still a ClickhouseModel but Postgres serves the query
     * and rejects the function (SQLSTATE 42883), which the trait swallows into four em dashes
     * while the table keeps working. So the dialect must follow the CONNECTION DRIVER, not the
     * model class (okf/concepts/clickhouse-query-patterns.md, "Driver-aware SQL").
     */
    public function test_the_clickhouse_branch_emits_postgres_functions_on_the_pgsql_bridge(): void
    {
        Config::set('database.connections.clarity_clickhouse.driver', 'pgsql');

        $sql = $this->host()->applyHitMetricAggregate(ClickhouseHit::query())->toSql();

        $this->assertStringNotContainsStringIgnoringCase('uniqexact', $sql);
        $this->assertStringContainsString('count(distinct ocr) as unique_hits', $sql);
        $this->assertStringContainsString('count(distinct id) as total_hits', $sql);
        $this->assertStringContainsString('group by', \strtolower($sql));
    }

    /**
     * The production dialect is unchanged: `uniqExact(id)` stays (operator decision), and the
     * bridge form above is a semantic mirror of it — distinct hit ids, distinct plates — not a
     * different question.
     */
    public function test_the_clickhouse_branch_emits_uniq_exact_on_a_clickhouse_driver(): void
    {
        // Resolve the connection under the committed config BEFORE flipping the driver value,
        // so flipping cannot make the manager try to build a real ClickHouse connection.
        ClickhouseHit::query();

        Config::set('database.connections.clarity_clickhouse.driver', 'clickhouse');

        $sql = $this->host()->applyHitMetricAggregate(ClickhouseHit::query())->toSql();

        $this->assertStringContainsString('uniqExact(ocr) as unique_hits', $sql);
        $this->assertStringContainsString('uniqExact(id) as total_hits', $sql);
    }

    /** The Postgres branch keeps its own column shape: alias-qualified, over the six-way join. */
    public function test_the_postgres_branch_keeps_its_joined_column_shape(): void
    {
        $sql = $this->host()->applyHitMetricAggregate($this->postgresFilteredQuery())->toSql();

        $this->assertStringNotContainsStringIgnoringCase('uniqexact', $sql);
        $this->assertStringContainsString('count(distinct scans.ocr) as unique_hits', $sql);
        $this->assertStringContainsString('count(*) as total_hits', $sql);
    }

    // ---------------------------------------------------------------------
    // Hit types no card names (AC-4) — accepted gap, RN5
    // ---------------------------------------------------------------------

    /** A failed aggregate knows nothing, so every card must degrade the same way. */
    public function test_a_failed_aggregate_produces_four_null_cards(): void
    {
        $cards = $this->host()->foldCards(null);

        $this->assertCount(4, $cards);
        $this->assertSame(
            ['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'],
            \array_column($cards, 'key'),
        );
        $this->assertNull($cards[0]['unique']);
        $this->assertNull($cards[0]['total']);
    }

    /**
     * The card set is ALWAYS exactly four (AC-1, operator decision RN5 2026-09-15). A
     * Probable Match is one click away in the Type filter, is excluded by neither query
     * branch, and lands in NO card's total — this pins that acceptance directly rather
     * than reintroducing the fifth "Other Hits" card RN4 added and RN5 removed.
     */
    public function test_a_hit_type_no_card_counts_is_accepted_as_uncounted(): void
    {
        Hit::factory()->create(['hit_type' => HitTypeEnum::Probable_Match->value]);

        $cards = $this->host($this->postgresFilteredQuery())->cards();

        $this->assertCount(4, $cards);
        $this->assertSame(
            [0, 0, 0, 0],
            \array_column($cards, 'total'),
            'no card counts a Probable Match (must be int 0, not null-from-failure)',
        );
        $this->assertGreaterThanOrEqual(
            1,
            (int) $this->postgresFilteredQuery()->count(),
            'the table itself would still list the row',
        );
    }

    // ---------------------------------------------------------------------
    // The cost bound (rate)
    // ---------------------------------------------------------------------

    /**
     * The aggregate is unbounded (GROUP BY over every matching row, no LIMIT) and runs ahead
     * of the paginated table on EVERY render. Per-request memoisation cannot help, because
     * the renders that repeat it are separate requests — pagination, sorting, and above all
     * the 10s retry polls HasRetryQuery schedules after a table timeout. Two page instances
     * on the same filter state must resolve it once.
     */
    public function test_the_aggregate_is_resolved_once_for_a_repeated_filter_state(): void
    {
        $runs = $this->countAggregateQueries();

        $first = $this->host($this->postgresFilteredQuery())->cards();
        $second = $this->host($this->postgresFilteredQuery())->cards();

        $this->assertSame(1, $runs->count, 'a second render of the same filter state must not re-run the aggregate');
        $this->assertSame($first, $second);
    }

    /** ...and the bound must not outlive the filter state it was computed for (AC-3). */
    public function test_a_changed_filter_state_resolves_the_aggregate_again(): void
    {
        $runs = $this->countAggregateQueries();

        $this->host($this->postgresFilteredQuery()->where('hits.id', '>', 0))->cards();
        $this->host($this->postgresFilteredQuery()->where('hits.id', '>', 1))->cards();

        $this->assertSame(2, $runs->count);
    }

    /**
     * The failing case is the expensive one, so it is the one that must not repeat: after a
     * timeout the table polls this page every 10s, twice, and each of those polls used to
     * re-fire the same doomed aggregate.
     *
     * This is also why the reverted `retryCount > 0` guard is not coming back. The marker is
     * keyed on the query that failed and expires on its own, so narrowing the filter is tried
     * immediately and the cards come back by themselves; `retryCount` is never reset, so it
     * em-dashed the cards for the rest of the page session.
     */
    public function test_a_failing_aggregate_is_not_re_run_by_the_next_render(): void
    {
        Config::set('app.debug', false);

        // Also pins that the failure is reported exactly ONCE: the second render must not even
        // reach the logger. (Faking it additionally keeps the db-error channel's own database
        // write out of a transaction the deliberately broken query has already aborted.)
        Log::shouldReceive('channel')->with('db-error')->once()->andReturnSelf();
        Log::shouldReceive('error')->once();

        $runs = $this->countAggregateQueries();

        $first = $this->host($this->brokenFilteredQuery())->cards();
        $second = $this->host($this->brokenFilteredQuery())->cards();

        $this->assertSame(1, $runs->count, 'the poll after a failure must not re-run the failing aggregate');
        $this->assertNull($first[0]['unique'], 'cards degrade to an em dash');
        $this->assertNull($second[0]['unique']);
        $this->assertCount(4, $second);

        // Proves the failing statement did NOT leave the connection aborted (PG 25P02): if
        // fetchAggregateRows() had not rolled back its own savepoint, this insert — which
        // touches the same connection — would itself throw.
        Hit::factory()->create();
        $this->assertSame(1, Hit::query()->count());
    }

    // ---------------------------------------------------------------------
    // The cost bound (Postgres statement-level cap)
    // ---------------------------------------------------------------------

    /**
     * The functional pin for the High: a Postgres aggregate that would run past its cap is
     * actually cancelled by Postgres itself (SQLSTATE 57014), not merely "intended" to be.
     * `pg_sleep()` stands in for "a broad filter" without needing production-scale data; the
     * host overrides the real 5s constant down to 50ms so the test doesn't wait 5s to prove it.
     */
    public function test_a_slow_postgres_aggregate_is_cancelled_below_the_tables_budget(): void
    {
        $connection = DB::connection('zod');
        $query = $connection->query()->selectRaw('pg_sleep(0.2) as sleep_result');

        try {
            (new HitMetricCardsHostWithShortTimeout)->fetchRows($query, $connection, false);
            $this->fail('expected the capped statement_timeout to cancel the sleeping query');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('57014', $exception->getMessage());
        }
    }

    /**
     * Trap: a session-level SET statement_timeout that is not restored would apply to
     * every later query on this connection (including the table's own). The finally
     * block must put the prior value back.
     */
    public function test_the_capped_timeout_does_not_leak_to_a_later_query_in_the_same_test(): void
    {
        $connection = DB::connection('zod');
        $before = $connection->selectOne('show statement_timeout')->statement_timeout;

        $query = $connection->query()->selectRaw('1 as one');
        $this->host()->fetchRows($query, $connection, false);

        $after = $connection->selectOne('show statement_timeout')->statement_timeout;

        $this->assertSame($before, $after, 'the capped timeout must not survive its own query');
    }

    /** A cancelled statement outside a transaction must not leave the session unusable. */
    public function test_the_connection_still_works_after_a_cancelled_aggregate(): void
    {
        $connection = DB::connection('zod');
        $query = $connection->query()->selectRaw('pg_sleep(0.2) as sleep_result');

        try {
            (new HitMetricCardsHostWithShortTimeout)->fetchRows($query, $connection, false);
        } catch (QueryException) {
            // Expected — asserted in test_a_slow_postgres_aggregate_is_cancelled_below_the_tables_budget().
        }

        $this->assertSame(1, $connection->selectOne('select 1 as one')->one);
    }

    /**
     * Trap (a): ClickHouse has its own `timeout_query` (config/database.php) and must not
     * take a Postgres statement_timeout SET — and must not open a Laravel transaction that
     * would force the zod write PDO on a later mix-up.
     */
    public function test_the_clickhouse_branch_never_takes_a_postgres_statement_timeout(): void
    {
        $connection = DB::connection('zod');
        $before = $connection->transactionLevel();

        $sqlSeen = [];
        $connection->beforeExecuting(function (string $sql) use (&$sqlSeen): void {
            $sqlSeen[] = $sql;
        });

        $query = $connection->query()->selectRaw('1 as one');
        $this->host()->fetchRows($query, $connection, true);

        $this->assertSame($before, $connection->transactionLevel(), 'the ClickHouse branch must not open its own transaction');
        $this->assertFalse(
            \collect($sqlSeen)->contains(fn ($sql) => \str_contains(\strtolower($sql), 'statement_timeout')),
        );
    }

    /**
     * The RN5 High: on a cold connection (transactionLevel === 0) the Postgres branch must
     * NOT open a top-level Laravel transaction, because that forces getReadPdo() onto the
     * write host. Under RefreshDatabase we are already inside a transaction, so a SAVEPOINT
     * (net-zero level change) is the correct nested path — this asserts the level is restored.
     */
    public function test_the_postgres_branch_restores_transaction_level_after_the_aggregate(): void
    {
        $connection = DB::connection('zod');
        $before = $connection->transactionLevel();

        $query = $connection->query()->selectRaw('1 as one');
        $this->host()->fetchRows($query, $connection, false);

        $this->assertSame(
            $before,
            $connection->transactionLevel(),
            'a leaked transaction would keep subsequent reads on the write PDO',
        );
    }

    // ---------------------------------------------------------------------
    // Cache TTL ladder (AC-4 staleness + impersonation collapse)
    // ---------------------------------------------------------------------

    /** okf/concepts/platform-gotchas.md PG-009's ladder, applied the same way as {@see ClickhouseQuery}. */
    public function test_the_cache_ttl_is_ten_seconds_normally(): void
    {
        Cache::spy();

        $this->host($this->postgresFilteredQuery())->cards();

        Cache::shouldHaveReceived('put')
            ->once()
            ->withArgs(fn ($key, $value, $ttl) => $ttl === 10);
    }

    /** Collapses to 3s under impersonation, same as the rest of the ladder. */
    public function test_the_cache_ttl_collapses_to_three_seconds_while_impersonating(): void
    {
        session()->put('admin_impersonator', 1);

        Cache::spy();

        $this->host($this->postgresFilteredQuery())->cards();

        Cache::shouldHaveReceived('put')
            ->once()
            ->withArgs(fn ($key, $value, $ttl) => $ttl === 3);
    }

    /**
     * The failure marker rides the SAME ladder as a successful result — no separate,
     * longer blackout — because the aggregate's own wait is now capped, so a retry after a
     * failure is cheap.
     */
    public function test_the_failure_marker_uses_the_same_short_ttl(): void
    {
        Config::set('app.debug', false);
        Log::shouldReceive('channel')->with('db-error')->once()->andReturnSelf();
        Log::shouldReceive('error')->once();

        Cache::spy();

        $this->host($this->brokenFilteredQuery())->cards();

        Cache::shouldHaveReceived('put')
            ->once()
            ->withArgs(fn ($key, $value, $ttl) => $ttl === 10);
    }

    // ---------------------------------------------------------------------
    // Null-safety (no authenticated user)
    // ---------------------------------------------------------------------

    /**
     * The cache key previously dereferenced `auth()->user()->org_id` with no null guard,
     * inconsistent with `auth()->user()?->email` in logHitMetricCardFailure(). A bare
     * property access on null degrades silently in PHP (a warning, not a crash), so this
     * pins it by promoting that warning to an exception for the duration of the call: if the
     * guard regresses, the aggregate throws, is swallowed by the trait's own catch, and the
     * card comes back null instead of a real count.
     */
    public function test_the_cache_key_is_null_safe_when_there_is_no_authenticated_user(): void
    {
        Auth::logout();

        \set_error_handler(function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, \E_WARNING);

        try {
            $cards = $this->host($this->postgresFilteredQuery())->cards();
        } finally {
            \restore_error_handler();
        }

        $this->assertNotNull($cards[0]['unique'], 'the aggregate must not have failed on a null user');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function host(?Builder $query = null): HitMetricCardsHost
    {
        $host = new HitMetricCardsHost;
        $host->filteredQuery = $query;

        return $host;
    }

    /** The shape buildPostgresQuery() hands over, reduced to what the aggregate reads. */
    private function postgresFilteredQuery(): Builder
    {
        return Hit::withoutGlobalScopes()
            ->join('scans', 'scans.id', '=', 'hits.scan_id');
    }

    /** Same shape, but guaranteed to throw when it executes — never when it compiles. */
    private function brokenFilteredQuery(): Builder
    {
        return $this->postgresFilteredQuery()->whereRaw('hits.column_that_does_not_exist = 1');
    }

    /**
     * Counts ATTEMPTS at the metric-card aggregate, identified by its select alias.
     *
     * `beforeExecuting`, not `DB::listen`: a query that throws never dispatches QueryExecuted,
     * and the failing aggregate is precisely the one whose repetition matters most here.
     */
    private function countAggregateQueries(): object
    {
        $counter = new class
        {
            public int $count = 0;
        };

        DB::connection((new Hit)->getConnectionName())->beforeExecuting(
            function (string $sql) use ($counter): void {
                if (\str_contains($sql, 'unique_hits')) {
                    $counter->count++;
                }
            }
        );

        return $counter;
    }
}

/**
 * A bare host for the trait: no Livewire, no Filament table, no page render — just the two
 * seams the trait actually needs, plus public shims onto the private fold/aggregate/fetch
 * steps so they can be driven directly. Kept out of the app so the trait's own surface stays
 * as small as review round 3 asked for.
 */
class HitMetricCardsHost
{
    use HasHitMetricCards;

    public ?Builder $filteredQuery = null;

    public function getFilteredTableQuery(): ?Builder
    {
        return $this->filteredQuery;
    }

    /**
     * @return array<int, array{key: string, label: string, color: string, unique: int|null, total: int|null}>
     */
    public function cards(): array
    {
        return $this->getHitMetricCardData();
    }

    /**
     * @param  array<string, array{unique: int, total: int}>|null  $counts
     * @return array<int, array{key: string, label: string, color: string, unique: int|null, total: int|null}>
     */
    public function foldCards(?array $counts): array
    {
        return $this->buildHitMetricCardData($counts);
    }

    public function fetchRows(QueryBuilder $query, ConnectionInterface $connection, bool $isClickhouse = false): Collection
    {
        return $this->fetchAggregateRows($query, $connection, $isClickhouse);
    }
}

/**
 * Overrides the real 5s cap down to 50ms so the statement-timeout tests can prove real
 * cancellation (via `pg_sleep()`) in well under a second instead of waiting out the
 * production value.
 */
class HitMetricCardsHostWithShortTimeout extends HitMetricCardsHost
{
    protected function hitMetricAggregateTimeoutMs(): int
    {
        return 50;
    }
}
