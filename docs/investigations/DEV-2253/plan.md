# DEV-2253 — Plan

**SCOUT: Investigate drivers missing from live org map despite scanning activity**

Route: bug · Gate: CLEAN (all four questions answered by Dalton, 2026-08-04) · Prep confidence: 92
Artifacts: [`prep-context.json`](prep-context.json) · [`derivation-log.json`](derivation-log.json)

## What we are building

`ClarityScoutBirdsEyeController::driverPositions()` (`app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php:78-129`)
reads only `clarity.sc_positions` and silently drops any kit with no row. Its sibling
`ClarityScoutMapController::kitPosition()` (`:473-608`) already degrades through
`sc_positions → kit_scan_paths → kit_trips` — its comment at `:489` says the fallback exists
"for kits not reporting to sc_positions". So a kit that scans all day but reports no GPS is
visible on its own driver map and invisible on the admin org map.

Production measurement (`derivation-log.json` d4): **6 of 6** currently-affected kits at Premier
Adjusters (4) and Accurate (2) are renderable from `clarity.kit_scan_paths` inside the endpoint's
own 24h window. **0 unrescuable.**

The fix gives the org dot the same scan-derived tier, labels its provenance honestly, and repairs
the one regression the fix itself introduces (the DEV-2197 no-GPS banner).

**UAT needed: yes.** Four of six nodes are user-visible, and t4 (dimmed dot + tooltip) is an
explicitly revertible cosmetic default that Dalton wants surfaced as a look-at-this rather than a gate.

## File count

**9 target files** (2 new PHP, 2 PHP edits incl. tests, 2 Vue, 2 wiki pages, 1 built-asset tree).
This is a single concern — *make GPS-dark scanning drivers visible on the org map, honestly
labelled* — and Dalton explicitly chose to include the client work in the Phase 2 interview.
**No ticket split recommended.** The count is noted only so the size is not a surprise at review.

## The DAG

```
t1  new query class + unit test
 └─ t2  controller: fallback merge + position_source + feature tests   [THE FIX]
     ├─ t3  App.vue — banner keys on real-GPS count        ┐ parallel
     ├─ t4  ScannerMap.vue — dim + tooltip the rescued dot ┘ parallel
     │   └─ t5  npm run build → commit public/build/**     (must be LAST, runs alone)
     └─ t6  okf wiki (parallel with t3/t4)
```

| id | title | files | depends on | parallel | test filter | complexity |
|----|-------|-------|-----------|----------|-------------|------------|
| t1 | Add `OrgScanPathPositionsQuery` | `app/Clickhouse/Queries/OrgScanPathPositionsQuery.php`, `tests/Unit/Clickhouse/Queries/OrgScanPathPositionsQueryTest.php` | — | yes | `OrgScanPathPositionsQueryTest` | complex |
| t2 | Merge the fallback tier + `position_source` | `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`, `tests/Feature/Http/Controllers/Api/ClarityScoutBirdsEyeControllerTest.php` | t1 | yes | `ClarityScoutBirdsEyeControllerTest` | complex |
| t3 | DEV-2197 banner → real-GPS count | `resources/js/clarity-scout/App.vue` | t2 | yes | `ClarityScoutBirdsEyeControllerTest` (proxy) | simple |
| t4 | Dim + tooltip the scan-derived dot | `resources/js/clarity-scout/components/ScannerMap.vue` | t2 | yes | `ClarityScoutBirdsEyeControllerTest` (proxy) | simple |
| t5 | Rebuild + commit assets | `public/build/**` | t3, t4 | **no** | none (see below) | complex |
| t6 | okf wiki | `okf/concepts/scout-clickhouse-queries.md`, `okf/concepts/scout-birds-eye.md` | t2 | yes | none (`php scripts/okf-lint.php`) | complex |

