# DEV-2220 — Implementation Plan

**Route:** bug · **Base:** main · **Mode:** normal · **Scope:** resilience-only (no DB schema change)

## Decision (Dalton, 2026-07-23)
This is a **recurrence** — DEV-1898 (Done, commit `c744f887`) already shipped the query rewrite the ticket's triage hints assume. The durable fix (a composite `hotlist(org_id,client_org_id)`/`(org_id,lienholder_org_id)` index) is **explicitly out of bounds** ("we cannot change any database schema"). So the platform PR is **graceful degradation only**:

- **Catch `SQLSTATE 57014` (statement timeout) specifically**, around `cache()->remember()`, in both `Org::getHotlistClients()` and `getHotlistLienholders()`.
- **Log** via the existing `HasRetryQuery` convention (`Log::channel('db-error')` + `logDatadogCount('clarity.query_timeout', …)`) — with **`org_id` context, not `auth()->user()->email`** (jobs/console have no authenticated user).
- **Return an empty `Collection`, uncached** (catch around `remember()` so nothing is persisted → a recovering org repopulates on the next successful call).
- **Rethrow** non-timeout `QueryException`s always, and rethrow timeouts when `config('app.debug')` (dev/CI still see real DB errors — matches `HasRetryQuery`).
- **Silently-empty** degraded UX (empty dropdowns; no visible indicator).
- **No** bounded `SET LOCAL statement_timeout`, **no** negative cache, **no** index/migration, **no** query reshape.

The fix lives in `Org.php` (not the jobs) because the two methods are called synchronously from **~9 Filament/console consumers** (HitsResource, HotlistResource, MissedOpportunityResource, AlertSettingsForm, ClientTable, IndividualClientAnalytics, HasStatDrillDown, CreateMissedOpportunityAlertSettings) that would otherwise surface an uncaught 500 on a cold cache.

## Subtask DAG
Single node `t1` (no fan-out) — see `plan.json`.

### t1 — Resilience in `Org.php` (TDD)
**Grounding for the worker:**
- 57014 condition: copy `bootstrap/app.php:23` → `$e->getCode() === '57014' || str_contains($e->getMessage(), 'statement timeout')`. Put it in a private `isStatementTimeout(QueryException $e): bool` helper (shared by both getters).
- Logging convention: `app/Filament/Concerns/HasRetryQuery.php` (db-error channel + `logDatadogCount`). Helper signature: `logDatadogCount(string $metric, float $amount = 1, array $tags = []): void` (`app/helpers.php:44`).
- Empty fallback: `collect()` — `Org.php:18` imports `Illuminate\Support\Collection`, which is the declared return type and what `pluck('name','id')` returns.
- Add imports: `use Illuminate\Database\QueryException;`, `use Illuminate\Support\Facades\Log;`.
- Precedent for the pattern: `StagingOrgsHotlistCount::getCount()` (try/catch around `remember()` → fallback) — but be **more precise**: catch 57014 only, rethrow other `QueryException`s.

**Shape (per getter):**
```php
public function getHotlistClients(): Collection
{
    try {
        return cache()->remember(
            'hotlist-clients:'.$this->id,
            now()->endOfDay(),
            fn () => self::query()
                ->whereIn('id', Hotlist::query()
                    ->where('org_id', $this->id)
                    ->whereNotNull('client_org_id')
                    ->select('client_org_id')
                    ->distinct())
                ->orderBy('name')
                ->pluck('name', 'id')
        );
    } catch (QueryException $exception) {
        if (! $this->isStatementTimeout($exception) || config('app.debug')) {
            throw $exception;
        }

        Log::channel('db-error')->error('Hotlist client cache warm timed out', [
            'org_id' => $this->id,
            'message' => $exception->getMessage(),
            'sql' => $exception->getSql(),
            'connection' => $exception->getConnectionName(),
            'code' => $exception->getCode(),
        ]);

        logDatadogCount('clarity.query_timeout', 1, [
            'category' => 'database_timeout',
            'item' => 'hotlist_clients_cache_warm',
        ]);

        return collect();
    }
}
```
(Lienholder variant identical; `item` → `hotlist_lienholders_cache_warm`.)

**Tests (TDD — write first, confirm red→green):**
- `tests/Feature/Jobs/Org/CacheOrgHotlistClientsTest.php` — new test: with `Config::set('app.debug', false)`, force the `zod` connection's `select` to throw a `QueryException` whose message contains `statement timeout`; assert `CacheOrgHotlistClients::handle()` does NOT throw and `cache()->get('hotlist-clients:'.$org->id)` stays `null`. Keep the 4 existing happy-path tests green.
- `tests/Feature/Jobs/Org/CacheOrgHotlistLienholdersTest.php` — same for the lienholder job.
- `tests/Models/OrgHotlistCacheTest.php` (new; follows `tests/Models/` convention): direct `$org->getHotlistClients()` / `getHotlistLienholders()` calls return an **empty Collection** on a forced statement-timeout (protects the Filament callers); a **non-timeout** `QueryException` still **propagates**; happy path still returns the correct `[id => name]` Collection.

**Test technique** (deterministic; mirrors `HasRetryQueryTest`): construct `new QueryException('zod', $sql, [], new \Exception('SQLSTATE[57014]: canceling statement due to statement timeout'))` and make the `zod` connection's `select` throw it (e.g. a partial Mockery mock of the connection rebound via `DB::extend`/`purge`, or an equivalent seam). Avoid a real `SET statement_timeout` race — the constructed exception is deterministic.

**Test command:** run the affected tests (`--filter=Hotlist` covers the two job tests, the new model test, plus related Hotlist consumer tests for light regression) — not the whole suite automatically (per CLAUDE.md; CI runs the full suite).

## Out of scope / excluded — see `plan.json.excluded_sites`
Composite index (schema — forbidden), bounded timeout, negative cache, query reshape, job-level catch, dead-`whereNotNull` cleanup, the 8 unrelated cache/whereIn sites.
