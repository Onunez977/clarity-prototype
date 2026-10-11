<?php

namespace App\Filament\Concerns;

use App\Clickhouse\Models\ClickhouseModel;
use App\Clickhouse\Queries\ClickhouseQuery;
use App\Clickhouse\Queries\HitDensityGeohashQuery;
use App\Clickhouse\Queries\HitPlateStatsBatchQuery;
use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use App\Enums\HitTypeEnum;
use App\Filament\Widgets\Dashboard\Concerns\SharedHitStatsData;
use App\Models\Hit;
use App\Services\Scout\BuildShiftSummary;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The filter-reactive hit metric cards rendered above the Hits table (DEV-2546).
 *
 * The four same-named Company Metrics dashboard widgets can't supply these numbers: they
 * read `clarity.hit_stats_daily`, an org/day rollup with no plate, VIN, order, disposition,
 * kit, client, lienholder or location dimension. Only their labels and colours are reused.
 *
 * The counts come from the page's OWN `getFilteredTableQuery()` — the same builder the
 * table paginates, with the same filters already applied — so the cards and the table can
 * never disagree about which dataset they describe. Every path that mutates filter state
 * re-renders them for free: Filament's deferred Apply action, the individual filter-reset
 * actions, and {@see HasClickableFilter}'s addFilter()/addQueryFilter() chips, which bypass
 * Filament's handleTableFilterUpdates() choke point entirely.
 *
 * Card count is fixed at exactly four (AC-1; operator decision, RN5, 2026-09-15). Hit types
 * M (Missed Opportunity), F (Missed Opportunity Spotted & Closed) and P (Probable Match)
 * can still reach the table under Type=All (both query branches exclude only OverWatch, and
 * `withoutGlobalScopes()` drops Hit's `only_basic` filter), so a table populated only with
 * those types leaves all four cards reading 0. That gap is accepted, not fixed: an earlier
 * round added a conditional fifth "Other Hits" card to close it; it was removed because
 * AC-1 ("four metric cards") outranks AC-4 ("every listed row is counted") under the
 * operator's reading of the ticket, and that card's headline double-counted a plate seen
 * under two of the uncovered types.
 */
trait HasHitMetricCards
{
    /**
     * Cache value standing in for "this exact aggregate threw"; never a valid counts array.
     */
    private const HIT_METRIC_CACHE_FAILURE_MARKER = 'hit-metric-cards:failed';

    /**
     * Statement-level cap on the POSTGRES aggregate only — see fetchAggregateRows().
     * Measured against a production-shaped replica (DEV-2546 RN5): the connection-level
     * `statement_timeout` is already 30s on both `platform` and `zod`, the aggregate is
     * index-driven (`kit_id_created_at_idx`) and bounded to one org's kits — never a
     * sequential scan — but it cannot stop early the way the table's `simplePaginate` can.
     * A join-free query strictly cheaper than this one already hit that 30s ceiling
     * (SQLSTATE 57014) over a one-year window for a single org. Capping this query at 5s
     * leaves the table its full 30s budget afterwards, so a broad filter costs the user one
     * wait, not two.
     */
    private const HIT_METRIC_AGGREGATE_TIMEOUT_MS = 5000;

    /**
     * @var array<int, array{key: string, label: string, color: string, unique: int|null, total: int|null}>|null
     */
    private ?array $hitMetricCardData = null;

    /**
     * Card order, labels and colours, keyed by the hit type each card counts. Mirrors
     * LiveHits / HistoricalHits / StagingLiveHits / StagingHistoricalHits and
     * DashboardWidget::getStatColor(), so the two surfaces read as one family. These four
     * are the `only_basic` set {@see Hit::boot()}; see the class docblock for the M/F/P
     * types the table can list that no card here counts.
     *
     * @return array<string, array{key: string, label: string, color: string}>
     */
    private static function hitMetricCardDefinitions(): array
    {
        return [
            HitTypeEnum::Live->value => ['key' => 'live-hits', 'label' => 'Live Hits', 'color' => 'danger'],
            HitTypeEnum::Historical->value => ['key' => 'historical-hits', 'label' => 'Direct Historical Hits', 'color' => 'danger'],
            HitTypeEnum::Staging_Live->value => ['key' => 'staging-live-hits', 'label' => 'Staging Live Hits', 'color' => 'warning'],
            HitTypeEnum::Staging_Historical->value => ['key' => 'staging-historical-hits', 'label' => 'Staging Historical Hits', 'color' => 'warning'],
        ];
    }

    /**
     * Memoised per request. Filament evaluates the widget data closure once per widget,
     * and the page view reads it again separately, so without this the fold would run
     * twice per render. Being private it is never serialised into the Livewire snapshot,
     * so it cannot survive a hydration and serve a stale count after a filter change.
     * (The cross-request bound lives one level down, in aggregateHitMetricCounts().)
     *
     * @return array<int, array{key: string, label: string, color: string, unique: int|null, total: int|null}>
     */
    public function getHitMetricCardData(): array
    {
        return $this->hitMetricCardData ??= $this->buildHitMetricCardData($this->aggregateHitMetricCounts());
    }

    /**
     * Folds the grouped counts into the four card rows. Takes the counts as an argument
     * rather than fetching them so the fold is exercisable without a database.
     *
     * @param  array<string, array{unique: int, total: int}>|null  $counts  Null when the aggregate failed.
     * @return array<int, array{key: string, label: string, color: string, unique: int|null, total: int|null}>
     */
    private function buildHitMetricCardData(?array $counts): array
    {
        $cards = [];

        foreach (self::hitMetricCardDefinitions() as $hitType => $card) {
            $cards[] = [
                ...$card,
                'unique' => $counts === null ? null : ($counts[$hitType]['unique'] ?? 0),
                'total' => $counts === null ? null : ($counts[$hitType]['total'] ?? 0),
            ];
        }

        return $cards;
    }

    /**
     * How long one resolved aggregate stays in the shared cache.
     *
     * Deliberately the RN5 operator ladder (10s normally, 3s while impersonating) — NOT
     * PG-009 / {@see ClickhouseQuery}'s 10-minute default. Those longer TTLs are right for
     * expensive fleet-wide CH rollups; these cards must track an in-page filtered table, so
     * the tighter window is intentional and must not be "restored" to the wiki ladder.
     * Bounds AC-4 staleness under an unchanged filter; a mutation-driven bust in
     * BulkDispositionAction would close it further but is out of scope. Same TTL covers the
     * failure marker now that the aggregate's own wait is capped.
     */
    private function hitMetricCacheTtlSeconds(): int
    {
        return session()->has('admin_impersonator') ? 3 : 10;
    }

    /**
     * ONE grouped aggregate over the filtered set, not four round trips — a four-way
     * fan-out would multiply every cost below by four.
     *
     * WHAT IS AND IS NOT BOUNDED. The query itself has no LIMIT: it is a GROUP BY over
     * every row matching the filter, where the table's own query is simplePaginate-bounded
     * and can stop after perPage + 1 rows, and it runs BEFORE the table because header
     * widgets render first. On Postgres it runs over buildPostgresQuery()'s six-way join
     * against `hits` (228,575,936 rows) and `scans_metadata` (3,409,680,896 rows / 705 GB,
     * the largest table in the join) — see fetchAggregateRows() for the fix to the
     * structural asymmetry this leaves. What IS bounded is the RATE: one resolved aggregate
     * is shared, for hitMetricCacheTtlSeconds(), by every render of the same filter state —
     * paginating, sorting, the widget-plus-view double evaluation, a chip filter that lands
     * back on the same predicate, and above all the 10s retry polls {@see HasRetryQuery}
     * schedules after a table timeout. A failure is cached too, so the expensive case is
     * the one that repeats least. The key is derived from the COMPILED SQL and bindings of
     * the aggregate itself, so it changes the moment any filter, kit scope or branch
     * changes, and it cannot serve one org's numbers to another; cache() here is the same
     * store and idiom {@see SharedHitStatsData} uses for the dashboard cards.
     *
     * No `FINAL` of its own: the cards inherit the table's builder, so they describe the same
     * rows (operator answer Q3). Since DEV-2614 that builder applies `FINAL` on `hits_hot`
     * (≤90-day windows) and not on `hits_cold`: that collapses status re-emissions (same sort
     * key, newer `_version`) on hot only. `uniqExact(id)` rather than `count()` for the raw total. `hits_cold`/`hits_hot` are
     * ReplacingMergeTree with the mutable `hit_created_at` in the sort key, so rows for one
     * hit `id` that differ in `hit_created_at` never collapse and a bare `count()` runs up
     * to ~30% high, which the repo forbids on this table in four places
     * ({@see StagingLiveHitLeaderQuery}, {@see HitPlateStatsBatchQuery},
     * {@see HitDensityGeohashQuery}, {@see BuildShiftSummary}).
     *
     * Two consequences are recorded rather than claimed away. (1) The ClickHouse and
     * Postgres branches need not return the same number: Postgres counts transactional
     * rows through a join, ClickHouse counts distinct hit ids, and the Status filter even
     * reads different sources (live `hotlist.status` vs the frozen `hotlist_status` on the
     * hit row) — left as-is per operator decision, rather than reconciled. (2) The total
     * can read BELOW the number of rows the table paginates for the same filter, because
     * rows for one `id` that differ in `hit_created_at` survive even `FINAL`, on either table;
     * a row set carrying CDC duplicates is a pre-existing defect of the table, not of the card.
     *
     * The wide select list is replaced rather than appended to: the Postgres branch
     * otherwise carries two correlated nearest-address subselects and three PostGIS
     * expressions per row that nothing here depends on.
     *
     * @return array<string, array{unique: int, total: int}>|null Null when the aggregate failed.
     */
    private function aggregateHitMetricCounts(): ?array
    {
        // NOTE (review round 3): a `$this->retryCount > 0` guard was added here and REVERTED in
        // the same round. HasRetryQuery increments retryCount and never resets it, so the guard
        // turned one transient table timeout into permanently em-dashed cards for the rest of the
        // page session — worse than the cost it was meant to avoid, and it only ever covered
        // renders 2..N anyway, not the expensive first pass. Do not re-add it. The cache below is
        // the replacement: it covers the same retry polls, expires on its own, and is scoped to
        // the failing filter state instead of the whole session.
        $cacheKey = null;

        try {
            $query = $this->getFilteredTableQuery();

            if (! $query) {
                return null;
            }

            $aggregate = $this->applyHitMetricAggregate($query);
            $base = $aggregate->toBase();
            $connection = $aggregate->getModel()->getConnection();
            $isClickhouse = $aggregate->getModel() instanceof ClickhouseModel;
            $cacheKey = $this->hitMetricCacheKey($base, $connection->getName());
            $cached = cache()->get($cacheKey);

            if ($cached === self::HIT_METRIC_CACHE_FAILURE_MARKER) {
                return null;
            }

            if (\is_array($cached)) {
                return $cached;
            }

            $rows = $this->fetchAggregateRows($base, $connection, $isClickhouse);
        } catch (\Throwable $exception) {
            $this->logHitMetricCardFailure($exception);

            if (config('app.debug')) {
                throw $exception;
            }

            if ($cacheKey !== null) {
                cache()->put($cacheKey, self::HIT_METRIC_CACHE_FAILURE_MARKER, $this->hitMetricCacheTtlSeconds());
            }

            return null;
        }

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) data_get($row, 'hit_type')] = [
                'unique' => (int) data_get($row, 'unique_hits', 0),
                'total' => (int) data_get($row, 'total_hits', 0),
            ];
        }

        cache()->put($cacheKey, $counts, $this->hitMetricCacheTtlSeconds());

        return $counts;
    }

    /**
     * Runs the aggregate, capping ONLY the Postgres branch's own wait — separately from
     * the table's 30s connection-level `statement_timeout` — so the two don't compound
     * into two waits for one broad filter. ClickHouse has its own `timeout_query`
     * (config/database.php) and must never see this SET; the caller passes $isClickhouse
     * explicitly rather than this method re-deriving it.
     *
     * IMPORTANT — do not open a *new* top-level Laravel transaction on a cold connection.
     * `beginTransaction()` opens on the write PDO, and while `transactions > 0` Laravel's
     * `getReadPdo()` returns that same write PDO
     * ({@see Connection::getReadPdo}). That would pull this multi-join
     * aggregate onto the primary for every customer Hits render (`zod` is configured with
     * read/write hosts in config/database.php).
     *
     * Two paths:
     * - Production (transactionLevel === 0): session-level SET on the READ PDO, restored
     *   in finally. Outside a transaction block a cancelled statement does not leave
     *   25P02-aborted state.
     * - Already inside a transaction (RefreshDatabase tests): a SAVEPOINT + SET LOCAL +
     *   rollback. getReadPdo() is already the write PDO because of the outer transaction,
     *   so the host switch is unavoidable here; the savepoint is what clears 25P02 after a
     *   cancel so the outer test transaction stays usable.
     */
    private function fetchAggregateRows(QueryBuilder $query, ConnectionInterface $connection, bool $isClickhouse): Collection
    {
        if ($isClickhouse) {
            return $query->get();
        }

        if ($connection->transactionLevel() > 0) {
            $connection->beginTransaction();

            try {
                $connection->statement('SET LOCAL statement_timeout = '.$this->hitMetricAggregateTimeoutMs());

                return $query->get();
            } finally {
                $connection->rollBack();
            }
        }

        $pdo = $connection->getReadPdo();
        $previous = (string) $pdo->query('SHOW statement_timeout')->fetchColumn();
        $pdo->exec('SET statement_timeout = '.$this->hitMetricAggregateTimeoutMs());

        try {
            return $query->get();
        } finally {
            $pdo->exec('SET statement_timeout = '.$pdo->quote($previous));
        }
    }

    /**
     * Overridable seam for tests only: production always gets HIT_METRIC_AGGREGATE_TIMEOUT_MS.
     * Pinning the cap's cancellation behaviour against the real 5s value would make the test
     * suite wait 5s per assertion; a host that overrides this to a few milliseconds can pin the
     * SAME code path against a `pg_sleep()` in a fraction of a second.
     */
    protected function hitMetricAggregateTimeoutMs(): int
    {
        return self::HIT_METRIC_AGGREGATE_TIMEOUT_MS;
    }

    /**
     * The cache identity of one aggregate.
     *
     * Derived from the COMPILED SQL plus bindings rather than from a hand-assembled list of
     * filter names, because the hand-assembled list is what goes stale: a filter added later,
     * a kit-scope change, the hot/cold table swap, or the driver branch below would all have to
     * be remembered, and forgetting one serves the wrong org's numbers. Anything that can change
     * the result changes the SQL or a binding. The org id is prefixed as a second, human-legible
     * tenancy guard so a hash collision cannot cross an org boundary, and the connection name
     * distinguishes the ClickHouse branch from the Postgres one.
     *
     * Bindings are serialize()d, not json_encode()d: json_encode returns false on a non-UTF-8
     * binding (a mangled plate search), which would collapse every such query onto one key.
     */
    private function hitMetricCacheKey(QueryBuilder $aggregate, ?string $connection): string
    {
        return 'hit-metric-cards:org='.auth()->user()?->org_id
            .':'.$connection
            .':'.\sha1($aggregate->toSql().'|'.\serialize($aggregate->getBindings()));
    }

    /**
     * Shapes the filtered builder into the grouped aggregate. Split from the run-and-fold
     * step above so the emitted SQL can be asserted for BOTH branches without a live
     * ClickHouse connection — the CH branch is otherwise unreachable in CI, and a failure
     * there degrades to an em dash rather than an error.
     *
     * Two independent things vary, and conflating them is how this broke once already:
     *
     * - The COLUMN SHAPE follows the model. buildPostgresQuery() produces a six-way join whose
     *   columns are alias-qualified (`hits.hit_type`, `scans.ocr`); the ClickHouse table is flat
     *   (`hit_type`, `ocr`, `id`).
     * - The SQL DIALECT follows the CONNECTION DRIVER, per okf/concepts/clickhouse-query-patterns.md
     *   "Driver-aware SQL", and the same check the repo already makes in {@see ClickhouseQuery}.
     *   Under `CLICKHOUSE_DRIVER=pgsql` — the committed .env.testing, the documented local default,
     *   and qa-prep's fallback when Docker is absent — a ClickhouseModel is still a ClickhouseModel
     *   but its queries are served by Postgres, which has no `uniqExact()`. Emitting it there threw
     *   SQLSTATE 42883, was swallowed, and rendered all four cards as em dashes while the table
     *   itself kept working. `count(distinct …)` is the mapping the wiki prescribes and preserves
     *   the ClickHouse branch's semantics (distinct hit ids, distinct plates) on the bridge.
     *
     * The ClickHouse row shape also differs from Postgres: the HTTP client hands back
     * associative arrays where PDO hands back stdClass, which is why the caller folds
     * rows with data_get() rather than property access.
     */
    public function applyHitMetricAggregate(Builder $query): Builder
    {
        $query = $query->setEagerLoads([])->select([]);

        if (! ($query->getModel() instanceof ClickhouseModel)) {
            return $query
                ->selectRaw('hits.hit_type as hit_type, count(distinct scans.ocr) as unique_hits, count(*) as total_hits')
                ->groupBy('hits.hit_type');
        }

        $onPgsqlBridge = config('database.connections.clarity_clickhouse.driver') === 'pgsql';

        return $query
            ->selectRaw($onPgsqlBridge
                ? 'hit_type, count(distinct ocr) as unique_hits, count(distinct id) as total_hits'
                : 'hit_type, uniqExact(ocr) as unique_hits, uniqExact(id) as total_hits')
            ->groupBy('hit_type');
    }

    /**
     * In production the cards degrade to an em dash rather than taking the page down with
     * them — they are an accessory to the table, and a broad filter that times out the
     * aggregate must still leave a working table behind it. Under APP_DEBUG the caller
     * rethrows, matching {@see HasRetryQuery}: a swallowed Throwable is how this repo has
     * previously turned a broken query into a silently empty surface, and the ClickHouse
     * branch has no other way to fail loudly. (The rethrow happens BEFORE the failure marker
     * is written, so a developer fixing the query keeps seeing the exception instead of a
     * cached em dash.)
     *
     * The counter is deliberately not `clarity.query_timeout`: this catches every
     * Throwable, so labelling a connection refusal or a renamed column as a timeout would
     * mislead the only production signal this path emits.
     */
    private function logHitMetricCardFailure(\Throwable $exception): void
    {
        Log::channel('db-error')->error('Hit metric cards query failed', [
            'user' => auth()->user()?->email,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        logDatadogCount('clarity.query_error', 1, [
            'category' => 'database_error',
            'item' => 'hit_metric_cards_query_failed',
        ]);
    }
}
