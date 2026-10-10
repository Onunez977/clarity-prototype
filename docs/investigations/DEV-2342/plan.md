# DEV-2342 — Plan (single-arbiter)

**App:** clarity · **Route:** bug · **Scope:** W2 (server-side match) + W3 (concurrency ceiling) + AC6/AC7/AC9 tests
**Out of scope:** W1 (chlocal sc_positions projection), W4 (schedule re-enable + prod host verify), AC2/AC3/AC5/AC8 (measurement/operational, W1/W4-gated).
**UAT needed:** no (no UI surface; the Scout check-in UI stays hidden). **perf_validation:** not-required (see WS6 below).

The command stays DISABLED (routes/console.php schedule remains commented; `app.experimental.clarity_scout` off). This PR ships the code + tests and the EXPLAIN evidence; prod-scale duration/memory is proven at the W4 supervised re-enable.

## Ordered subtasks

| id | title | complexity | depends_on | target_files |
|----|-------|-----------|-----------|--------------|
| t1 | EXPLAIN-first: pick W2 query shape + batching granularity (read-only Cloud) | complex | — | (none — writes derivation_log) |
| t2 | Rewrite ScoutCheckInMatchQuery as one server-side CTE + AC9 parity test | complex | t1 | ScoutCheckInMatchQuery.php, ScoutCheckInMatchQueryParityTest.php (new) |
| t3 | Rewrite recorder: bulk insert + W3 semaphore + AC7 ::handle() bypass; delete dead query; config/route hygiene | complex | t1, t2 | ScoutRecordCheckIns.php, ScoutOpenOrderAddressesQuery.php (delete), config/scout.php, routes/console.php |
| t4 | Update command test to new signature + AC7 prod-env failure-signal + W3 ceiling case | complex | t3 | ScoutRecordCheckInsCommandTest.php |
| t5 | Add AC6 peak-RSS regression test at prod-representative cardinality | simple | t3 | ScoutRecordCheckInsMemoryTest.php (new) |

**DAG:** t1 → t2 → t3 → {t4, t5}. t4 and t5 run in parallel (disjoint files, both depend only on t3). t1 has no app-code target and gates the two W2 code nodes (t2, t3).

## W3 mechanism decision (deferred to the plan, Q3): N-slot `Cache::lock` semaphore at command start

Chosen over reviving the per-org queued job (`App\Jobs\Scout\RecordOrgCheckIns`, caab5a469 / reverted 102a3430).

**Why:**
- **Simplest mechanism that satisfies AC4.** A fixed pool of N `Cache::lock` slots acquired at `handle()` start gives a hard ceiling of N concurrent processes that holds *independent of runtime* — the exact property `withoutOverlapping(10)`'s expiry-not-cap semantics lacked (it let concurrency climb to ~17). Single-file change; no new infra.
- **The prune-preferring W2 shape doesn't map onto per-org jobs.** evidence-38238 says prefer the granule prune over query count, which points at per-**kit** equality (prunes the `(kit_mapping, timestamp_utc)` prefix cleanly) rather than per-org `IN` (optimizer may drop the prune). Per-kit batching does not map onto per-org jobs, so the jobs route's only real advantage (per-org parallelism) evaporates. t1's EXPLAIN confirms the shape; if it lands on per-org `IN`, the semaphore still applies unchanged.
- **No Horizon/supervisor coupling.** The job prototype was reverted because `{low}`-supervisor `maxProcesses` defaults to 1 and serialized throughput ~7x — a coupling to operational config the semaphore avoids entirely. (W2 changes that throughput math, but the coupling risk remains a reason to prefer the in-command mechanism.)
- **DEV-2348 coupling preserved.** Slot lease TTL is set ≤ `lookback_minutes` (today 10 < 15) so a crashed slot's auto-expiry never opens a gap wider than the overlapping lookback window — zero check-in loss. Slots are also released in a `finally` so normal completion frees them immediately.
- **Not influenced by the discredited "concurrency is load-bearing" reasoning.** This ADDS a ceiling; it does not rely on concurrency for throughput. The stale routes/console.php:66 comment and the okf Performance section are treated as falsified, not as constraints.

