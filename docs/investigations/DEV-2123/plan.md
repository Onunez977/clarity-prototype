# DEV-2123 — Implementation Plan

**Route:** bug · **Base:** main · **Sizing:** 4 files, single concern → 1 subtask (no split).

## t1 — Write-path CH connect timeout + retries (env-driven, write-specific keys)

**Decision (Dalton, Phase 2):** write-specific env keys; read paths unchanged; retries = 1.

### Changes
1. **config/database.php** — `clarity_clickhouse_write` only:
   - `'timeout_connect' => env('CLICKHOUSE_WRITE_TIMEOUT_CONNECT', 5)`
   - `'retries' => env('CLICKHOUSE_WRITE_RETRIES', 1)`
   - `clarity_clickhouse` and `public_clickhouse` keep `env('CLICKHOUSE_TIMEOUT_CONNECT', 2)` / `env('CLICKHOUSE_RETRIES', 0)`.
2. **.env.example** — document `CLICKHOUSE_WRITE_TIMEOUT_CONNECT=5`, `CLICKHOUSE_WRITE_RETRIES=1`, and the previously-undocumented read-path `CLICKHOUSE_TIMEOUT_CONNECT` / `CLICKHOUSE_RETRIES`.
3. **okf/concepts/clickhouse-integration.md** — update the "intentionally low 2s" line: write path now 5s + 1 retry (DEV-2123) to absorb CH Cloud TLS / idle-scaling connect latency; read paths keep 2s fast-fail.
4. **tests/Feature/Jobs/TrackAnalyticsTest.php** — add a config-resolution test (`timeout_connect >= 5`, `retries >= 1` on the write connection). Existing propagation test untouched.

### Why a config-resolution test (not a timeout test)
A real connect-timeout reduction can't be unit-tested deterministically. The regression we can pin is the **defaults silently resetting** — so we assert the resolved config values. The DEV-2102 propagation test already proves the no-swallow path.

### Invariants preserved
- DEV-2003 dispatch-time UUID + dedup (exactly-once) — untouched.
- DEV-2102 propagate-and-retry (no silent catch) — untouched.
- Read-path fast-fail — untouched.

### Verification
- `php artisan test --filter=TrackAnalyticsTest`
- pint.
