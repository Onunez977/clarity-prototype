<!-- ticket-status -->
**Ticket:** [DEV-2923](https://insightlpr-team.atlassian.net/browse/DEV-2923)
**Phase:** Implemented
**Gate:** CLEAN
**Done:** intake · Ultra-Prep · Ultra-Plan · Provision · Implement
**Next:** Review
**Open questions:** none
**Branch path:** main→main
**Base:** `1eaf006989221ec50e29d1ef9b4956299fe50600`
**Tip:** `42389469`
**Ship:** Observability only. Platform `LoggingClickhouseHttpClient` + `LoggingClickhouseConnection` enrich `db-error` with truncated SQL and caller context for `TIMEOUT_EXCEEDED` (159) and sibling HTTP failures. No timeout budget changes. No product query rewrites. Truncation locked at 4096 head-biased.
**Platform vs package:** platform-only. Package PR not opened. `query_id` omitted.
**Verdict:** APPROVE-with-adopted-changes (Change 1 + 10 nits) — implemented
**PR:** https://github.com/insight-lpr/platform/pull/502 **DRAFT**
**First Glance:** 43033 (2026-10-05, Ready)
**Cloud ClickHouse:** no reads, no writes
**Assignees:** Jira Dalton McCleery (unchanged). GitHub assignee DaltonMcCleery.
<!-- /ticket-status -->


# DEV-2923 journal

## Intake (2026-10-05)

Live Jira re-GET. Bug, To Do, Sprint 42, Medium, label `NonUser_Reported`, assignee Dalton McCleery. Summary: ClickHouse timeouts — log SQL / caller context so `TIMEOUT_EXCEEDED` is actionable.

Acceptance on the ticket:

- A forced ClickHouse timeout in fleet or local Docker ClickHouse puts enough SQL and caller/route into `db_error` and/or the Laravel ERROR to name the query without `system.query_log`.
- Successful queries stay unchanged aside from log I/O.
- SQL logging does not dump credentials, and truncation rules are documented.
- An org-469-style `TIMEOUT_EXCEEDED` line would have been actionable. Prove with a before/after sample on the pull request.

Out of scope on the ticket: fixing Reliable Recovery's slow query, enabling ClickHouse Cloud MCP, Datadog/RUM.

One comment, id **43033**, First Glance, 2026-10-05T09:02:53-04:00, verdict **Ready**, branch path **main→main**. That comment wins over the uploaded judgment file. The judgment agrees on the surface and the hard stop.

`origin/main` at branch time is `1eaf006989221ec50e29d1ef9b4956299fe50600`.

This phase writes docs under `docs/investigations/DEV-2923/` only. No product PHP. No package pull request. No timeout edits. No Cloud ClickHouse queries. DEV-2904 was not opened.

## Ultra-Prep (2026-10-05)

Read `okf/index.md`, `okf/concepts/data-architecture.md`, `okf/concepts/clickhouse-integration.md`, `okf/concepts/repositories.md`, and the `HasRetryQuery` row in `okf/concepts/filament-conventions.md`. ClickHouse stays the analytical read side. The wiki's timeout story matches the code: read-path `timeout_query` defaults to 60 seconds and is client-side because server `max_execution_time` is 0. Wiki edits stay queued.

### Throw site

Package `insight-lpr/laravel-clickhouse-eloquent` `@dev`, lock reference `44d6db805435f63b63b7a11f903287af8a4d8b06`, repo `insight-lpr/laravel-clickhouse`. Installed at `vendor/insight-lpr/laravel-clickhouse-eloquent/src/ClickhouseHttpClient.php`.

`select()` binds parameters, then calls `request($sql . ' FORMAT JSON')`. `write()` binds, then calls `request($sql)`. `insert()` / `insertAssocBulk()` build an INSERT and call `write()`.

`request()` is private. It POSTs the body with `X-ClickHouse-User` and `X-ClickHouse-Key`. Connect timeout is `timeout_connect`. Curl timeout is `timeout_query + timeout_connect`. The URL sets `max_execution_time` from `timeout_query`, then connection `settings` overwrite that key. `public_clickhouse` sets `max_execution_time` in settings. `clarity_clickhouse` does not, so the URL value stands.

On curl error, HTTP non-200, or a parsed ClickHouse body, the method throws `new ClickhouseException($lastError)` at line 438. `parseClickhouseError()` keeps `Code: NNN` plus the `DB::Exception` text, or a trimmed HTTP body. It does not set the exception code. `ClickhouseException` is an empty `RuntimeException`, so `getCode()` is 0. The response headers, including any `X-ClickHouse-Query-Id`, are discarded. The SQL is not attached.

A code 159 line therefore looks like `ClickHouse error 159: Timeout exceeded: elapsed … ms, maximum: …`. The elapsed figure in today's logs is that sentence, not a separate field. Curl timeouts look like `cURL error: …` and carry no SQL either.

### Who reaches that throw

`LaravelClickhouseEloquent\Connection::select()` and `statement()` forward to the client. `Builder::getRows()` uses `connection->select()`. Bulk inserts on the model call `getThisClient()->insert()`, which still ends in `write()` → `request()`.

`ClickhouseServiceProvider` is registered in `bootstrap/providers.php` after `AppServiceProvider` and replaces the `clickhouse` driver with `Connection::createWithClient()`. `createWithClient()` does `new static` and then assigns a stock `ClickhouseHttpClient`. A platform subclass that only extends `Connection` still gets the stock client unless it overrides `createWithClient()`. The driver callback has to be registered again from a `booted()` callback. `AppServiceProvider` already registers one `booted()` callback (the catch-all route). A second callback there runs after the package provider.

The pgsql test bridge never uses this driver. `.env.testing` in this environment sets `CLICKHOUSE_DRIVER=clickhouse` against `127.0.0.1:8123` and does not override `CLICKHOUSE_TIMEOUT_QUERY`, so the config default of 60 seconds applies. This phase did not open a connection.

### Who writes `db_error`

Channel key is `db-error` in `config/logging.php`. Path is `storage/logs/db_error.log`, daily driver, five days, JSON via `DataDogHandler`. The processor adds the authenticated user id, name, and email, and on error-and-above the org id and name.

Call sites that log to that channel:

- `HasRetryQuery` logs `sql` only for `Illuminate\Database\QueryException`. A `ClickhouseException` hits the following `catch (\Exception)` and logs message, code, file, and line. File/line are `ClickhouseHttpClient.php:438`. Used by list pages for scans, hits, missed opportunities, and affiliate stats.
- `Org::getHotlistClients()` and `getHotlistLienholders()` log message and code, then return an empty collection when debug is off.
- `HasHitMetricCards` logs class, message, code, file, and line.

`ClickhouseQuery::query()` does not use `db-error`. In production and testing it `report()`s and returns `[]`. `bootstrap/app.php` counts only PostgreSQL `57014` / `statement timeout`. Uncaught `ClickhouseException` still reaches the default Laravel log, with the thin message and a stack, and without SQL.

`scripts/extract_sql_queries.py` keeps a line only when `context.sql` is present. Today's CH lines are skipped.

Several tests already subclass `ClickhouseHttpClient` and swap the connection's protected `client` via reflection (`KitUserPathControllerTest`, `ClarityScoutBirdsEyeControllerTest`, `CloseInactiveShiftsCommandTest`). `select()` is public and not final. Plan should install the logger as the connection's client and leave those spies extending the parent class.

### Platform-only vs package

Platform-only is enough for the acceptance criteria.

A platform subclass of `ClickhouseHttpClient` can override `select()` and `write()`, time the parent call, and on `ClickhouseException` write one `db-error` record with truncated SQL, redacted bindings, the first `App\` caller frame, route when it is useful, user id, org id, client elapsed ms, server elapsed parsed from the message, and the timeout settings copied from the connection config. It rethrows the same exception so existing catches, metrics, and tests keep their current messages. `insert()` reaches `write()` on `$this`, so inserts are covered without a second log.

That needs no package change and no composer bump. It does not change `timeout_query`, settings, or any product SQL. Successful calls do not log.

`query_id` is the gap. The server id is a response header that private `request()` never reads, and the exception has nowhere to put it. The ticket lists it as "if the client has one." This client does not. SQL plus the caller frame names the query without `system.query_log`. Escalate only if a later phase makes `query_id` mandatory. That is not a park question.

Package edit at line 438 would also work, and would be the only way to stamp the id on every failure including spies that extend the stock client. Package pull requests are outside this prep. Do not open one.

### Truncation and redaction

Candidates are in `prep-context.json`. Recommended lock for Plan: 4096 characters of the SQL argument, head-biased, with the original length and a truncated flag. Credential-shaped assignments and bearer tokens are replaced. Plates and org ids stay. Passwords, keys, hosts, and HTTP headers are not logged. Bindings are a separate redacted list, not interpolated into the logged SQL.

### Gate

CLEAN. The throw site, the `db-error` paths, the hard stops, and the platform-versus-package cite are all in the repo. No product behavior was changed. Forecast gates 1–4 stay open for Implement. Forecast gate 5 is answered here.

Next is Ultra-Plan.

## Pull request (2026-10-05)

Draft pull request https://github.com/insight-lpr/platform/pull/502 targets `main`.

Assigning DaltonMcCleery failed: `Resource not accessible by integration (replaceActorsForAssignable)`. Jira assignee stays Dalton McCleery. Recorded and continued.

## Ultra-Plan (2026-10-05 8:34am CDT · sealed)

Claude-first #23 complete on Mac worktree.

1. Opus primary draft → `claude-plan-draft.md` (tip through `61a9e38f2`).
2. CloudAgent `bc-9dd40489` review → **REQUEST CHANGES** (`cloud-plan-review.md`). Blocking Change 1: rewrite forced-timeout recipe so server throws code 159 before curl (`timeout_query=2`, `timeout_connect=5`, `retries=0`, settings `timeout_before_checking_execution_speed=0` + `max_block_size=1`, `SELECT * FROM numbers(100000000)`). Ten nits on assertions/hygiene.
3. Opus reconcile → **APPROVE-with-adopted-changes** (`claude-plan-reconcile.md`). Adopted Change 1 + all ten nits; rejected none. Extra deltas: App\ caller also needed on curl-error test; prefer `App\Jobs\TrackAnalytics` for write-path proof; walker no-App\ null fallback; AC-2 stays off `RequiresClickhouse`.
4. Sealed `plan.json` + `plan.md`. Gate **CLEAN**. Next **Provision** (own fleet Docker CH twin — not DEV-2904 Path A).

No product PHP. Cloud CH untouched. Open questions: none.

## 2026-10-05 ~8:40am CDT — Provision done

- Cited Galadriel Path A LIVE ledger (`qa-prep-ledger.json`, prove_passed) on own twin :18091/:15443/:18134.
- Smoke: login 200, PG public=139 platform=41, CH SELECT 1 OK, cloud_touched=false.
- DEV-2904 Path A LEFT UP untouched.
- Wrote provision.md / provision.json; ticket-status → Provisioned · Next Implement.
- .context/qa-prep-ledger.json on disk (not committed).
- No product PHP. Next: Implement baton.

## Implement (2026-10-05)

Logging client and connection are in `app/Clickhouse/Logging/`. `AppServiceProvider::booted()` re-extends the `clickhouse` driver after `ClickhouseServiceProvider`, so the first resolved connection uses `LoggingClickhouseConnection::createWithClient()`.

`select()` and `write()` time the parent call, and on `ClickhouseException` write one `db-error` line (`ClickHouse HTTP exception`) then rethrow that same exception. Log failures are swallowed. Typed copies hold `timeout_query`, `timeout_connect`, `retries`, and settings `max_execution_time`. The raw config array is not kept. `query_id` is omitted.

AC-1 on Docker ClickHouse `127.0.0.1:8123` (26.2.19.43). The cited ledger port `:18134` refused connections; this environment's `.env.testing` host was used. The Change 1 statement does return `Code: 159` / `TIMEOUT_EXCEEDED`, but 26.2 writes that trailer after a partial HTTP 200 body and closes the socket. The package client records `cURL error: transfer closed with outstanding read data remaining` and drops the body, so `ch_code` would stay null. The timeout test merges the two Change 1 settings and adds test-local `wait_end_of_query=1`, which returns HTTP 408 with code 159 before any rows. Not written to `config/database.php`, `.env`, `.env.testing`, or dashboard constants. Org-469 query was not run. Cloud ClickHouse was not queried. DEV-2904 ports were not touched.

Libcurl's blackhole message includes the connect target. The logged `exception_message` replaces `to <host> port <n>` with `[redacted]`. The thrown exception text is unchanged.

Caller file is the relativized `debug_backtrace` path. `TrackAnalytics::handle` is the write-path `caller_class`; its frame file is the test that called `handle()`.

```
php artisan test --compact tests/Unit/Clickhouse/ClickhouseLogRedactionTest.php tests/Feature/Clickhouse/ClickhouseFailureLoggingTest.php tests/Feature/Clickhouse/ClickhouseTimeoutLoggingTest.php
Tests: 11 passed (96 assertions)
Duration: 8.09s
```