New tunables land in `config/scout.php` under its documented no-fallback discipline (config is the single source of truth; call sites read without a fallback).

## AC coverage map

- **AC1** (server-side match, no address list crosses PHP, no re-serialization) → t2 (CTE) + t3 (recorder drops enumeration/array-threading).
- **AC4** (hard concurrency ceiling, lookback invariant) → t3 (N-slot semaphore, lease ≤ lookback) + t4 (assertion).
- **AC6** (peak-RSS at prod-representative cardinality) → t5.
- **AC7** (failed vs empty distinguishable) → t3 (::handle() bypass at call sites; shared swallow untouched) + t4 (production-env test).
- **AC9** (matcher ↔ ScoutCheckInRollupQuery parity) → t2 (parity test; rollup untouched).
- **AC2/AC3** → measurement-gated: proven by t1's EXPLAIN plan + t5's local peak-RSS + the structural O(matches) argument; full-cycle wall-clock / host-fit deferred to W4.
- **AC5/AC8** → deferred (W1 chlocal projection / W4 prod host observation) — out of this repo's reach.

## Excluded / deliberately-not-covered sites

- **app/Clickhouse/Queries/ClickhouseQuery.php:29** — the Throwable→[] swallow is NOT modified (Q6). AC7 is solved at the recorder call sites via `::handle()`; ~20 other query classes rely on the swallow, and a prior guard behind `::query()` was proven dead (2cd844090).
- **okf/concepts/scout-check-in-recorder.md** — the discredited "load-bearing concurrency" Performance section is corrected POST-APPROVAL per CLAUDE.md's WRITE rule, not mid-flight.
- **tests/Feature/Console/ScoutRecordCheckInsScheduleTest.php** — pre-existing RED (schedule commented since 11bc23e2). Accept red until W4 (Q7); confirm red-on-main in Phase 4.
- **config/scout.php:25 (overrun_ms)** — ratchet is measurement-gated (AC2/Q5); the new value depends on a prod wall-clock this pipeline cannot take. Left unchanged.

## Sizing gate

8 distinct files (5 app/config/route: 1 CH-query rewrite, 1 command, 1 delete, 1 config, 1 route-comment; 3 tests: 2 new + 1 edit) plus a derivation_log artifact. ~5 concerns: server-side CH match (W2), concurrency ceiling (W3), call-site failure signal (AC7), memory regression (AC6), parity (AC9), with config/route hygiene folded in.

**Verdict: ship as ONE ticket.** The ticket is explicitly framed as the single source of truth for fixing this command (folds in DEV-2347/2348/2349). The concerns are tightly coupled — W3's shape depends on W2's EXPLAIN result, and every test depends on W2/W3 — so a split would create serial cross-PR dependencies with no isolation benefit. No genuine split reason found.

## WS6 CH-at-scale perf gate

Driving CH tables: sc_positions ~268M, public_hotlist_address ~318M, public_address ~196M — all **< 1B rows**, so the >1B deferred-perf gate does **not** trigger. `perf_validation` stays **not-required**. (The read-only Cloud EXPLAIN in t1 is a plan-shape decision aid per evidence-38238, not a gate obligation.)

## Blast radius (report-only; driver decides)

Every code node carries blast_radius from the ticket's sensitive set — this whole ticket is the fix for a production host OOM/reboot:
- **t2** — `cross-db-ch-rewrite` (rewrites a zod.* CDC + sc_positions join; a dropped `id IN` prune or bbox prefilter reproduces the DEV-2339 full-scan-timeout class).
- **t3** — `scheduled-command`, `cron-concurrency` (a wrong ceiling either reproduces the OOM or, too low + expiry/lookback coupling, silently drops check-ins per DEV-2348), `no-fallback-config`.

Not a hard-stop request — reported for the driver.