**Blast radius: empty on every node.** Verified against `database/migrations/**` (none — this is a
read-only ClickHouse addition, no schema change, no new MV, no CDC change, no writes), billing,
Shield/Policies/auth config, `Clickhouse*Purge|Cleanup`, and `PruneScans`.

## Sequencing constraints

- **t3/t4 need the payload field name**, so both depend on t2 (which introduces `position_source`).
  They are file-disjoint from each other and may run in parallel.
- **t5 must run after t3 and t4 are committed.** It is `parallelizable: false` on purpose:
  `npm run build` mutates repo-root files *outside* its `target_files` and must not race another worker.
- **The fallback merge and the payload field both live in `ClarityScoutBirdsEyeController.php`**, so
  they are deliberately one node (t2). Splitting them would put two workers in the same file.
- **TDD per node**: pinning test first, RED → GREEN. t2's tier-1 happy-path pin is expected to pass
  immediately — it is a regression guard for an endpoint that has **zero** existing coverage.

## Test-environment facts every node must carry

1. **`RequiresClickhouse` skips the whole class** unless `CLICKHOUSE_DRIVER=clickhouse`
   (`tests/Concerns/RequiresClickhouse.php:38-41`). A green local run may mean *skipped*, not passed —
   confirm from the runner output that the new tests **executed**, or the RED→GREEN transition proves nothing.
2. **`clarity.kit_scan_paths` has a 90-day DELETE TTL applied AT INSERT**
   (`database/schema/clickhouse-schema.sql:143`). A fixture anchored to a hardcoded past date reads
   back as 0 rows with nothing to blame. Anchor **every** fixture to `now()` / `now()->subMinutes(N)`.
   The Bird's Eye test file's own `frozenNow()` helper (`:102`) documents this.
3. **`FINAL` is mandatory** on `kit_scan_paths` (`ReplacingMergeTree(_version)`); it is a documented
   no-op under the pgsql bridge (`app/Providers/AppServiceProvider.php:69-82`), so no special-casing.
4. **Two join keys coexist in `driverPositions()`**: `sc_positions` is keyed by `lower(kit_mapping)`,
   `kit_scan_paths` by `(org_id, kit_id)` — `kit_scan_paths` has no `kit_mapping` column at all.
   Do not conflate them.
5. **`driverPositions()` has zero test coverage today.** Pin the tier-1 happy path too, not just the
   new fallback.
6. **Repo convention**: log + return a degraded payload on any ClickHouse `Throwable`, never a 500.
   A tier-2 failure must never wipe tier-1 results.
7. **Do not fall back on a *thrown* tier-1 read** — the early `return` inside the existing `catch`
   implements this with no bespoke flag, mirroring `ClarityScoutMapController.php:526-538` (same
   cluster ⇒ retrying re-pays the timeout on every 10s poll).
   *Known nuance, not a defect:* `ClickhouseQuery::query()` itself swallows `Throwable` and returns
   `[]` when `app.env` is `production|testing` (`ClickhouseQuery.php:29-36`), so in production a real
   outage surfaces as an *empty* tier-1 result and the fallback fires once, also swallowing to `[]`
   → degraded empty payload, no 500. Accepted. Do not invent a did-tier-1-throw flag.
8. **`npm run build` regenerates `CLAUDE.md` / `AGENTS.md` / the copilot + junie instruction files and
   drops `boost.json`.** All of that must be reverted so only real asset changes are committed
   (t5 carries the checklist). Service-worker timestamp/hash churn from `scripts/version-sw.mjs` is legitimate.
9. **Do not run the full PHP suite** — it cannot finish locally under the 128M `memory_limit`. t5 and
   t6 have no phpunit filter; their gates are the build/revert checklist and `php scripts/okf-lint.php`.

## Node detail

### t1 — `OrgScanPathPositionsQuery` (+ unit test)

