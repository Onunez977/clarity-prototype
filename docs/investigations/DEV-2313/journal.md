2026-08-07 10:59 CDT · intake · intake complete: route=bug app=clarity base=main
2026-08-07 11:12 CDT · prepped · prep complete: gate=BLOCKED, 2 material needs-human questions (restore-UI scope decision); backend confirmed correct + no background writer; orphaned frontend caller since f0152f41d (2026-06-09) verified via git+grep; best-of-N skipped (fact-grounded conclusion verified deterministically)

## Phase 2 gate — human interview (2026-08-07)
Q: Restore UI / docs-only / confirm-close?
A (Dalton): "There should be some back-end process that we can do to have this be running in the background, but also still have the UI hidden. Can we investigate that?"
=> New direction: design a background process that periodically COMPUTES + PERSISTS Scout check-in rollups (so data is captured continuously / "running in background"), while keeping the UI hidden (no frontend restore). Immediate ask = INVESTIGATE the approach. Run stays parked at gate on the design decision.
2026-08-07 12:24 CDT · gated · gate CLEAN: owner chose background-persist (nightly Postgres rollup, UI hidden); design confirmed
2026-08-07 12:26 CDT · planned · plan: 1 node (single-node fast path) — new Postgres scout_check_in_rollups table + scout:aggregate-check-ins nightly command reusing ScoutCheckInRollupQuery; blast-radius (additive migration) approved; no perf gate
2026-08-07 12:30 CDT · provisioned · provision-only Postgres: reused isolated worktree dev+testing DBs (migrated); dev-login on; CH deferred/neutralized; ledger written
2026-08-07 12:46 CDT · implemented · implement: scout:aggregate-check-ins command + scout_check_in_rollups table + model + nightly schedule + RequiresClickhouse test (4 green); reuses ScoutCheckInRollupQuery; existing 47 check-in tests still green; blast-radius=migration (pre-approved)
2026-08-07 13:00 CDT · reviewed · review RN1: 100/100 across 3 in-session opus lanes, zero scoring defects; 6 non-scoring notes posted to PR (all nits, no follow-up ticket)
2026-08-07 13:03 CDT · reviewed · revert accidental boost-doc regen churn (php 8.4.23->8.3 in CLAUDE.md/AGENTS.md/.junie) that leaked into the intake checkpoint via git add -A; keeps the shipped diff to only the 5 intended files
2026-08-07 13:07 CDT · verified · verify PASS: AC1-AC5 SATISFIED; command test 4/4 + existing check-in suite 47/47 green vs real local CH; dev-env smoke clean; no findings

## PIVOT (2026-08-07) — rework to DEV-1756 full spec
Checked the original check-in tickets at Dalton's request. DEV-1756 ("Automated Check-ins — Count GPS positions matching assignment addresses", epic DEV-1623) SPEC: detect from GPS positions matching Direct Order / Staging Order / HH/AA addresses, RECORD each match as a check-in, maintain a running count per address, automated. The shipped implementation drifted: scan-based (not GPS), on-read counts (not recorded), Direct/Staging only (no HH/AA), UI-triggered.
Dalton chose: REWORK DEV-2313 to the full spec. Scrap the nightly count-rollup (ScoutAggregateCheckIns + ScoutCheckInRollup + migration). New target: a backend GPS-position (sc_positions) -> order-address (Direct/Staging/HH-AA) check-in RECORDER with per-address running counts, automated. Architecture pivot -> re-entering prep/plan; prior RN1 100/100 review applies only to the scrapped approach.
Related: DEV-2258 (Priority-address dispatch w/ automated check-in confirmation), DEV-1758 (auto-sync Direct Order GPS -> RDN). Launched a data-model + design investigation (sc_positions, HH/AA, recording model, perf).

