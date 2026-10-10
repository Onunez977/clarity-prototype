# DEV-2342 — Ultra-Prep Brief (fresh run)

**Bug, Highest priority, In Progress.** `scout:record-check-ins` exhausted production host memory
(2026-08-12 ~21:17 CDT, ~29 GB marginal across 17 concurrent processes on a box with ~30 GB
headroom; host rebooted). The command's schedule is **commented out in production** (commit
`11bc23e2`) and stays disabled until the ACs are met. This is a **fresh restart**: merged PR #323
shipped observability-only and is NOT the fix.

## Read these first (in the repo / investigation dir)

- `app/Console/Commands/ScoutRecordCheckIns.php` — the command being rewritten (the bug).
- `app/Clickhouse/Queries/ScoutCheckInMatchQuery.php` — the current per-kit matcher that takes the
  PHP address array back into CH as `values(...)` literals (the amplifier).
- `app/Clickhouse/Queries/ScoutCheckInRollupQuery.php` — **the correct architecture to lift** (its
  docblock names the DEV-1758 "lift the matches stream" extension point). Read its docblock in full.
- `app/Clickhouse/Queries/ScoutOpenOrderAddressesQuery.php` — the per-org open-address enumeration
  W2 removes from the PHP path.
- `app/Models/ScoutCheckIn.php`, `config/scout.php`, `routes/console.php` (schedule block).
- `okf/concepts/check-in.md` (domain rule: 0.1 mi / 160.934 m, UTC hour bucket, (kit,address,hour)
  identity, Direct-wins tie-break), `okf/concepts/scout-check-in-recorder.md`,
  `okf/concepts/scout-clickhouse-queries.md`, `okf/concepts/platform-gotchas.md`.
- Evidence: `docs/investigations/DEV-2342/evidence-38238-projection.md` (Jeffrey Cobb — sc_positions
  PK bypass + projection plan) and `evidence-38240-incident.md` (the incident report).
- Full ticket JSON (jq the description/comments if needed):
  `/Users/daltonmccleery/.claude/projects/-Users-daltonmccleery-Herd-platform--claude-worktrees-dev-2342-83df5a/c5393299-bc4c-4467-8903-39d61cac204e/tool-results/mcp-0d6dde38-d850-410d-8efa-bbdae27e848c-getJiraIssue-1786633454160.txt`

## Root-cause chain (from the ticket)

1. `lower(kit_mapping)` disables the `sc_positions` PK (ORDER BY is case-sensitive `(kit_mapping,
   timestamp_utc)`). 3056/32732 granules scanned vs 13/32732 exact-case. ~600ms vs ~20ms/chunk.
2. One org has ~255k open addresses → 128 chunk queries/kit at `ADDRESS_CHUNK = 2000`.
3. **The amplifier:** the recorder pulls ~255k addresses OUT into a PHP array, re-serializes them as
   inline SQL `values(...)`, pushes them BACK in 2000 at a time, per kit → ~5,500 CH queries/cycle.
   Both the duration problem and the memory problem.
4. Long cycles defeat `withoutOverlapping(10)` (a 10-min lock *expiry*, not a duration cap) → a new
   process ~every 10 min → concurrency ≈ longest_cycle/10 ≈ 17-21. Measured peak 17.
5. Each process materializes its own copy: `$addresses` held across the 43-kit loop, `array_chunk()`
   copies the whole array per kit, `$matches[]` accumulates. 17 × ~1.5-3 GB ≈ ~29 GB. The outage.

## Plan of record (from the ticket) — SCOPE THIS PR

- **W2 (PRIMARY, this repo):** rewrite the recorder to compute the match **server-side in ONE
  ClickHouse statement per org (or per kit — design risk 1)** by lifting ScoutCheckInRollupQuery's
  matches CTE: position_window ⋈ (open_hotlists → address_links → addresses, bbox-prefiltered) via
  `geoDistance(...) <= 160.934`, GROUP BY (kit, address_id, hour_bucket) argMin on timestamp_utc →
  returns ONLY matches (~40 rows/cycle, not 255k addresses). Then `ScoutCheckIn::insertOrIgnore($rows)`
  one bulk write against the existing `unique(kit_id, address_id, hour_bucket)`. Nothing large crosses
  the PHP boundary. Binding hazards from the rollup docblock: keep the bbox prefilter
  (`BBOX_PAD_DEGREES`); keep BOTH prunes on `public_address` (`id IN (subquery)` + INNER JOIN
  address_links for is_staged); parse EWKT with `extract()`+`toFloat64OrNull` (never `readWKTPoint`);
  skip FINAL on the two zod tables (use `_peerdb_is_deleted = 0` + DISTINCT).
