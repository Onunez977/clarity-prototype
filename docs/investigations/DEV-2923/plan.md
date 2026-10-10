# DEV-2923 — Ultra-Plan (sealed)

**Gate:** CLEAN  
**Verdict:** APPROVE-with-adopted-changes  
**First Glance:** comment [43033](https://insightlpr-team.atlassian.net/browse/DEV-2923?focusedCommentId=43033) Ready  
**Branch path:** main→main · draft PR [#502](https://github.com/insight-lpr/platform/pull/502)  
**Sources:** `prep-context.json` · `claude-plan-draft.md` · `cloud-plan-review.md` (REQUEST CHANGES, `bc-9dd40489`) · `claude-plan-reconcile.md` (Change 1 + all 10 Nits adopted; none rejected)

## Summary

Enrich ClickHouse HTTP exception logging so `TIMEOUT_EXCEEDED` (159) and sibling HTTP failures are actionable on the `db-error` channel. Platform subclass `LoggingClickhouseHttpClient` overrides `select()` / `write()`, logs truncated+redacted SQL plus the first `App\` caller (or null fallback), then rethrows the **same** `ClickhouseException`. `LoggingClickhouseConnection` + `AppServiceProvider` `booted()` re-extend install it after `ClickhouseServiceProvider`. No timeout budget, product query, package PR, or dashboard constant changes.

## AC map

| AC | Plan landing |
| --- | --- |
| AC-1 Forced timeout names the query | `db-error` line with `sql` + caller/route + `ch_code`; Docker proof uses **Change 1 recipe** (server 159 before curl) |
| AC-2 Successful queries unchanged | One `hrtime(true)` before `parent::*`; log only in `catch`; zero `db-error` on success `select` + `statement(SELECT 1)` in non-`RequiresClickhouse` class |
| AC-3 No credentials; truncation documented | **4096** head-biased; credential regex; `bindings_redacted`; typed timeout props (no raw config with password) |
| AC-4 Org-469-style before/after on PR | Same forced local 159; stock client vs logging client; org-469 query **not** run |

## Approach (smallest · #24)

1. **`LoggingClickhouseHttpClient`** — extend package client; override `select` + `write` only (`insert`/`insertAssocBulk` → `write`). Shared private emit; swallow log failures; rethrow original `$e`.
2. **Typed copies** — constructor copies `timeout_query`, `timeout_connect`, `retries`, `settings['max_execution_time']` into typed properties. Do **not** retain the raw config array (holds username/password/host).
3. **`LoggingClickhouseConnection`** — override `createWithClient()` (parent hard-assigns stock client).
4. **`AppServiceProvider::booted()`** — re-`extend('clickhouse', …)` after `ClickhouseServiceProvider` (providers.php order). Existing `booted()` precedent at AppServiceProvider.
5. **Public static** `truncateSql` / `redactSql` / `redactBindings` on the client (match package `formatValue` idiom). No third abstraction class.
6. **Caller walker** — first `App\` frame outside `App\Clickhouse\Logging\`; no match → all caller_* `null` (no throw). Relativize `caller_file` to repo root.
7. **Empty insert** — `Cannot insert empty values` before `write()` stays unenriched.

## Forced-timeout recipe (AC-1 · Change 1 — sealed)

Test-local on `clarity_clickhouse` → Docker CH `127.0.0.1:8123` only. **Never** write into `config/database.php`, `.env`, `.env.testing` defaults, or dashboard constants. Never Cloud CH.

| Knob | Value |
| --- | --- |
| `timeout_query` | `2` |
| `timeout_connect` | `5` |
| `retries` | `0` |
| curl budget | `7` (`CURLOPT_TIMEOUT = query+connect`) |
| settings | `timeout_before_checking_execution_speed=0`, `max_block_size=1` |
| SQL | `SELECT * FROM numbers(100000000)` (`select()` appends ` FORMAT JSON`) |
| Assert | `ch_code === 159`; `TIMEOUT_EXCEEDED` in message; `sql` contains `numbers(`; `statement === 'select'`; `connection === 'clarity_clickhouse'`; timeout fields match; **caller_* all null** (no-`App\` fallback) |
| Not asserted as gate | `server_elapsed_ms` (best-effort parse of `elapsed <n> ms`) |

*Why not the draft recipe:* `timeout_query=1` + default `timeout_connect=2` → 3s curl budget; curl fails first with `cURL error:…` → `ch_code` null. `SELECT count() FROM numbers(100000000000)` is the wrong shape for early limit checks.

## File / touch list

| File | Change |
| --- | --- |
| `app/Clickhouse/Logging/LoggingClickhouseHttpClient.php` | **Create** |
| `app/Clickhouse/Logging/LoggingClickhouseConnection.php` | **Create** |
| `app/Providers/AppServiceProvider.php` | Add `booted()` re-extend |
| `tests/Feature/Clickhouse/ClickhouseFailureLoggingTest.php` | **Create** — no `RequiresClickhouse` |
| `tests/Feature/Clickhouse/ClickhouseTimeoutLoggingTest.php` | **Create** — `RequiresClickhouse`; recipe only |
| `tests/Unit/Clickhouse/ClickhouseLogRedactionTest.php` | **Create** — statics + all `formatValue` binding types |

**Not touched:** package vendor; `Org` / `HasRetryQuery` / `HasHitMetricCards` / `ClickhouseQuery`; `LprRepossessions` constants; `config/database.php` defaults; DEV-2904 twin; `scripts/audit-card-query-sql.php` (verified OK); `okf/` (queued post-approval).

## Logging design (locked)

- **Channel** `db-error` → `storage/logs/db_error.log` (daily); message `ClickHouse HTTP exception`; level error.
- **Key `sql` is load-bearing** for `scripts/extract_sql_queries.py`.
- **Bindings key** stays `bindings_redacted` (not `bindings`).
- **Fields:** sql (redacted+truncated, placeholders intact), sql_truncated, sql_original_length, statement (`select`|`write`), bindings_redacted, binding_count, bindings_truncated, ch_code, exception_message, caller_*, route_*, console, user_id/org_id (ids only), connection, client_elapsed_ms, server_elapsed_ms (best-effort), timeout_query/timeout_connect/retries/curl_timeout/max_execution_time. **`query_id` omitted.**
- **Truncation:** 4096 head-biased, suffix `…[truncated N chars]`, always `sql_original_length`.
- **Rethrow:** same `ClickhouseException` instance.

## Tests (plan; not run this phase)

**FailureLoggingTest** (always runs, incl. `CLICKHOUSE_DRIVER=pgsql`):
1. Driver guard → Logging connection/client after purge.
2. curl-error `select` from `App\` invoker → one `db-error`, `ch_code` null, exception propagates.
3. curl-error `write` via `App\Jobs\TrackAnalytics` → `statement=write`, `caller_class=TrackAnalytics`, `connection=clarity_clickhouse_write`.
4. AC-2 success: `select` + `statement('SELECT 1')` → zero `db-error`.
5. No credentials in serialized context.

**TimeoutLoggingTest** (`RequiresClickhouse`): Change 1 recipe only. Call `setupRequiresClickhouse()` before `Log::spy()` (per-method, not shared setUp).

**LogRedactionTest:** truncate/redact cases from draft + `redactBindings` for every `formatValue` type (esp. array).

## Before/after (AC-4)

Same forced local 159; stock client thin line vs logging client rich line; PR states local forced timeout of the same class as org-469; org-469 query not run.

## Provision / QA (#18)

- Own fleet Docker CH twin — **not** DEV-2904 Path A.
- Real CH driver (pgsql mirror cannot produce `ClickhouseException`).
- Cloud CH READ-ONLY / untouched.
- Forced-timeout needs no seed; trait truncates may need re-seed for other CH QA.
- Soft #27 N/A (no UI).

## Hard stops

Logging only · no timeout budgets · no product query rewrites · no LprRepossessions/HeldFlexibleCache retune · no laravel-clickhouse PR · Cloud CH RO · no DEV-2904 twin steal · not an org-469 query fix · Soft #27 N/A · no product PHP in Plan phase.

## Open questions

None.
