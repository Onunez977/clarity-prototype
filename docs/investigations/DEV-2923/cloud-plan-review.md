**REQUEST CHANGES.** The platform-only logging shape holds. The forced code-159 proof, as written, expires at curl before ClickHouse starts its execution-time check.

# DEV-2923 cloud plan review

Review only. The primary draft is `docs/investigations/DEV-2923/claude-plan-draft.md` on `ff86442c65123b5d3f8c2f708dab7d7eb7839b5b`. Prep gate is CLEAN. This pass re-read the installed client (`composer.lock` reference `44d6db805435f63b63b7a11f903287af8a4d8b06`), the cited loggers, the spies, and live Jira [DEV-2923](https://insightlpr-team.atlassian.net/browse/DEV-2923) including First Glance comment 43033 (Ready, main→main). No product PHP, no package PR, no commit. ClickHouse was not queried.

## Verdict

**REQUEST CHANGES**

One change: the forced-timeout recipe cannot produce ClickHouse code 159 inside the curl budget it specifies. Nits below stay in the same plan; they do not reopen the gate.

## Changes

1. **Rewrite the AC-1 statement so the server throws 159 before curl does.** Keep it test-local on `clarity_clickhouse` against `127.0.0.1:8123`. Do not write it into `config/database.php`, `.env`, `.env.testing` defaults, or the dashboard constants.
   - `ClickhouseHttpClient::request()` sets `CURLOPT_TIMEOUT` to `timeout_query + timeout_connect` (`ClickhouseHttpClient.php:408`). The draft sets `timeout_query` to 1 and leaves `timeout_connect` at the config default of 2 (`config/database.php:171`). The curl budget is 3 seconds.
   - ClickHouse does not apply `max_execution_time` as a wall clock until `timeout_before_checking_execution_speed` elapses. That setting defaults to 10 seconds. Until then, a 1-second `max_execution_time` does not abort the query. Curl fails first with `cURL error: …` (`ClickhouseHttpClient.php:421-423`). `ch_code` stays null, because that message does not match `ClickHouse error (\d+)`.
   - ClickHouse's own stateless proof is `SELECT * FROM numbers(100000000)` with `max_block_size=1`, `timeout_before_checking_execution_speed=1`, `max_execution_time=2`, `timeout_overflow_mode='throw'` (`tests/queries/0_stateless/02896_max_execution_time_with_break_overflow_mode.sql` in the ClickHouse repo). `SELECT count() FROM numbers(100000000000)` is the wrong shape for that proof.
   - Lock the test connection to `timeout_query=2`, `timeout_connect=5`, `retries=0` (curl budget 7) and add test-only settings `timeout_before_checking_execution_speed=0` and `max_block_size=1`. Run `SELECT * FROM numbers(100000000)` with no `FORMAT` clause — `select()` appends ` FORMAT JSON` (`ClickhouseHttpClient.php:44`). Assert `ch_code === 159` and `TIMEOUT_EXCEEDED` in the message.
   - `buildUrl()` already copies `settings` onto the query string after `max_execution_time` (`ClickhouseHttpClient.php:376-384`), so those two keys belong on the purged test connection's `settings` array. `public_clickhouse` already overrides `max_execution_time` from settings (`config/database.php:228-229`); this test stays on `clarity_clickhouse`, which does not.

## Nits

- The timeout test's caller assertion disagrees with the walker. The walker keeps the first `App\` frame outside `App\Clickhouse\Logging\`. `ClickhouseTimeoutLoggingTest` lives in `Tests\`, so a direct `select()` from the test has no `App\` frame. Drive the query from an `App\` invoker (the production shape: `HotlistAddressGeohashQuery::queryClickhouseRaw` calls `DB::connection('clarity_clickhouse')->select` at `HotlistAddressGeohashQuery.php:165`) and assert that class. Keep the `App\` rule.
- Assert one `statement()` / `write()` failure as well (`statement=write`). `insert()` reaches `$this->write()` (`ClickhouseHttpClient.php:93`); an untested override can drift. Put the emit in one private method both overrides call.
- `redactBindings()` has to accept every type `formatValue()` accepts — null, bool, int, float, `DateTimeInterface`, array, string (`ClickhouseHttpClient.php:277-307`) — without throwing. A throw inside the log `try` is swallowed and the `db-error` line never lands. Add an array-binding unit case.
- `server_elapsed_ms` is best-effort. Pass the test on `ch_code === 159` and `TIMEOUT_EXCEEDED`. Parse milliseconds when the message contains `elapsed <number> ms`. A seconds-formatted message stays null and still passes.
- The success-path write must be SQL ClickHouse accepts. `Connection::statement()` → `write()` (`Connection.php:61-63`), so `SELECT 1` through `statement()` is a successful write. `TRUNCATE TABLE IF EXISTS` with no table name throws, gets logged, and fails the zero-record assert. `RequiresClickhouse` already truncates real tables in `setUp` (`RequiresClickhouse.php:46-54`), before `Log::spy()`.
- Copy `timeout_query`, `timeout_connect`, `retries`, and `settings['max_execution_time']` into typed properties. The raw config array also holds username, password, and host.
- Relativize `caller_file` to the repo root. `debug_backtrace` paths are absolute.
- Keep the bindings key as `bindings_redacted`. `scripts/extract_sql_queries.py:48-51` keeps a line when `context.sql` is present and substitutes `context.bindings`. These lines stay in the extract with placeholders intact.
- Empty `insert()` throws `Cannot insert empty values` before `write()` (`ClickhouseHttpClient.php:74-76`). Leave that throw unenriched. HTTP failures still hit the `write()` override.
- `scripts/audit-card-query-sql.php:74-78` reflects `client` on `LaravelClickhouseEloquent\Connection` and swaps in a stock subclass. That still works when the instance is `LoggingClickhouseConnection`. No script edit.

## AC coverage

| AC | Draft | Judgment |
| --- | --- | --- |
| AC-1 Forced timeout names the query without `system.query_log` | `db-error` line with `sql` plus the first `App\` caller, route, `ch_code` | Right channel and fields. The Docker proof must be the recipe in Changes. Uncaught exceptions still reach the Laravel log with the original thin message; the `db-error` line is the actionable one, which matches the prep acceptance note. |
| AC-2 Successful queries unchanged | One `hrtime(true)` read before `parent::*`; delta and log only inside `catch` | Holds. No settings write, no SQL mutation, no second request. The success-path feature test belongs in a class that does not use `RequiresClickhouse`. |
| AC-3 Credentials and truncation | 4096 head-biased, credential regex, bindings separate | Holds. Re-measured below. |
| AC-4 Org-469-style 159 before/after on the PR | Same forced local timeout, stock client vs logging client; org-469 query not run | Holds, once Change 1 actually yields code 159. The sample states it is a local 159 of the same class. |

Live ticket acceptance matches this table. `query_id` stays optional: the ticket asks for it when the client has one. `X-ClickHouse-Query-Id` is discarded in private `request()` (`ClickhouseHttpClient.php:416-419` returns the body only). Omitting it keeps the work platform-only.

## Agreements

- Throw site is `throw new ClickhouseException($lastError)` at `ClickhouseHttpClient.php:438`. `ClickhouseException` is an empty `RuntimeException` (`Exceptions/ClickhouseException.php:7-9`), so `getCode()` stays 0. Rethrow that same instance. Wrapping it would miss `catch (ClickhouseException …)` in `Org.php:221` and `:257`, and `TrackAnalyticsTest.php:111`.
- `select()` and `write()` are the right overrides. `insertAssocBulk()` delegates to `insert()` (`ClickhouseHttpClient.php:121`), and `insert()` calls `$this->write()`.
- Parent properties are private (`ClickhouseHttpClient.php:13-20`). The subclass must keep its own copy of the numbers it logs.
- `Connection::createWithClient()` does `new static` and then assigns a stock `ClickhouseHttpClient` (`Connection.php:26-27`). The connection subclass must override `createWithClient()`. `ClickhouseModel::newBaseQueryBuilder()` checks `instanceof ClickhouseConnection` (`ClickhouseModel.php:22`); a subclass still selects the ClickHouse builder.
- `DatabaseManager::extend` replaces `$this->extensions[$name]` (`DatabaseManager.php:428-430`). `ClickhouseServiceProvider` is after `AppServiceProvider` (`bootstrap/providers.php:16` and `:24`) and registers the stock resolver (`ClickhouseServiceProvider.php:22-25`). A `booted()` callback runs after every provider `boot()` (`Application.php:1131-1137`). `AppServiceProvider` already registers one (`AppServiceProvider.php:113-118`). No other `extend('clickhouse')` exists. The pgsql bridge (`config/database.php:161`) never consults that resolver.
- Nothing in `app/Providers` resolves a ClickHouse connection during `boot()`. The draft's purge-and-assert guard is still the right check that the winning resolver is `LoggingClickhouseConnection` / `LoggingClickhouseHttpClient`.
- Spies stay source-compatible. `KitUserPathControllerTest`, `ClarityScoutBirdsEyeControllerTest`, and `CloseInactiveShiftsCommandTest` extend the stock client, override `select(string $sql, array $bindings = []): array`, call `parent::select()`, and replace the protected `client` via reflection (`KitUserPathControllerTest.php:210-226`, `CloseInactiveShiftsCommandTest.php:400-428`). `getClient(): ClickhouseHttpClient` (`Connection.php:15-18`) accepts those subclasses. They bypass the logging wrapper on purpose. Do not change the `select()` signature or the `client` property type.
- Existing thin loggers stay unedited. `HasRetryQuery` logs `sql` only for `QueryException` (`HasRetryQuery.php:48-58`); `ClickhouseException` hits the generic catch at `:96-107`. `Org` and `HasHitMetricCards::logHitMetricCardFailure` (`HasHitMetricCards.php:389-403`) log message and code. `ClickhouseQuery::query()` `report()`s and returns `[]` (`ClickhouseQuery.php:29-37`). `bootstrap/app.php` counts PostgreSQL `57014` only. Logging before rethrow covers those paths from below.
- `db-error` is the daily channel to `storage/logs/db_error.log` (`config/logging.php:69-76`), tapped by `DataDogHandler`, which already adds user id, name, email, and org id/name on error. New fields are ids only. Message `ClickHouse HTTP exception`. Context key `sql` is what `extract_sql_queries.py` requires.
- Truncation stays **4096, head-biased**, suffix `…[truncated N chars]`, with `sql_original_length`. `HotlistAddressGeohashQuery`'s heredoc is 1361 characters; `public_address` is at 384, `hotlist FINAL` at 940, `org_id IN` at 1041, `length(plate)` at 1139 (`HotlistAddressGeohashQuery.php:133-163`). The polygon is interpolated at `:160` and is what blows past 4096. `UNION ALL` has no matches under `app/`. The longest composed product statement checked, `UniqueLprCaseClaimsQuery` base+tail, is 5020 characters; `billing_claim_events_v` is at 565, the org predicate at 816, `hits_cold` at 2063, and `pre_claim` at 3168. The tail past 4096 is the join-guard comment. 8192 is not justified.
- Redaction list matches prep: password, secret, token, `api_key`, `api-key`, authorization assignments, and bearer tokens become `[redacted]`. Plates, org ids, kit ids, dates, geohashes, and polygon coordinates stay. Credentials in the log path are the curl headers (`ClickhouseHttpClient.php:409-413`), which are not arguments to `select()` / `write()`.
- Timeout numbers in the log are copies. `LprRepossessions` stays read-only: `OBSERVED_MAX_LATENCY_SECONDS` 33.7 (`LprRepossessions.php:99`), `QUERY_TIMEOUT_SECONDS` 60 (`:111`), `COLD_LOCK_SECONDS` (`:151`), `REFRESH_LOCK_SECONDS` (`:159`). Write-path connect budget and retries (`config/database.php:201-204`) stay as they are.
- Two classes, plus the `booted()` re-extend, is the smallest shape prep already proved. Public static `truncateSql()` / `redactSql()` / `redactBindings()` on the client match `formatValue()` / `formatInsertValue()` and avoid a third type. Leave `ClickhouseFailureContext` unused.
- Package PR stays escalate-only, and only if a later phase makes `query_id` mandatory. This plan does not.
- `okf/` stays queued. After approval, one line on `okf/concepts/clickhouse-integration.md` plus `php scripts/okf-lint.php`.
- Provision: real Docker ClickHouse, this ticket's own twin, never the DEV-2904 Path A twin, Cloud ClickHouse untouched. `RequiresClickhouse` truncates its table list on setup; the forced-timeout statement needs no seed. Soft #27 does not apply (no UI). Open questions stay empty.

## What must change in the draft before Implement

- Replace the `ClickhouseTimeoutLoggingTest` 159 recipe with the test-local settings and `SELECT * FROM numbers(100000000)` in Changes. State that those settings are not product defaults.
- Fold the nits that affect assertions (App\ caller, one `write()` failure, non-string bindings, `server_elapsed_ms`, the success-path write statement) into the test list.
- Record the audit-script reflection check as already verified.