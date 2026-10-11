# DEV-2923 — Ultra-Plan reconcile (Opus, read-only)

> Read-only. No code, no `plan.json` / `plan.md` written. Inputs re-read: `claude-plan-draft.md` (Opus primary), `cloud-plan-review.md` (CloudAgent `bc-9dd40489`, **REQUEST CHANGES**). All cites below re-verified against the live tree in this worktree.

## 1. Summary

The draft's architecture survives review intact: a `LoggingClickhouseHttpClient` + `LoggingClickhouseConnection` pair installed by a `booted()` re-extend, logging one `db-error` record and rethrowing the same `ClickhouseException` instance. Every structural claim I re-checked holds against live code — private parent properties (`ClickhouseHttpClient.php:13-20`), `insert()` → `$this->write()` (`:93`), `insertAssocBulk()` → `insert()` (`:121`), `select()` appending `' FORMAT JSON'` (`:44`), `createWithClient()` hard-assigning a stock client (`Connection.php:26-27`), and provider order (`bootstrap/providers.php:16` vs `:24`) with the existing `booted()` precedent (`AppServiceProvider.php:113-118`). CloudAgent's single blocking change is about the **AC-1 proof recipe**, not the design, and it is correct to take: the draft's 3-second curl budget is too tight to reliably observe a server-side 159. I **adopt Change 1 and all ten nits**, reject none, and add three deltas the review did not reach: the `App\`-caller defect also hits the draft's *curl-error* test (not just the timeout test), `App\Jobs\TrackAnalytics` is an already-proven production-shaped invoker that discharges nits 1 and 2 together with no CH server and no new app-namespace scaffolding, and `setupRequiresClickhouse()` is called **per test method**, not from a shared `setUp()` — which changes nit 5's reasoning without changing its conclusion.

## 2. CloudAgent findings — adopt / reject

### Change 1 — forced-timeout recipe → **ADOPT**

Adopt the locked recipe exactly as specified in the reconcile prompt. Verified from live code: `CURLOPT_TIMEOUT => $this->queryTimeout + $this->connectTimeout` (`ClickhouseHttpClient.php:408`), and `clarity_clickhouse` supplies `timeout_connect => 2` (`config/database.php:171`), so the draft's `timeout_query => 1` yields a **3-second** curl budget. The draft's own assertion list then demands `ch_code === 159` — but a curl expiry produces `"cURL error: {$curlError}"` (`:421-423`), which `parseClickhouseError()` never sees and which cannot match `/ClickHouse error (\d+)/`, so `ch_code` lands `null` and the test fails on its headline assertion. The review's shape (`timeout_query=2`, `timeout_connect=5`, `retries=0` → 7-second budget, plus `timeout_before_checking_execution_speed=0` and `max_block_size=1`, running `SELECT * FROM numbers(100000000)`) leaves the server five seconds of headroom over its own 2-second deadline and forces small blocks so the limit check fires early.

One honesty note to carry into the plan: I can verify the curl arithmetic and the `buildUrl()` settings-overwrite path (`:374-386`) from this repo, but **not** the review's claim about `timeout_before_checking_execution_speed` gating the `max_execution_time` wall clock, nor the cited upstream file `02896_max_execution_time_with_break_overflow_mode.sql` — neither the ClickHouse server source nor that test is in this tree. The recipe is adopted because it is robust under *both* readings of the server mechanism (7s budget ≫ 2s deadline regardless of whether the gate exists), not because the mechanism was independently confirmed. The draft's recipe is fragile under one reading; the review's is safe under both. Also confirmed: `clarity_clickhouse.settings` does **not** carry `max_execution_time` (`config/database.php:175-178`), unlike `public_clickhouse` (`:228-229`), so `buildUrl()`'s `max_execution_time => $this->queryTimeout` is what ships on this connection — the review's distinction is right.

### Nit 1 — `App\` caller invoker → **ADOPT, with wider scope and a better placement**

