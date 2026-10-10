# Ultra-Plan CloudAgent review — DEV-2923 (READ-ONLY)

You are a READ-ONLY Ultra-Plan reviewer for [DEV-2923](https://insightlpr-team.atlassian.net/browse/DEV-2923) on insight-lpr/platform (PR branch `cursor/dev-2923-ch-timeout-log-context-4838` @ tip `61a9e38f2`, draft PR https://github.com/insight-lpr/platform/pull/502, base `main`).

## Hard fences
- Do NOT commit, push, open/update PRs, or mutate Herd/fleet/env/DB/ClickHouse.
- Do NOT implement product PHP.
- Cloud / production ClickHouse is READ-ONLY (prefer cite-only).
- Never name Fellowship bots on any GitHub text (neutral phase nouns only).
- Do not steal or reconfigure the DEV-2904 Path A twin.
- Logging / observability only — do not change timeout budgets or rewrite product queries.
- Platform-only — do not open an `insight-lpr/laravel-clickhouse` PR.

## Inputs (on branch under docs/investigations/DEV-2923/)
- `claude-plan-draft.md` — **primary Opus High Effort Ultra-Plan draft**
- `claude-plan-prompt.md`, `prep-context.json`, `precedents.json`, `state.json`, `ticket-status.md`, `journal.md`
- Live First Glance comment **43033**: Ready, branch path **main→main**

Re-read cited surfaces before judging:
- `vendor/insight-lpr/laravel-clickhouse-eloquent/src/ClickhouseHttpClient.php` (private `request()` ~L438; public `select`/`write`/`insert`)
- `ClickhouseException`
- `config/logging.php` (`db-error` channel → `storage/logs/db_error.log`)
- Existing thin loggers: `HasRetryQuery`, `Org.php`, `HasHitMetricCards`, `ClickhouseQuery::query()`
- Prep recommendation: platform subclass of `ClickhouseHttpClient` + Connection subclass + re-extend `clickhouse` driver from `AppServiceProvider::booted()`
- Test spies that subclass `ClickhouseHttpClient` (KitUserPath / ClarityScoutBirdsEye / etc.)

## Your only job
Write `docs/investigations/DEV-2923/cloud-plan-review.md` covering:
1. **AC coverage** — forced timeout actionable SQL+caller; success path unchanged; truncation/redaction; org-469-style before/after sample
2. **Repo patterns** — subclass HTTP client vs package edit; provider registration order; spy compatibility
3. **Preflight #24** — smallest change; reject wrong abstractions; no new layers unless prep proves none exist
4. **Logging design** — fields, channel `db-error`, rethrow unchanged, truncation lock (4096 vs 8192), redaction rules, omit `query_id`
5. **Provisionability** — forced timeout on real Docker CH (not PG mirror); own twin separate from DEV-2904; Cloud CH RO; soft #18
6. **Hard stops / risks** — no timeout budget / product query / dashboard constant changes; platform-only; package escalate-only if `query_id` mandatory
7. **Verdict** — APPROVE / APPROVE with minor nits / REQUEST CHANGES — with Changes/Nits listed explicitly

## Done-signal
- Prefer putting the full markdown body in your final message (parent will land `cloud-plan-review.md`).
- If you write the file on the working tree, leave it uncommitted when possible; if commit needed use `[skip ci]` and no product code.
- No Herd/fleet mutations. No commit/push from this review agent if avoidable.