- **W3 (this repo):** a hard concurrency ceiling that holds independent of runtime — an N-slot
  semaphore acquired at command start (exit if no slot) OR per-org queued jobs with
  `HORIZON_SUPERVISOR_*_PROCESSES` set deliberately (`maxProcesses` default 1 → serializes; note it).
  **Coupling invariant (DEV-2348):** if lock expiry rises, lookback must be ≥ it. Today expiry
  10 < lookback 15 = zero loss window; do not break.
- **W1 (chlocal, SEPARATE REPO — does NOT ship here):** sc_positions `lower(kit_mapping)` projection.
  Platform is SELECT-only. Needs a chlocal ticket. **Out of scope for this PR** except possibly a
  wiki note. (Comment 38238 is explicit: "This ALTER does not ship on DEV-2342.")
- **W4 (re-enable):** uncomment the schedule only after W1-W3 + criteria; requires production host
  memory verification (`system.mem.pct_usable` flat). **Not fully doable in this pipeline** — the
  re-enable + host watch is an operational step gated on prod observation.

## Live design risks (MATERIAL — must become gate questions)

1. **`IN` vs equality on the projection.** Per-org batch → `lower(kit_mapping) IN (...)`, which the
   optimizer may ignore (losing the prune); per-kit equality prunes cleanly. Decides ~12 vs ~43
   queries/cycle. Settle with EXPLAIN; prefer more queries over losing the prune. NOTE: the projection
   is W1/chlocal, so on the current (unprojected) table BOTH shapes are slow — this risk also affects
   whether W2 alone is enough before W1 lands.
2. **GPS is far denser than LPR scans.** The rollup was tuned against `scans`; `sc_positions` fixes
   arrive ~every 3s, so `position_window` is much larger and the bbox less selective. Measure before
   assuming the rollup's performance profile transfers.

## Acceptance criteria (9) — every one gets an explicit implement/defer decision

1. Match computed server-side in CH; no per-org address list crosses PHP; no re-serialization to SQL.
2. Duration: full cycle at prod cardinality < 5-min cadence, **measured**.
3. Memory: peak RSS O(matches), budget stated; peak × plausible concurrency fits ~30 GB headroom.
4. Concurrency: hard ceiling independent of runtime; lookback invariant holds.
5. Projection proven: single-kit 15-min EXPLAIN granules in tens; matcher query_log p50 « 400ms,
   read_rows « ~500k. **(depends on W1/chlocal projection — cross-repo)**
6. Regression tests: a peak-memory assertion at prod-representative address cardinality (not the
   current 3-address fixtures). Zero `memory_get_peak_usage` assertions exist today.
7. Silent-failure guard: `ClickhouseQuery::query()` swallows `\Throwable` to `[]` in prod, so a failed
   match looks identical to an empty one. The recorder must distinguish "ran, empty" from "failed".
8. Host verified: `pct_usable` flat across a full cycle after re-enable. **(operational, prod-only)**
9. Parity: rewritten matcher and ScoutCheckInRollupQuery agree on the same inputs (one domain rule).

## Out of scope

- DEV-2309 (CloseInactiveShifts PG timeout) — separate defect.
- Changing where check-ins are stored (stay in PostgreSQL `platform.scout_check_ins`).
- W1 sc_positions DDL (chlocal-owned).

## Precedents (see precedents.json)

DEV-2313 (In Code Review — introduced the recorder + the parallel matcher this rewrites),
DEV-2347/2348/2349/2339 (Done — folded in), DEV-2163 (rollup address-source + bbox),
DEV-2012 (the lower(kit_mapping) convention), DEV-1758 (the documented lift point).

## Known platform gotchas that bind here (from memory + wiki)

- `ClickhouseQuery` swallows `\Throwable` → `[]` in production|testing — any "did it fail?" guard on a
  bare result is dead code (this is AC7; the old run burned a review round on exactly this).
- `Scan::hot()::query()` static-discards setTable — not directly relevant but same family of CH traps.
- CH hot tables have a 90-day TTL that rots fixtures; sc_positions TTL is 120d.
- The rollup omits FINAL on zod tables deliberately; the CH Scan/Hit models don't boot ApplyFinal.
