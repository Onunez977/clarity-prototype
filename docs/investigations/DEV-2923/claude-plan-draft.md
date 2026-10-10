# DEV-2923 — Ultra-Plan (Opus primary draft)

> Read-only planning artifact. No code written, no `plan.json`. Branch path **main→main**; draft PR [#502](https://github.com/insight-lpr/platform/pull/502) (base `main`, tip `1644bd848`). Prep gate **CLEAN**, recommendation **platform-only**, package PR **not required**.

## Summary

`ClickhouseHttpClient::request()` throws a bare `ClickhouseException` carrying only a parsed message string (`ClickhouseHttpClient.php:438`), while the SQL and bindings stay as locals in the public `select()`/`write()` frames above it. This plan adds a platform subclass of that client which catches `ClickhouseException` around the parent call, writes one structured `db-error` record containing truncated+redacted SQL, the first `App\` caller frame, the route, and the effective timeout numbers, then rethrows the *same* exception instance so every existing catch (`HasRetryQuery`, `Org`, `HasHitMetricCards`, `ClickhouseQuery::query()`) keeps behaving exactly as it does today. A `Connection` subclass and a re-registration of the `clickhouse` driver from `AppServiceProvider`'s `booted()` callback install it. No timeout budget, product query, package file, or dashboard constant is touched.

## AC → implementation mapping

| AC | Implementation | Proof |
| --- | --- | --- |
| **AC-1** — forced CH timeout names the query without `system.query_log` | `LoggingClickhouseHttpClient::select()`/`write()` catch → `db-error` line with `sql`, `caller_class`, `caller_function`, `caller_file`, `caller_line`, `route_name`, `route_uri`, `ch_code`, `connection` | Feature test forcing CH code **159 TIMEOUT_EXCEEDED** against local Docker CH; PR sample |
| **AC-2** — successful queries unchanged | Nothing added to the success path except one `hrtime(true)` read before `parent::*`; delta is computed only inside `catch`. No settings write, no SQL mutation, no second request | Feature test asserting `Log::spy()` records **zero** `db-error` writes across a successful `select()` + `write()` |
| **AC-3** — no credentials dumped; truncation documented | Credentials live only in curl headers (`ClickhouseHttpClient.php:409-413`), never in the SQL argument and never in the exception; plus an explicit credential-assignment regex over SQL and bindings. Truncation locked at **4096 chars, head-biased** | Unit tests over the public static `truncateSql()` / `redactSql()` / `redactBindings()` helpers (no network, always run) |
| **AC-4** — org-469-style 159 would have been actionable | Before/after pair captured from the same forced local timeout — stock client line vs logging client line | Attached to PR #502 at Implement |

## File touch list

### Create

**`app/Clickhouse/Logging/LoggingClickhouseHttpClient.php`** — extends `LaravelClickhouseEloquent\ClickhouseHttpClient`.

- Overrides **only** `select(string $sql, array $bindings = []): array` and `write(string $sql, array $bindings = []): bool`. `insert()` builds its SQL then calls `$this->write($sql)` (`ClickhouseHttpClient.php:93`) and `insertAssocBulk()` delegates to `insert()` (`:121`), so one `write()` override covers every insert path — no `insert()` override needed.
- **Must keep its own copy of `$config`.** Every parent property is `private` (`ClickhouseHttpClient.php:13-20`), so `timeout_query`, `timeout_connect`, `retries`, `settings`, and `name` are unreadable from a subclass. Constructor signature stays `__construct(array $config)`, calls `parent::__construct($config)`, and stores the array.
- Exposes `truncateSql()`, `redactSql()`, `redactBindings()` as **public static** helpers. This matches the package's own idiom — `ClickhouseHttpClient::formatValue()` and `::formatInsertValue()` are public statics (`:277`, `:313`) — and makes AC-3 unit-testable with no network and no extra class. (If review prefers them extracted, a `ClickhouseFailureContext` value class is the fallback; this draft chooses the 2-class shape for #24.)
- The log emit itself is wrapped in its own `try { … } catch (\Throwable) { }`; the original `ClickhouseException` is rethrown unconditionally. A misconfigured channel must never mask a CH failure.

**`app/Clickhouse/Logging/LoggingClickhouseConnection.php`** — extends `LaravelClickhouseEloquent\Connection`, overrides `createWithClient()`.

The override is required, not cosmetic: `parent::createWithClient()` does `new static(...)` and then unconditionally assigns `$conn->client = new ClickhouseHttpClient($config)` (`Connection.php:24-30`), so subclassing alone would still get the stock client. Everything else (`select()`, `statement()`, grammars, `escapeString()`) is inherited unchanged.

Compatibility check: `App\Clickhouse\Models\ClickhouseModel::newBaseQueryBuilder()` branches on `$connection instanceof ClickhouseConnection` (`ClickhouseModel.php:22`) — a subclass satisfies that, so the ClickHouse query builder is still selected.

### Edit

**`app/Providers/AppServiceProvider.php`** — one added `$this->app->booted()` block re-extending the `clickhouse` driver.

`ClickhouseServiceProvider::boot()` registers `db->extend('clickhouse', …)` with `Connection::createWithClient` (`ClickhouseServiceProvider.php:18-26`), and it is listed at `bootstrap/providers.php:24` — **after** `AppServiceProvider` at `:16`. A plain `boot()` extend would be overwritten. A `booted()` callback runs after every provider has booted, so it wins. `AppServiceProvider::boot()` already uses this exact mechanism for the web catch-all route (`AppServiceProvider.php:113-118`), so this is the established pattern rather than a new one. The closure mirrors the package's: set `$config['name'] = $name`, then `return LoggingClickhouseConnection::createWithClient($config)`.

*Alternative considered and not chosen:* a new provider appended last in `bootstrap/providers.php`. It works today but silently reverts to the stock client if anyone reorders that array; `booted()` is order-independent.

*Registration risk to verify at Implement:* `DatabaseManager::extend` only affects connections resolved **after** registration. If any boot-time code resolves `clarity_clickhouse` before `booted()` fires, that instance keeps the stock client. CH connections are resolved lazily on first query in HTTP/console/queue paths, so this is expected to be a non-issue — but it is cheap to assert, so a guard test is included below rather than assumed.

*pgsql bridge:* when `CLICKHOUSE_DRIVER=pgsql` (local/testing default) the connections resolve through Laravel's pgsql driver (`config/database.php:161`, `:189`, `:214`) and the `clickhouse` extend is never consulted. Nothing changes on that path.

*Dev script note:* `scripts/audit-card-query-sql.php` imports `LaravelClickhouseEloquent\Connection` directly (`:29`). Confirm at Implement that it does not depend on the driver returning the package class.

### Create (tests)

- `tests/Feature/Clickhouse/ClickhouseFailureLoggingTest.php` — **no** `RequiresClickhouse` trait.
- `tests/Feature/Clickhouse/ClickhouseTimeoutLoggingTest.php` — **uses** `RequiresClickhouse`.
- `tests/Unit/Clickhouse/ClickhouseLogRedactionTest.php` — pure statics, no container/network.

Split per the known trap: a class-level `RequiresClickhouse` skips the whole file when `CLICKHOUSE_DRIVER=pgsql`, which would quietly hide the redaction and success-path guarantees behind a green local run. The CH-free guarantees live in sibling classes that always execute.

## Logging design

**Channel** `db-error` (`config/logging.php:69-76` → `storage_path('logs/db_error.log')`, daily, 5 days, so dated files are `db_error-YYYY-MM-DD.log`; tapped by `App\Log\DataDogHandler`, which applies `JsonFormatter` and appends `extra.user.{id,name,email}` plus `extra.user.org.{id,name}` on error records).

**Level** `error`. **Message** `ClickHouse HTTP exception`.

**Key name `sql` is load-bearing** — `scripts/extract_sql_queries.py` reads `context.sql` and skips lines without it.

### Context fields

| Field | Source |
| --- | --- |
| `sql` | the `$sql` **argument** (placeholders intact, bindings *not* interpolated), redacted then truncated. For `select` this is the statement before the client appends `' FORMAT JSON'` (`ClickhouseHttpClient.php:44`) |
| `sql_truncated` | bool |
| `sql_original_length` | int, pre-truncation `strlen` |
| `statement` | `select` or `write` — known from which override is executing |
| `bindings_redacted` | list, capped |
| `binding_count` | int, pre-cap |
| `bindings_truncated` | bool |
| `ch_code` | `preg_match('/ClickHouse error (\d+)/', …)` → int, else `null` (curl errors and `ClickHouse HTTP NNN` bodies have no CH code; the HTTP status stays visible inside `exception_message`) |
| `exception_message` | `$e->getMessage()` verbatim — already free of credentials (`parseClickhouseError`, `:444-456`, only echoes the response body) |
| `caller_class` / `caller_function` / `caller_file` / `caller_line` | first `App\` frame that is not in `App\Clickhouse\Logging\` |
| `route_name` / `route_uri` | when a request is bound; `livewire.update` is a weak name, so `caller_class` is authoritative there |
| `console` | bool |
| `user_id` / `org_id` | `auth()->id()`, `auth()->user()?->org_id` — ids only, attribute reads, **no extra query**. Name/email are already added by `DataDogHandler`; do not duplicate |
| `connection` | `$config['name']`, set by the extend closure before `createWithClient` (mirrors `ClickhouseServiceProvider.php:23`) |
| `client_elapsed_ms` | `hrtime(true)` delta, failure path only |
| `server_elapsed_ms` | `preg_match('/elapsed\s+([0-9.]+)\s*ms/i', …)` or `null` |
| `timeout_query` / `timeout_connect` / `retries` | read from the stored config array — **values are copied, never changed** |
| `curl_timeout` | `timeout_query + timeout_connect`, matching `ClickhouseHttpClient.php:408` |
| `max_execution_time` | `$config['settings']['max_execution_time'] ?? $config['timeout_query']`, matching `buildUrl()` (`:376-384`) where `settings` overwrite the URL param |
| `query_id` | **omitted.** `X-ClickHouse-Query-Id` is discarded in the private `request()`; capturing it means a package edit, which is explicitly out of scope |

### Truncation — locked at 4096, head-biased

Keep the head; when cut, suffix `…[truncated N chars]`. **No head+tail.** Always record `sql_original_length`.

Measured justification (this is the "measure before choosing 8192" step prep asked for):

- The only query in the app that injects an unbounded literal is `HotlistAddressGeohashQuery` — `rg 'polygonLiteral|pointInPolygon' app/` returns exactly one call site (`HotlistAddressGeohashQuery.php:58`). Its heredoc (`:133-163`) places **every** identifying element — `{$zodDb}.public_address`, `{$clarityDb}.hotlist FINAL`, `org_id IN ({$orgList})`, `status = 1`, `length(plate) >= 3`, `{$stagedFilter}` — inside roughly the first 1.1 KB. The unbounded `pointInPolygon(...)` literal is appended afterwards at `:160`, and only `GROUP BY geohash` + `HAVING` trail it. A 4096-char head names this query comfortably; a tail segment would buy only the tier band.
- `rg 'UNION ALL' app/` → **0 matches.** No product query hides its identifying predicate in a trailing branch, which is the one shape that would justify head+tail.
- The largest-by-construction statement is an `insert()`-built `INSERT INTO <table> (cols) VALUES (…)` (`ClickhouseHttpClient.php:79-93`): the table and column list are the identifying part and they lead. 4096 is more than enough; `sql_original_length` records the true size.

**Bindings:** max 32 items, each string capped at 200 chars, overflow drops the tail and sets `bindings_truncated = true`.

### Redaction

Never logged, by construction: connection password, username, host, port, URL, `X-ClickHouse-Key`, `X-ClickHouse-User`, any HTTP header. None of these appear in the `$sql` argument or in `ClickhouseException`; they exist only in `curl_setopt_array` (`:402-414`) and in the private config properties the subclass deliberately does not echo.

Belt-and-braces over `sql` and each binding: case-insensitive replacement of `password`, `secret`, `token`, `api_key`, `api-key`, `authorization` assignments (`key=value` or `key: value`) and bearer tokens with `[redacted]`.

Not redacted, on purpose: plates, org ids, kit ids, dates, geohashes, polygon coordinates. The entire point of the line is to name the query. `DataDogHandler` already writes user name and email on this channel for authenticated requests — pre-existing, out of scope to change.

Bindings are logged **separately** from the SQL and never interpolated into it, so a secret passed as a binding can be masked before it reaches disk.

### Rethrow

`throw $e;` — the original instance, message, code (`0`), file, and line untouched. `ClickhouseException` is an empty `RuntimeException` subclass (`Exceptions/ClickhouseException.php:7-9`); wrapping it would break `catch (ClickhouseException …)` in `Org.php:221`/`:257`, `HasHitMetricCards.php:389-403`, and the `expectException` in `TrackAnalyticsTest`.

Because the enricher logs *before* rethrowing, every swallowing path downstream now has a rich line preceding its thin one: `Org`'s cache warms, `HasHitMetricCards`, `ClickhouseQuery::query()`'s `report() + return []` (`ClickhouseQuery.php:29-37`), and `HasRetryQuery`'s generic `\Exception` catch (`HasRetryQuery.php:96-107`, which has no `sql` because `ClickhouseException` is not an `Illuminate\Database\QueryException` and so never reaches the `getSql()` branch at `:52`). None of those files are edited.

## Provider / client registration order

1. `AppServiceProvider::boot()` runs (providers.php:16).
2. `ClickhouseServiceProvider::boot()` runs (providers.php:24) and registers the stock extend.
3. Framework fires `booted` callbacks → the plan's closure re-registers `clickhouse` with `LoggingClickhouseConnection::createWithClient`.
4. First CH query resolves the connection through the winning extension.

## Tests

**`tests/Unit/Clickhouse/ClickhouseLogRedactionTest.php`** (always runs)
- `truncateSql()` under 4096 → unchanged, `sql_truncated` false.
- `truncateSql()` over 4096 → head preserved, `…[truncated N chars]` suffix, `sql_original_length` exact.
- A reconstructed `HotlistAddressGeohashQuery`-shaped statement with a large synthetic ring → truncated output still contains `public_address`, `hotlist FINAL`, `org_id IN (`, `length(plate) >= 3`. This is the AC-3/AC-1 interaction locked as a regression.
- `redactSql()` masks `password=…`, `api_key: …`, `Bearer …`; leaves a plate literal and `org_id = 469` intact.
- `redactBindings()` caps at 32 items / 200 chars and sets `bindings_truncated`.

**`tests/Feature/Clickhouse/ClickhouseFailureLoggingTest.php`** (no CH server required)
- *Driver guard:* with `config(['database.connections.clarity_clickhouse.driver' => 'clickhouse'])` + `DB::purge('clarity_clickhouse')`, assert `DB::connection('clarity_clickhouse')` is a `LoggingClickhouseConnection` and `->getClient()` is a `LoggingClickhouseHttpClient`. This is the cheap proof that the `booted()` re-extend actually wins, instead of assuming it.
- *curl-error branch:* point the connection at `192.0.2.1` (TEST-NET-1, RFC 5737) with `timeout_connect => 1`, `timeout_query => 1`, `retries => 0`, then `DB::purge` — the pattern already proven in `TrackAnalyticsTest.php:97-107`. `Log::spy()`; run a `select` with a recognizable statement; assert one `db-error` error record whose context carries `sql`, `statement = select`, `caller_class`, `connection`, `ch_code = null`, and that the `ClickhouseException` still propagates unchanged.
- *No credentials:* assert the serialized context contains neither the configured username nor password nor host.

**`tests/Feature/Clickhouse/ClickhouseTimeoutLoggingTest.php`** (`use RequiresClickhouse`)
- *Forced server-side timeout, AC-1:* test-local `config([...])` on `clarity_clickhouse` → `host 127.0.0.1`, `port 8123`, `https false`, `timeout_query => 1`, `retries => 0`, then `DB::purge`. Run a statement that ClickHouse aborts on its own deadline rather than one curl kills first — `SELECT count() FROM numbers(100000000000)`. `buildUrl()` sends `max_execution_time=1` while the curl budget is `1 + timeout_connect` (`:408`), so ClickHouse returns **Code 159 … (TIMEOUT_EXCEEDED)** before curl gives up. Assert `ch_code === 159`, `sql` contains `numbers(`, `caller_*` names the test frame, `console === true`, `timeout_query === 1`, `max_execution_time === 1`, `curl_timeout === 1 + timeout_connect`, `server_elapsed_ms` parsed non-null.
- *AC-2 success path:* `Log::spy()`; run a successful `SELECT 1` and a `TRUNCATE TABLE IF EXISTS`; assert **zero** `db-error` records.
- Deliberately table-free (`numbers()`, `SELECT 1`) so the forced-timeout proof has no schema, seed, or `clarity.`-prefix dependency.
- Never point this at ClickHouse Cloud. Local Docker CH only, `127.0.0.1:8123`, per the compose service in `CLAUDE.md`.
- Per execution policy, tests are run only when explicitly requested.

## Before/after sample strategy (AC-4)

Both lines come from the **same** forced local timeout, captured at Implement — not from production, and not from the org-469 query.

- **Before:** with the stock client installed on the connection (the existing `ReflectionProperty($connection, 'client')` swap used at `KitUserPathControllerTest.php:224-226` makes this a one-liner), capture what a swallowing surface writes today — the thin `Query Exception's Exception` shape from `HasRetryQuery.php:100-107`: `message`, `code: 0`, `file: …/ClickhouseHttpClient.php`, `line: 438`, plus `extra.user` from `DataDogHandler`. No `sql`, no caller, no route, no timeout fields.
- **After:** the `ClickHouse HTTP exception` record from the logging client on the identical failure.
- PR text states plainly that the pair is a locally forced timeout, that the `ch_code` is the same 159 `TIMEOUT_EXCEEDED` class as the org-469 incident, and that the org-469 query itself was not run or investigated.
- The prep sketch in `prep-context.json → before_after_sample` is a reconstruction and is replaced, not quoted as evidence.

## Success-path guarantee (AC-2)

Added work on a successful query: **one `hrtime(true)` read** before the `parent::` call. The delta is computed only inside `catch`. No log call, no config lookup, no backtrace, no second request, no settings write, no change to the SQL sent. The config array is copied once per connection instance in the constructor, not per query. Nothing is appended to or removed from the statement — `select()` still receives exactly what `Connection::select()` passes (`Connection.php:55-58`).

## Provision / QA notes (#18)

- Forced timeout must be proven on **real Docker ClickHouse**, not the pgsql mirror. The pgsql bridge never reaches `ClickhouseHttpClient` at all, so it cannot produce a `ClickhouseException` and cannot validate this change.
- Requires **its own** fleet CH twin when Galadriel stands one. Do **not** borrow, reconfigure, or repoint the DEV-2904 Path A twin.
- ClickHouse **Cloud is read-only and untouched** in every phase of this ticket. No forced-timeout test may be aimed at it.
- Local run: `127.0.0.1:8123`, literal `clarity` / `zod` database names (per the committed compose service), `CLICKHOUSE_DRIVER=clickhouse` and the host/port overrides in `.env.testing` only — **never** edit global `.env` defaults, `config/database.php` defaults, or the dashboard constants.
- `RequiresClickhouse` truncates ~24 `clarity.*` / `zod.*` tables on setup (`RequiresClickhouse.php:16-55`). Any CH-backed QA seed must be re-applied after a run of this suite.
- The forced-timeout test needs no seeded rows, so it can run on a bare CH container immediately after schema load.

## Hard stops / out of scope / collisions

**Hard stops (from the ticket):** no timeout-budget changes; no product-query rewrites; no `LprRepossessions` / `HeldFlexibleCache` retuning (`LprRepossessions.php:99` `OBSERVED_MAX_LATENCY_SECONDS = 33.7`, `:111` `QUERY_TIMEOUT_SECONDS = 60`, `:151` `COLD_LOCK_SECONDS`, `:159` `REFRESH_LOCK_SECONDS` — read-only here); no `insight-lpr/laravel-clickhouse` PR; ClickHouse Cloud read-only; not a fix for the org-469 / Reliable Recovery slow query; Soft #27 N/A (no UI).

**Out of scope:** Datadog/RUM instrumentation; removing email from `DataDogHandler`; touching `bootstrap/app.php`'s `QueryException` report hook (`:33-43`); editing `Org.php`, `HasRetryQuery`, `HasHitMetricCards`, or `ClickhouseQuery` — the enricher deliberately covers them from below; enabling ClickHouse Cloud MCP.

**Collisions:** DEV-2904 Path A CH twin (parallel; untouched). The held org-469 watch (do not investigate that query here). The dashboard timeout/lock constants (read, never written).

**okf/:** no wiki edits while in flight. After approval, a one-line note on `okf/concepts/clickhouse-integration.md` stating that CH HTTP failures log truncated SQL and caller context on the `db-error` channel, followed by `php scripts/okf-lint.php`.

**Package escalation:** confirmed **not required**. The only trigger would be making `query_id` mandatory, which needs `X-ClickHouse-Query-Id` captured inside the private `request()` — a `laravel-clickhouse` edit requiring an explicit escalate before Implement. This plan omits `query_id` and therefore stays platform-only.

**Standards:** PHP 8.3-compatible (PG-021). `okf/concepts/laravel-boost-guidelines.md` is required reading before the first line of code. `composer format` (Pint) before finishing.

## Open questions

None. Prep's Question Gate is CLEAN and nothing in this plan introduces a new one.