One batched round trip answering "where was each of these kits last seen scanning?", modelled on
`OrgLivePositionsQuery.php:20-78` (same `argMax`-per-group shape, same UTC `defaultCutoff()`, same
`AS ts` alias convention) and on `KitScanPathsQuery.php:76` for the mandatory `FINAL`. Batched rather
than fanned out per kit because the endpoint polls ~10s over a whole org (Premier: 178 kits).

Two design choices worth not re-deriving:
- **Two scalar `argMax` columns**, not `argMax()` over the tuple — avoids the driver-dependent tuple
  decoding `kitPosition()` has to hand-destructure at `ClarityScoutMapController.php:557`.
- **`length(path_points) >= 1` guard** — `arrayElement()` on an empty array returns the default
  `(0,0)` tuple, which would plot a phantom dot in the Atlantic. This is an out-of-bounds guard, *not*
  the dropped `(0,0)` sentinel work on `sc_positions`.

### t2 — the fix

Three inseparable edits in one file: (a) the tier-2 merge with tier 1 always winning, (b) sourcing
the *missing* set from the **full scoped `Kit` collection** rather than `$byMapping`'s keys — so kits
that `kitsByMapping()` (`:742-757`) drops for a null/empty `kit_mapping` are also rescued via
`kit_id` — and (c) the new `position_source` key.

`position_source` is a **new** field: `'gps'` for a live `sc_positions` fix, `'scan_path'` for a
`kit_scan_paths` rescue. The existing `source` field is left alone — it means scanner-hardware vs
browser-GPS and is consumed by `stores/map.js:474`. Repurposing it was explicitly rejected (Q3).

The old `if ($byMapping === []) return []` early return is replaced by `if ($kits->isEmpty())`, so a
fully mapping-less org still reaches tier 2. Both tiers share one computed cutoff string so they can
never diverge.

Six new feature tests: tier-1 pin · fallback rescue (RED) · tier-1 precedence · neither-source omitted ·
mapping-less kit rescued via `kit_id` · one fallback query for the whole missing set. Plus an optional
seventh (tier-2 failure leaves tier-1 dots intact) — to be **skipped and reported** if forcing the
throw is not cleanly reachable, rather than weakened into a meaningless assertion.

### t3 — the DEV-2197 banner (Q2)

The one genuine regression the fix introduces. `noKitsReporting` (`App.vue:339-345`) fires on
`driverPositions.length === 0`; after t2 a fully GPS-dark org gets a rescued dot for every driver, the
condition goes false, and a statement that is **still true** is silently suppressed. Re-key it on a
count of `position_source === 'gps'` entries. Both consumers (the dismissible card at `:38`, the
persistent amber pill at `:90`) inherit the fix from the single computed. The store passes the payload
through verbatim (`stores/map.js:943`), so no store change is needed.

### t4 — dim + tooltip (Q1, cosmetic default)

A rescued dot is up to ~15 min stale, sits at the last *scan* rather than the vehicle, and freezes the
moment a driver stops scanning even while still driving — so it should read as "last known", not as a
live fix. `updateDriverMarkers()` (`ScannerMap.vue:691-735`) currently plots every entry uniformly and
never reads provenance. Note the existing update branch calls only `setPosition()`/`setIcon()` and
never `setTitle()`, so the title must now be refreshed too, otherwise a kit transitioning
`gps ⇄ scan_path` keeps the wrong tooltip. Nameplates and `fitToDriverPositions()` are deliberately
left provenance-agnostic — a rescued dot still deserves a nameplate and still belongs in the bounds.

Dalton flagged this as trivially revertible and a human-QA look-at-this rather than a gate.

### t5 — built assets

This repo tracks `public/build` (15 files incl. `manifest.json`), so a Vue change without rebuilt,
committed assets does not take effect for anyone. Runs alone, after t3 and t4. Full revert checklist
and the expected-churn description live in the node's `intended_diffs`.

### t6 — okf wiki