Adopt, and keep the `App\` rule. The review is right that `ClickhouseTimeoutLoggingTest` lives in `Tests\`, so a direct `select()` from the test body puts **no** `App\` frame on the stack and the draft's `caller_*` assertion cannot pass. Two additions the review did not reach:

- **The same defect is in the draft's curl-error test**, not only the timeout test. `ClickhouseFailureLoggingTest` likewise asserts `caller_class` from a direct test-frame `select` (draft line 136). Both assertions need an `App\` invoker.
- **`App\Jobs\TrackAnalytics` already is that invoker, and it is strictly better than a new one.** `TrackAnalyticsTest.php:88-114` already proves a `ClickhouseException` propagates out of `TrackAnalytics::handle()` when the write connection is pointed at TEST-NET-1 (`192.0.2.1`) with `timeout_connect/timeout_query => 1`, `retries => 0`, then `DB::purge`. `handle()` calls `Analytics::insertAssoc()` (`TrackAnalytics.php:53`), which reaches `$this->write()` through `insertAssocBulk()` → `insert()` → `write()` (`ClickhouseHttpClient.php:121`, `:93`). That single test therefore yields an `App\` caller **and** `statement = write` **and** a non-`clarity_clickhouse` value for the `connection` field — with **no ClickHouse server required**, which matters because it lands the `App\`-caller proof in a class that is *not* skipped when `CLICKHOUSE_DRIVER=pgsql`. Putting that assertion only in the `RequiresClickhouse`-gated timeout class would reproduce precisely the trap the draft's own three-class split was designed to avoid.

Derived requirement this exposes, which the plan must state: the forced-159 statement is a table-free `numbers()` select with genuinely no `App\` frame, and production has vendor-only stacks too. **The walker must have a documented no-`App\`-frame fallback** — `caller_class`/`caller_function`/`caller_file`/`caller_line` all `null`, no throw — and the timeout test asserts that fallback rather than an `App\` class. A walker that assumes a match will either error inside the log `try` (swallowed → no line at all) or emit garbage.

### Nit 2 — assert a `write()` failure, shared private emit → **ADOPT**

Confirmed `insert()` returns `$this->write($sql)` (`ClickhouseHttpClient.php:93`) and `insertAssocBulk()` returns `$this->insert(...)` (`:121`), so one `write()` override covers all insert paths — the draft is right about that, and the review is right that an unasserted override drifts. Discharged by the `TrackAnalytics` test above at zero extra cost. The shared private emit method is plain hygiene; adopt.

### Nit 3 — `redactBindings()` accepts every `formatValue()` type → **ADOPT**

Verified `formatValue()` handles null, bool, int, float, `DateTimeInterface`, array (recursive), `__toString` objects, and string, and throws `ClickhouseException` on anything else (`ClickhouseHttpClient.php:277-308`). The review's consequence is the sharp part: a throw inside the emit's own `try { … } catch (\Throwable) { }` — which the draft correctly specifies at line 27 — is swallowed, so a redaction bug silently produces **no** `db-error` line at all, which is the exact failure this ticket exists to prevent. Add the array-binding unit case; also cover `null`, `DateTimeInterface`, and the `__toString` object, since those are the other non-scalar shapes `formatValue()` admits.

### Nit 4 — `server_elapsed_ms` best-effort → **ADOPT**

The field is parsed out of a server message whose formatting is not under our control, and `parseClickhouseError()` only echoes the response body (`:444-456`). Gating a test on it makes AC-1 hostage to ClickHouse's message wording across versions. Pass on `ch_code === 159` + `TIMEOUT_EXCEEDED`; parse ms only when the message matches `elapsed <number> ms`; a seconds-formatted message stays `null` and still passes.

### Nit 5 — success-path write SQL → **ADOPT, with a corrected rationale**

Adopt the conclusion: put the AC-2 success-path test in a class that does **not** use `RequiresClickhouse`, and never use `TRUNCATE TABLE IF EXISTS` with no table name (it throws → is logged → breaks the zero-record assert). `Connection::statement()` → `client->write()` (`Connection.php:61-63`), so `SELECT 1` through `statement()` is a legitimate successful write.

**Correction to the review's reasoning, which strengthens the same conclusion:** `setupRequiresClickhouse()` is *not* invoked from a shared `setUp()`. A repo-wide grep shows it called explicitly at the top of each individual test method (`StagingLiveHitLeaderQueryTest.php:39,62,84,105,137,160`; `CacheOrgHotlistLienholdersTest.php:37,52,74,90,117`; and so on). So "the truncates already ran in `setUp`, before `Log::spy()`" is not guaranteed — the ordering is whatever the test body chooses. And those ~24 statements are real `write()` calls (`RequiresClickhouse.php:52-54`): `TRUNCATE TABLE IF EXISTS clarity.x` tolerates a missing *table* but not a missing *database*, so on a container whose schema load was partial, the trait itself would emit `db-error` records and sink a zero-record assert. Keeping AC-2 out of a `RequiresClickhouse` class removes the coupling entirely.

### Nit 6 — typed timeout properties → **ADOPT**

The config array handed to the constructor carries `username`, `password`, and `host` alongside the timeout numbers (`config/database.php:160-187`). Holding the whole array on a class whose job is to write a log line is a standing footgun; copying the four values into typed `int` / `?int` properties makes the credentials structurally unreachable from the emit path rather than merely unreferenced. Also satisfies the boost guidelines' explicit-type rule.

### Nit 7 — relativize `caller_file` → **ADOPT**

`debug_backtrace()` returns absolute paths, which leak the deploy/worktree layout into Datadog and make lines non-comparable across environments. Relativize to repo root. Trivial, and it belongs in the same helper as the walker.

### Nit 8 — keep the key `bindings_redacted` → **ADOPT**

Verified the mechanism at `scripts/extract_sql_queries.py:48-51`: the extractor keeps a line when `context.sql` is present, reads `context.get('bindings', [])`, and `substitute_bindings()` interpolates them into the SQL. Renaming our key to `bindings` would make the extractor splice **redacted / truncated** values into what reads like replayable SQL — actively misleading. Under `bindings_redacted`, the extractor finds no bindings, emits the statement with `?` placeholders intact, and the redacted list stays visible in the raw record. Keep the name.

### Nit 9 — empty `insert()` throw stays unenriched → **ADOPT**

`insert()` and `insertAssocBulk()` both throw `Cannot insert empty values` before any HTTP work (`ClickhouseHttpClient.php:74-76`, `:104-106`), above the `write()` override. There is no SQL, no caller-relevant failure, and nothing for a `db-error` line to name; it is a programming error, not a database incident. Record the exclusion so a future reader does not log it as a gap.

### Nit 10 — audit-script reflection still works → **ADOPT (record only, no edit)**

Verified: `scripts/audit-card-query-sql.php:74-79` builds `new ReflectionProperty(Connection::class, 'client')` against the **declaring** class `LaravelClickhouseEloquent\Connection` (`Connection.php:13`, `protected ClickhouseHttpClient $client`) and calls `setValue()` on the instance returned by `DB::connection('clarity_clickhouse')`. A `ReflectionProperty` on the declaring class sets fine on a subclass instance, so `LoggingClickhouseConnection` changes nothing. The script swaps in `CardQuerySqlRecorder extends ClickhouseHttpClient` (the stock parent), which bypasses the logging wrapper — intentional, and identical to how the three existing spies behave (`CloseInactiveShiftsCommandTest.php:403-425`). No script edit; record as verified.

**Rejections: none.** No nit conflicts with a hard stop or with prep's CLEAN platform-only path.

## 3. Sealed plan deltas

Exact text to land in `plan.md` / `plan.json`, replacing the corresponding draft sections.

### 3a. Forced-timeout recipe (replaces draft §Tests, `ClickhouseTimeoutLoggingTest` first bullet)

> **Forced server-side timeout (AC-1)** — `tests/Feature/Clickhouse/ClickhouseTimeoutLoggingTest.php`, `use RequiresClickhouse`.
>
> Test-local `config([...])` on `clarity_clickhouse`, then `DB::purge('clarity_clickhouse')`:
> `driver => 'clickhouse'`, `host => '127.0.0.1'`, `port => '8123'`, `https => false`, `options => ['protocol' => 'http']`, `timeout_query => 2`, `timeout_connect => 5`, `retries => 0`.
> Curl budget is `timeout_query + timeout_connect = 7`s (`ClickhouseHttpClient.php:408`), five seconds clear of the server's own 2-second deadline.
>
> Settings — **merge into**, do not replace, the existing `clarity_clickhouse.settings` (which carries `max_bytes_before_external_group_by` and `validate_polygons => 0`, `config/database.php:175-178`): add `timeout_before_checking_execution_speed => 0` and `max_block_size => 1`. `buildUrl()` copies `settings` onto the query string after `max_execution_time` (`ClickhouseHttpClient.php:376-386`), so these reach the server. `clarity_clickhouse` does **not** override `max_execution_time` in settings (unlike `public_clickhouse`, `config/database.php:228-229`), so the effective server deadline is `timeout_query` = 2.
>
> Statement: `SELECT * FROM numbers(100000000)` — **no `FORMAT` clause**; `select()` appends `' FORMAT JSON'` itself (`ClickhouseHttpClient.php:44`). Table-free, so no schema, seed, or `clarity.`-prefix dependency.
>
> Assert: `ch_code === 159`; `TIMEOUT_EXCEEDED` present in `exception_message`; `sql` contains `numbers(`; `statement === 'select'`; `console === true`; `connection === 'clarity_clickhouse'`; `timeout_query === 2`; `timeout_connect === 5`; `curl_timeout === 7`; `max_execution_time === 2`. `server_elapsed_ms` is **best-effort** — parsed only when the message matches `elapsed <number> ms`, `null` otherwise, and `null` still passes. Caller fields assert the **no-`App\`-frame fallback** (all four `null`) — see §3c.
>
> These settings and timeouts are **test-local only**. They are not written to `config/database.php`, `.env`, `.env.testing` defaults, or any dashboard constant. Never aimed at ClickHouse Cloud; local Docker CH on `127.0.0.1:8123` only.
>
> *Rationale for replacing the earlier recipe:* `timeout_query => 1` with `clarity_clickhouse`'s default `timeout_connect => 2` (`config/database.php:171`) gives a 3-second curl budget. A curl expiry produces `"cURL error: …"` (`ClickhouseHttpClient.php:421-423`), which never reaches `parseClickhouseError()` and cannot match `/ClickHouse error (\d+)/`, so `ch_code` would be `null` and the headline assertion would fail. `SELECT count() FROM numbers(100000000000)` is additionally the wrong shape — default block sizing makes limit checks infrequent. (The upstream `timeout_before_checking_execution_speed` gating argument is taken on review's authority: the ClickHouse server source is not in this repo. The 7-second budget makes the recipe correct under either reading.)

### 3b. Test list (replaces draft §Tests, feature-test bullets)

> **`tests/Feature/Clickhouse/ClickhouseFailureLoggingTest.php`** — no `RequiresClickhouse`; always runs, including under `CLICKHOUSE_DRIVER=pgsql`.
>
> 1. *Driver guard.* `config(['database.connections.clarity_clickhouse.driver' => 'clickhouse'])` + `DB::purge`; assert the resolved connection is a `LoggingClickhouseConnection` and `->getClient()` a `LoggingClickhouseHttpClient`. Proves the `booted()` re-extend wins rather than assuming it.
> 2. *curl-error branch, `select`, `App\` caller.* Point `clarity_clickhouse` at `192.0.2.1` (TEST-NET-1, RFC 5737) with `timeout_connect => 1`, `timeout_query => 1`, `retries => 0`, `https => false`, then `DB::purge` — the pattern proven at `TrackAnalyticsTest.php:97-107`. Drive the select from an `App\` frame. `Log::spy()`; assert exactly one `db-error` record carrying `sql`, `statement === 'select'`, a non-null `caller_class` in the `App\` namespace and outside `App\Clickhouse\Logging\`, `connection`, `ch_code === null`, and that the original `ClickhouseException` still propagates.
> 3. *curl-error branch, `write`, via `App\Jobs\TrackAnalytics`.* Reuse the existing blackhole setup on `clarity_clickhouse_write` (`TrackAnalyticsTest.php:97-107`). `TrackAnalytics::handle()` → `Analytics::insertAssoc()` (`TrackAnalytics.php:53`) → `insertAssocBulk()` → `insert()` → `write()` (`ClickhouseHttpClient.php:121`, `:93`). Assert one record with `statement === 'write'`, `caller_class === App\Jobs\TrackAnalytics::class`, `connection === 'clarity_clickhouse_write'`, and the exception still propagating. This single test discharges the `App\`-caller rule **and** the `write()`-override coverage, with no ClickHouse server and in a class that is never skipped.
> 4. *AC-2 success path.* Lives here, **not** in the `RequiresClickhouse` class. `Log::spy()` over a successful `select()` and a successful `statement()` (`SELECT 1` — `Connection::statement()` → `client->write()`, `Connection.php:61-63`); assert **zero** `db-error` records. Do not use `TRUNCATE TABLE IF EXISTS` with no table name: it throws, gets logged, and breaks the assert.
> 5. *No credentials.* Assert the serialized context contains neither the configured username, password, nor host.
>
> **`tests/Feature/Clickhouse/ClickhouseTimeoutLoggingTest.php`** — `use RequiresClickhouse`; the forced-159 recipe in §3a, and nothing else. Note that `setupRequiresClickhouse()` is invoked **per test method**, not from a shared `setUp()` (see every call site, e.g. `StagingLiveHitLeaderQueryTest.php:39,62,84,105`), and that its ~24 `TRUNCATE TABLE IF EXISTS` calls are real `write()`s (`RequiresClickhouse.php:52-54`) which would be logged if the CH schema were partially loaded. Call it before any `Log::spy()`.
>
> **`tests/Unit/Clickhouse/ClickhouseLogRedactionTest.php`** — unchanged from the draft, plus: `redactBindings()` must accept every type `formatValue()` admits without throwing — `null`, `bool`, `int`, `float`, `DateTimeInterface`, **array** (recursive), `__toString` object, `string` (`ClickhouseHttpClient.php:277-308`). A throw here is swallowed by the emit's own `catch (\Throwable)` and the `db-error` line never lands at all.
>
> Per execution policy, tests run only when explicitly requested.

### 3c. Logging hygiene (amends draft §Context fields and §Logging design)

> - **Caller walker.** First `App\` frame outside `App\Clickhouse\Logging\`. When no such frame exists — a vendor- or framework-only stack, which the forced-159 `numbers()` test deliberately produces — emit `caller_class`, `caller_function`, `caller_file`, `caller_line` as `null`. The walker must never throw; a throw inside the emit `try` means no record at all.
> - **`caller_file` is relativized to the repo root.** `debug_backtrace()` paths are absolute and would leak deploy layout into Datadog while making records non-comparable across environments.
> - **Typed properties, not the raw config array.** Copy `timeout_query`, `timeout_connect`, `retries`, and `settings['max_execution_time']` into typed properties in the constructor. The config array also holds `username`, `password`, and `host` (`config/database.php:160-187`); do not retain it on the instance.
> - **Bindings key stays `bindings_redacted`.** `scripts/extract_sql_queries.py:48-51` keeps any line with `context.sql` and substitutes `context.bindings` into the SQL. Naming ours `bindings` would splice redacted/truncated values into apparently replayable SQL. Under the current name the extractor emits the statement with `?` placeholders intact.
> - **One private emit method** called by both the `select()` and `write()` overrides. Keeps the two paths from drifting and keeps the swallow-and-rethrow in one place.
> - **`server_elapsed_ms` is best-effort** — parsed only from an `elapsed <number> ms` match, `null` otherwise. Never an assertion gate.
> - **Empty-insert throw stays unenriched.** `insert()` / `insertAssocBulk()` throw `Cannot insert empty values` above the override (`ClickhouseHttpClient.php:74-76`, `:104-106`). Programming error, no SQL, nothing to name. HTTP failures still reach `write()`.
> - **`scripts/audit-card-query-sql.php` — verified, no edit.** Its `ReflectionProperty(Connection::class, 'client')` targets the declaring parent class (`Connection.php:13`) and sets fine on a `LoggingClickhouseConnection` instance (`:74-79`). It installs a stock-parent recorder and so bypasses the wrapper, exactly as the three existing spies do (`CloseInactiveShiftsCommandTest.php:403-425`).
> - Unchanged from the draft and re-confirmed: truncation **4096, head-biased**, suffix `…[truncated N chars]`, always with `sql_original_length`; the `db-error` daily channel at `storage/logs/db_error.log` tapped by `DataDogHandler` (`config/logging.php:69-76`); message `ClickHouse HTTP exception`; context key `sql` load-bearing; rethrow of the **same** `ClickhouseException` instance; `query_id` omitted; the emit wrapped in its own `catch (\Throwable)`.

## 4. Hard stops / out of scope — reaffirmed

Unchanged from the draft §Hard stops, and nothing adopted here encroaches on them.

- **Logging only.** No timeout-budget changes; no product-query rewrites; `LprRepossessions` constants read-only (`:99` `OBSERVED_MAX_LATENCY_SECONDS = 33.7`, `:111` `QUERY_TIMEOUT_SECONDS = 60`, `:151`, `:159`). The timeout numbers in the log line are **copies**.
- **No `config/database.php`, `.env`, `.env.testing`, or dashboard-constant writes.** The §3a recipe is test-local `config()` + `DB::purge` only.
- **ClickHouse Cloud is READ-ONLY and untouched** in every phase. No forced-timeout test may be aimed at it. Local Docker CH, `127.0.0.1:8123`, literal `clarity` / `zod` database names.
- **Platform-only.** No `insight-lpr/laravel-clickhouse` PR. The sole trigger would be making `query_id` mandatory (`X-ClickHouse-Query-Id` is discarded inside the private `request()`, `ClickhouseHttpClient.php:416-419`); this plan omits it.
- **Do not borrow, reconfigure, or repoint the DEV-2904 Path A CH twin.** This ticket gets its own when Galadriel stands one.
- **Not a fix for the org-469 / Reliable Recovery slow query.** That query is not run or investigated; the AC-4 before/after pair is a locally forced 159 of the same class, stated plainly as such in the PR.
- **Soft #27 N/A** — no UI surface.
- **Out of scope:** Datadog/RUM instrumentation; `DataDogHandler` email removal; `bootstrap/app.php`'s `QueryException` hook; edits to `Org.php`, `HasRetryQuery`, `HasHitMetricCards`, `ClickhouseQuery` — the enricher covers them from below; enabling ClickHouse Cloud MCP.
- **`okf/` stays queued.** After approval only: one line on `okf/concepts/clickhouse-integration.md`, then `php scripts/okf-lint.php`.
- **Standards:** PHP 8.3-compatible (PG-021); `okf/concepts/laravel-boost-guidelines.md` is required reading before the first line of code; `composer format` before finishing.

## 5. Open questions

None. Prep's Question Gate is CLEAN, and nothing adopted here opens a new one. The one item I could not independently verify — ClickHouse's `timeout_before_checking_execution_speed` gating semantics — is not a Question Gate: the adopted recipe is correct whichever way it resolves, and the uncertainty is recorded in §3a rather than left for a decision.

## 6. Verdict

**APPROVE-with-adopted-changes**