## HH/AA resolved + open questions posted (2026-08-07)
Checked the RDN metrics pusher (Dalton's pointer): RdnMetricType + RdnHitHourCountQuery define hit_type codes L=Live, H=Historical Hits (HH), S=Staging Live, Z=Staging Historical (AA). So HH/AA are HIT-TYPE categories, NOT a separate address category — no new ingestion path needed; addresses still come from hotlist->hotlist_address->address. Check-ins should span all 4 categories (spec: "every match regardless of assignment type"), labelled by category. Exact historical-status->hotlist-selection mapping still to confirm.
DEV-1756 specifies neither dedup granularity nor cadence. Posted open-questions comment (id 38136) to DEV-2313 tagging Oscar Nunez (accountId 712020:d36a2f57-a650-4f7f-bf65-bf5d2d5a444b) via ADF mention: (1) HH/AA scope + historical hotlist-status mapping, (2) dedup granularity, (3) cadence.
PARKED awaiting product answers. Cannot build the recorder until dedup + cadence + HH/AA scope are answered (material data-semantics). Perf measurement on sc_positions to follow once cadence is chosen.

## Oscar answered (2026-08-10, comment 38143) — gate CLEAN
1. HH/AA scope: group by ASSIGNMENT TYPE not hit category. Track check-ins for all OPEN direct + OPEN staging orders (any open status, both direct+staging). Historical/closed OUT of scope. => reuse the EXISTING ScoutCheckInRollupQuery address scope exactly (open hotlist, is_staged split); NO HH/AA, no new enumeration.
2. Dedup: one check-in per (kit, address, hour) — same as today.
3. Cadence: near-real-time ideal (future: live map indicator UX); if too heavy on sc_positions, start 5-15 min and iterate — defer to the perf check.
Gate CLEAN. Next: perf-measure sc_positions (read-only prod) to set cadence, then re-plan + implement the GPS-position recorder. Live-map indicator = out of scope (UI hidden, future follow-up).
2026-08-10 11:56 CDT · planned · re-plan: scrap nightly count-rollup (4 files); recorder plan locked (GPS sc_positions -> open direct/staging address match, one check-in per kit/address/hour, every 5 min); perf-validated on prod CH (sc_positions ~255M, per-kit read ~120ms index-bounded, fleet ~150 kits)
2026-08-10 12:18 CDT · implemented · implement recorder: scout:record-check-ins command + ScoutCheckInMatchQuery (sc_positions x open direct/staging addresses) + scout_check_ins table + model + every-5-min schedule; TDD 5/5 green vs real local CH; additive-only (existing check-in code untouched); blast-radius=migration (pre-approved)
2026-08-10 12:36 CDT · implemented · review-fix (RN2 findings): deterministic match aggregation (argMin/min, direct-preferred) fixes any() nondeterminism + matched_at now uses the earliest fix time via firstOrCreate (stable across the sliding window); +3 tests (geoDistance band within bbox, hour-boundary split, shared direct+staging address). 8/8 green
2026-08-10 12:42 CDT · reviewed · review complete: RN2 3 lanes 94/94/90 -> fixed 2 scoring findings -> RN3 re-review 100/100; recorder deterministic + tests pin geoDistance/hour-boundary/shared-address; rework_pivot cleared
2026-08-10 12:44 CDT · verified · verify PASS (recorder): AC1-AC6 SATISFIED; ScoutRecordCheckInsCommandTest 8/8 green vs real local CH; dev-env smoke clean + schedule every-5-min; review RN3 100/100; perf prod-measured
2026-08-10 13:05 CDT · verified · genuine agent-QA: qa-prep seeded a real Scout scenario; backend lane B1-B8 all PASS vs real local CH incl. LIVE scout:record-check-ins recording 2 rows (direct+staging), idempotent, flag-gated; AC-1..AC-6 SATISFIED; log clean
2026-08-10 13:50 CDT · reviewed · hardening (test-adequacy, requested): CH liveness probe surfaces a CH outage instead of silent 0; +3 tests (non-UTC driver -> UTC hour_bucket, multi-driver scoping + kitless skip, CH-unreachable graceful). 11/11 green
2026-08-10 13:55 CDT · reviewed · RN4 re-review 100/100: CH liveness probe + 3 resilience/tz tests verified load-bearing; suite 11/11 green; happy path + AC1-AC6 unchanged

## Cross-ticket check-in definition review (2026-08-10)
Pulled DEV-1758 (Auto-sync Direct Order GPS -> RDN) + DEV-2258 (priority-address dispatch w/ automated check-in confirmation). Found the intended check-in definition is MORE specific than what we built:
- Jeff Cobb (DEV-1758): "Checkin = passed by a hotlist address but NO HIT created for the hotlist address within 30 minutes. Only direct work not staging. Driven within 1/10 mile."
- DEV-2258: "GPS passed the given address, even without a camera hit" -> coverage checklist. Consumers = DEV-1758 (RDN case update) + DEV-2258 (coverage %).
Our build: GPS proximity (<=160.934m ✓) to OPEN direct/staging addresses, but does NOT exclude addresses with a hit within 30 min, and includes staging.
Dalton (2026-08-10): Oscar's DEV-2313 answer (Direct + Staging) is CORRECT and supersedes Jeff's direct-only. The "no hit within 30 min" exclusion = investigate further before finalizing.
HOLD finalize. Investigating the no-hit-within-30min exclusion (hits data model + matcher anti-join + residual semantic questions).

## Exclusion resolved — DEV-2313 finalized as complete (2026-08-11)
Re-read the other check-in tickets at Dalton's request before asking product. Finding: the "no hit within 30 min" exclusion is NOT a gap in the recorder — it belongs to DEV-1758 (the RDN case-update sync) as a PUSH-TIME filter, not a RECORD-time filter. Evidence chain:
- DEV-1756 recorder spec = "every ~160m GPS match recorded" (no exclusion); DEV-2313 is a faithful rebuild of that recorder.
- Oscar (DEV-1758, comment 28271): 1758 "really only handles Clarity configuration to turn on the case updates to RDN and the actual case update push" — check-in CREATION is a separate prerequisite (1756/2313).
- Jeff's rule (DEV-1758, 2026-07-08) is scoped "direct only" because only Direct orders carry an RDN case (remote_order_id) — a push constraint — and Oscar already overrode the direct-only half for DEV-2313's RECORD scope (Direct+Staging). The no-hit half sits in the same push-scoped definition.
- DEV-2258 defines a check-in as "GPS passed the given address, even without a camera hit" → the check-in is the coverage signal regardless of hits; its coverage checklist wants every pass recorded, not a hit-filtered subset.
Question scorecard (had we put it in the recorder): address-level (ANSWERED: Jeff/2258/1756/2163), live "camera" hits L/S (ANSWERED: 2258), any-kit (sensible default), hour-void + ±30min direction (ours to default). None block DEV-2313.
Dalton chose Option A: post a one-line scoping confirm to Oscar on DEV-2313 (comment 38150) + leave the exclusion note on DEV-1758 (comment 38151), then finalize DEV-2313 as complete (recorder = broad Direct+Staging, no exclusion). blocked_on cleared.
2026-08-11 · handoff · finalize: telemetry updated, real CI-trigger commit, PR #314 undraft+retitle, Jira → In Code Review, QA env torn down (manual CH path — seed rows in shared literal clarity/zod).

## Perf fix A+B — per-org enumeration + cache (2026-08-11)
QA of the raw ScoutCheckInMatchQuery (kit jasonstephens / org 321316, proven vs prod: 72 check-ins/24h) surfaced "Read 759M rows / 11.38GB" per kit. Investigation: zod.public_hotlist_address is ORDER BY id, so the hotlist_id join CANNOT prune (EXPLAIN: 38830/38830 granules) -> full 311M scan + clarity.hotlist FINAL, done PER KIT. The original perf_validation ("~120ms index-bounded") measured only the sc_positions read, missing the address-enumeration cost (~2.5-12s/kit). At ~150 kits/5min that overruns the cycle + hammers CH. It's DEV-2163's M1 amplified fleet-wide.
Dalton chose A+B now in this PR (investigated the fix first with real numbers).
- A: new ScoutOpenOrderAddressesQuery enumerates the org's open Direct/Staging addresses ONCE per org (all the org's kits share it; org 321316 has 6 kits).
- B: cache that per-org set 15 min (ClickhouseQuery::query cache:true; opens turn over in hours).
- ScoutCheckInMatchQuery refactored: takes the cached address set, injects it as a CH values() literal (chunked 2000 to stay under max_query_size), reads only sc_positions per kit (~130ms) + geoDistance -> NO per-kit 311M scan. geoDistance/bbox/aggregation unchanged.
Equivalence PROVEN: NEW vs OLD combined query at a 6h window = 41/41 IDENTICAL (address|hour|hotlist). Both query classes smoke-tested end-to-end vs real local CH (enum -> 1 addr; match via values -> 1 check-in; empty-address short-circuit -> 0). Pint clean. Full PHPUnit (CH-backed) runs in CI (local test-DB torn down; RefreshDatabase would rebuild the PostGIS/tiger stack). Projected fleet cost ~20-57s/cycle (from ~375s+). Re-review warranted (supersedes RN4 single-query design).

