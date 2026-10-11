# DEV-2923 Ultra-Plan — Opus reconcile (read-only)

You are a **read-only reconciler** for ticket DEV-2923. Your stdout becomes `docs/investigations/DEV-2923/claude-plan-reconcile.md`.

## Role and tools
- Tools allowed: **Read, Grep, Glob only.** No Bash, Edit, Write, product PHP, git, DB/CH/Herd/fleet mutations.
- Re-read both inputs below and cite live code when deciding adopt/reject.
- Do **not** write `plan.json` / `plan.md` (parent seals those after this run).
- Prefer CLEAN gate. Leave Open questions empty unless a real new Question Gate appears (do not invent one).
- Hard stops remain: logging only; no timeout budget / product query / dashboard constant changes; Cloud CH READ-ONLY; platform-only (no laravel-clickhouse PR); do not steal DEV-2904 Path A twin; Soft #27 N/A.

## Inputs
1. `docs/investigations/DEV-2923/claude-plan-draft.md` — Opus primary Ultra-Plan draft
2. `docs/investigations/DEV-2923/cloud-plan-review.md` — CloudAgent review (verdict **REQUEST CHANGES**, agent `bc-9dd40489`)
3. Supporting: `prep-context.json`, `precedents.json`, `state.json`, `claude-plan-prompt.md`

Also re-cite as needed: `ClickhouseHttpClient.php` (`request` CURLOPT_TIMEOUT, `buildUrl` settings, `select`/`write`/`insert`), `config/database.php` clarity_clickhouse defaults, `RequiresClickhouse`, existing spies.

## Mandatory adopt decisions

### Change 1 — ADOPT (rewrite AC-1 forced-timeout recipe)
Replace the draft's forced-timeout recipe so the **server** throws ClickHouse code **159** before curl expires. Test-local on `clarity_clickhouse` against Docker CH (`127.0.0.1:8123`). Do **not** write these into `config/database.php`, `.env`, `.env.testing` defaults, or dashboard constants.

Locked recipe:
- Connection override: `timeout_query=2`, `timeout_connect=5`, `retries=0` (curl budget 7s)
- Test-only settings on the purged connection's `settings` array: `timeout_before_checking_execution_speed=0`, `max_block_size=1`
- Statement: `SELECT * FROM numbers(100000000)` (no FORMAT — `select()` appends ` FORMAT JSON`)
- Assert: `ch_code === 159` and `TIMEOUT_EXCEEDED` in the message
- Rationale cite: draft's `timeout_query=1` + default `timeout_connect=2` → curl budget 3s loses to default `timeout_before_checking_execution_speed=10`; `SELECT count() FROM numbers(100000000000)` is the wrong shape

### Nits — ADOPT / fold into the sealed plan (these affect assertions or implementation hygiene)
1. **App\\ caller** — Drive the timeout query from an `App\` invoker (production shape, e.g. thin invoker calling `DB::connection('clarity_clickhouse')->select`); assert that class. Walker keeps first `App\` frame outside `App\Clickhouse\Logging\`. Tests live in `Tests\`, so direct `select()` from the test has no `App\` frame. Keep the `App\` rule.
2. **write()/statement failure** — Assert one `statement()` / `write()` failure (`statement=write`). Put emit in one private method both `select` and `write` overrides call. `insert()` reaches `$this->write()`.
3. **Non-string bindings** — `redactBindings()` must accept every type `formatValue()` accepts (null, bool, int, float, DateTimeInterface, array, string) without throwing. Add array-binding unit case.
4. **server_elapsed_ms best-effort** — Pass on `ch_code === 159` + `TIMEOUT_EXCEEDED`. Parse ms when message has `elapsed <number> ms`; seconds-formatted → null still passes.
5. **Success-path write SQL** — Use SQL ClickHouse accepts. `SELECT 1` via `statement()` is a successful write. Do **not** use `TRUNCATE TABLE IF EXISTS` with no table (throws → logged → fails zero-record assert). Prefer success-path feature test in a class that does **not** use `RequiresClickhouse` (spy/Log::fake on mocked client), or use a known-good write that won't collide with RequiresClickhouse setUp truncates.
6. **Typed timeout props** — Copy `timeout_query`, `timeout_connect`, `retries`, and `settings['max_execution_time']` into typed properties (do not keep raw config array that also holds username/password/host).
7. **Relativize caller_file** — Relativize to repo root; `debug_backtrace` paths are absolute.
8. **bindings_redacted key** — Keep key name `bindings_redacted` (not a rename that breaks `scripts/extract_sql_queries.py` which substitutes `context.bindings` when `context.sql` present).
9. **Empty insert unenriched** — Empty `insert()` throws `Cannot insert empty values` before `write()` — leave that throw unenriched. HTTP failures still hit `write()` override.
10. **Audit-script note** — Record that `scripts/audit-card-query-sql.php` reflection swap of `client` still works when the connection instance is `LoggingClickhouseConnection`. No script edit.

Reject a nit **only** with a live cite that proves it conflicts with hard stops or with prep's CLEAN platform-only path. Prefer adopt.

## Required output structure
Markdown with:
1. **Summary** — one short paragraph; overall reconcile stance (expect: APPROVE-with-adopted-changes)
2. **CloudAgent findings — adopt / reject** — for Change 1 and each Nit: **adopt** or **reject** + one-paragraph **why**
3. **Sealed plan deltas** — exact text that must land in `plan.md` / `plan.json` for the forced-timeout recipe, test list, and logging hygiene
4. **Hard stops / out of scope** — reaffirm
5. **Open questions** — empty unless real QG
6. **Verdict line** — `APPROVE-with-adopted-changes` (or REQUEST CHANGES if you must reject Change 1 — do not)

Do **not** implement code. Stdout only.