The wiki currently documents the exact asymmetry this ticket removes
(`scout-clickhouse-queries.md:111-118` spells out the 3-tier degrade; `:75-91` presents
`OrgLivePositionsQuery` as the whole story; `scout-birds-eye.md:25` lists driver-positions as backed
by `OrgLivePositionsQuery` alone). Also records in one line that `driverPaths()`/`OrgLivePathsQuery`
and the single-driver `livePath()` still carry the unfixed idiom (DEV-2228), so the next reader does
not assume parity. Additionally fixes pre-existing staleness at `scout-birds-eye.md:51-60`, where the
`orgScoutKits()` pseudocode still shows the `kit_settings` enrollment gate DEV-2182 removed.

## Deliberately excluded

Every site from prep's exhaustiveness sweep that is **not** covered by a subtask, with its reason.
The machine-readable list is `excluded_sites[]` in `plan.json`.

| Site | Why excluded |
|------|--------------|
| `driverPaths()` (`:140-211`) — org breadcrumb trail | Same idiom, but Q4 fixed this ticket at **dot only**; a trail fallback is materially different work → DEV-2228 |
| `OrgLivePathsQuery` | Same single-source idiom on the trail surface → DEV-2228 |
| `livePath()` + `KitHistoricPositionsQuery` — single-driver trail | Same idiom; `HistoricPathBuilder`'s 500ft segmentation was tuned for 3s-decimated GPS and would mis-segment 15-min buckets → DEV-2228 |
| `BuildShiftSummary.php:105-117` — 0 miles / empty map | Same idiom, different consumer (async shift email); no accurate mileage fallback exists (bucket endpoints ⇒ wildly wrong distances). Own ticket |
| `scanPaths()` historical-range branch | Same idiom, but a deliberate DEV-2018 granularity tradeoff already pinned by a test |
| Missing `geo != (0,0)` filter in `OrgLivePositionsQuery` / `OrgLivePathsQuery` / `KitHistoricPositionsQuery` | **Proven inert**: 0 rows with `geo=(0,0)` across 172,667,380 points / 585 kits / 60 days (d1). All three prep candidates: leave it, don't even split a follow-up |
| Tier-3 `kit_trips` fallback in `driverPositions()` | 0 of 6 measured rescues and structurally incapable of helping — `kit_trips` derives from `sc_positions`, so it is empty exactly when `sc_positions` is (d4) |
| Propagating `position_source` to trails / `kitPosition()` / `livePath()` | Deferred with the trail work per Q4; the driver's own map already renders these kits |
| `ClarityScoutBirdsEyeDispositionController.php:28` `withShared()` vs `orgScoutKits()` never merging shared kits | Real asymmetry, but unconfirmed and **not** the measured cause — most kits in both orgs render fine and 6/6 affected are rescued by tier 2. Needs its own investigation |
| Upstream: kits not reporting GPS (77.8% fleet-wide of kits that scanned in 24h) | Out of this repo — the Python GPS egress loader (DEV-2043 traced this class there). Platform can only stop their absence from blanking the map |
| The controller's app-tz cutoff string | Latent DEV-2164-class smell, but `APP_TIMEZONE=UTC` (`config/app.php:113`) so it is UTC in practice; touching it changes tier-1 behaviour for no measured benefit |

## QA framing

- **Agent QA**: fallback renders a dot · tier precedence · no phantom dots · mapping-less kit rescued ·
  one query not N. Seed shape and personas are in `prep-context.json → qa_hint`.
- **Human QA (the judgment call)**: on the real Bird's Eye map, confirm rescued dots appear for the
  affected-shaped fixtures, that the dimmed styling + tooltip reads correctly rather than as a bug,
  and that the DEV-2197 banner still fires for a fully GPS-dark org. t4 is the revertible one.
- **Cross-link DEV-2228 to this ticket** (Q4). This fix explains but does **not** fix DEV-2228's
  trail-rendering gap — state that explicitly in the closeout rather than implying parity.