## Final 3-lane audit (2026-08-11, requested) — 100/100/94 -> all findings closed
Lanes: sql-data 100 (empty-bbox/geoDistance-arg-order/UInt64-coercion/addslashes all verified safe), runtime-ops 100 (when() precedes mutex -> flag-off inert; background lock released via schedule:finish; CH exception NOT cached-as-[]; createOrFirst absorbs races), tests-ac-docs 94 (M-1 untested resilience paths; M-2 stale wiki claim).
Fixes: corrected scout-dashboard-stats.md stale recovery_address claim (M-2); +4 tests closing M-1 (per-org failure via env=local rethrow + dead CH; per-kit failure via cache-primed enumeration + dead-CH matcher — table-rename approach abandoned, PG 25P02 aborts the RefreshDatabase tx; --since widening; chunk_size=1 multi-chunk merge); matcher coords -> single tuple argMin (lane-1 same-second blend nit); zod. hardcode -> zodDb(); okf gotchas +outage-alerting corollary +pennant:purge runbook note.
Local verification: cloned zod_testing as the worktree test DB (createdb -T) -> full class 17/17 green vs real local CH. Runbook residuals documented in okf.

## Definition triple-check vs the full Jira trail (2026-08-11)
Swept DEV project for check-in/visit/coverage tickets (2 JQL passes; extracted the full key/status/epic/summary set structurally). Read in full: DEV-1756 (origin spec), DEV-1757, DEV-1661, DEV-2251 (+ previously DEV-1758, DEV-2163, DEV-2258, DEV-2313).
CORROBORATED — no change to the built logic:
- DEV-1756 verbatim: "When a GPS position matches an address tied to a Direct Order, Staging Order, or HH/AA, automatically record it as a check-in... EVERY match should be tracked regardless of assignment type" => GPS-driven, automated, NO hit exclusion. Matches the build.
- DEV-1661 (LPR Visits, shipped+QA'd) independently establishes BOTH our constants: "one pass-through of a location" = geoDistance <= 160.934m grouped into toStartOfHour 1-hour blocks; fixed a ~60x inflation (392 raw scans -> 6 visits). LprVisitCountQuery.php:18,25 confirms in code. Our hour bucket is the company standard, not an invention — and matters MORE for GPS (~3s fixes) than for scans.
- DEV-1757 pairs HH-with-Direct / AA-with-Staging => consistent with HH/AA being hit-type categories (historical direct/staging), not an address source. No conflict with Oscar's open-Direct+Staging scope.
- DEV-2251 (zone coverage %) is breadcrumb/GPS-area based, NOT a check-in consumer — noted so the two coverage notions aren't conflated.
GAP CLOSED (doc-only): DEV-1756 AC "a running count is maintained per address" — satisfied BY DERIVATION (COUNT(*) per address_id, (org_id,address_id) index; DEV-1757's two tiles = countIf on is_staged), not a stored counter. Documented in check-in.md so a future reader doesn't think a counter column is missing.
okf/concepts/check-in.md gained a provenance section (why 0.1mi + why an hour, with the DEV-1661 quote) and a source-of-definition ticket table.
