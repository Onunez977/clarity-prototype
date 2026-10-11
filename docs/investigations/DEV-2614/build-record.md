**Insight AI — Build Record**

**TL;DR**

The Hits page, its metric cards and both Otto hit tools were meant to read from ClickHouse but never did for normal users. The routing check asked Pennant (Laravel's feature-flag library) for a flag named `clickhouse:hits`, a name that can never exist (the defined slug is `clickhouse-hits`), so only impersonating staff reached ClickHouse. Routing now follows `config('app.experimental.clickhouse.hits')`. Prod `CLICKHOUSE_HITS_ENABLED` is true and Dalton chose to leave it on, so deploying this moves every org and Otto onto ClickHouse at once. The ClickHouse query stays scoped by `kit_id` only, exactly like Postgres: a `hotlist_org_id IN (own, staging, silent)` prune (the ticket's AC-2) was built, reviewed and QA'd, then **withdrawn by Dalton after QA**, because it dropped 0.09% of rows versus Postgres for a modest win (30-day cold reads 10-14% fewer primary-key granules; Today unchanged) and its silent-org part matched nothing (production's one silent org owns only NICB orders, which the page already hides). After a production benchmark Dalton also revised AC-5: `FINAL` applies on `hits_hot` only (windows of 90 days or less). Without it, 0.7% (Today), 4.5% (30d) and 62% (120d cold) of an org's "Open" hits were stale and page 1 held 5 to 12 duplicate rows of 51; on hot it costs +2 to 4 ms, on 120d cold about 3.1x memory, which is why cold is excluded. Agent QA found a Disposition-column bug the flip would have shown to every org (every ClickHouse row rendered "New Hit"); it is fixed and pinned by a rendered-HTML test. The "New Hit" filter now matches Postgres (NULL or the literal).

Verified by CH-free shape and routing tests, live-ClickHouse row-set and metric-card classes (incl. a new pin that the cards inherit `FINAL` on hot, red without it), seven review rounds and two agent-QA runs; Dalton finished human QA, including a pass against production ClickHouse (read-only) as org 14, before AC-2 was withdrawn. The withdrawal removed code only; the remaining query is the one QA exercised minus the prune. Ship gate (AC-8, revised 2026-09-30): ship in the same release as DEV-2739's fix, insight-lpr/platform#469, or later. #469 is already on `main` and moves the per-row distance lookup to the same Postgres source the Postgres path uses, so merging this PR now is safe; only hot-fixing it to `deploy` alone would put every Pro org on the old 1.7%-resolve lookup. Open, none blocking: `hits_cold` (windows over 90 days) still shows stale-status and duplicate rows by decision; rows for one hit id that differ in `hit_created_at` survive even `FINAL`; the Missed Opportunities "New Hit" filter misses NULL rows on both branches (sibling bug, a different page); Hits filter analytics were never recorded. Blast radius: every org and Otto move to ClickHouse at deploy.

### Route, base branch, PR

Route: bug, app: clarity. Base branch `main` (ticket First Glance comment 42079; configured `CLARITY_MAIN_BRANCH=main`), branch `claude/DEV-2614`. PR: https://github.com/insight-lpr/platform/pull/485 (ready for review; ships with or after #469, AC-8). Jira status mirror comment 42285. Diff base `fd89c4f1d`; 11 files, +628/-38 outside `docs/investigations` (4 app files, `phpunit.xml`, 6 test files).

### What changed (net, as shipped)

| File | Change |
|---|---|
| `app/Support/Hits/HitsListQuery.php` | `shouldUseClickhouse()` = `config('app.experimental.clickhouse.hits', false) \|\| session()->has('admin_impersonator')` (DEV-2591 idiom, `ListScans.php:306-309`). `buildClickhouseQuery()` adds `->when($useHot, fn ($q) => $q->final())`, so the list, the cards and both Otto consumers get `FINAL` on `hits_hot` only. Scoping is unchanged: `notNicb()->forKits($kitIds)`. |
| `app/Clickhouse/Models/Hit.php` | `scopeForDispositions` emits `(disposition IS NULL OR disposition IN (...))` when New Hit is selected and keeps the literal `'New Hit'` (AC-7). `scopeValidDisposition` (Scout) rewritten as a direct `whereRaw` with byte-identical SQL; `scopeActionableDisposition` delegates to `forDispositions([NEW_HIT, ACCESSIBLE])`, SQL pinned identical. Scout behaviour unchanged. |
| `app/Filament/Resources/HitsResource.php` | Disposition column: CH records render `$record->disposition ?? 'New Hit'`, checked first; `->default('New Hit')` kept (Filament's blank-state early return); PG branch guards `json_decode` with `is_object` (RN6 L10). |
| `app/Filament/Concerns/HasHitMetricCards.php` | Docblock only: cards inherit `FINAL` on `hits_hot`, which collapses status re-emissions; rows for one id that differ in `hit_created_at` survive `FINAL` on either table (RN6 M2). |
| `phpunit.xml` | Force-pins `CLICKHOUSE_HITS_ENABLED=false` and `CLICKHOUSE_SCANS_ENABLED=false` so the default suite is deterministic. |
| `tests/Filament/Resources/ClickhouseHitQueryShapeTest.php` (new) | Disposition scope SQL; real `baseQuery` on hot and cold: `kit_id in` kept, no `hotlist_org_id` in WHERE, `_peerdb_is_deleted` filtered, `0 = 1` on an empty kit set, `FINAL` on hot only (grammar-guarded on both tables), PG SQL unchanged, Scout SQL pinned. |
| `tests/Filament/Resources/ClickhouseRoutingTest.php` | `HitsListQuery` routing suite: config on/off, Pro-tier independence, impersonator, Pennant row ignored, `'clickhouse:hits'` not a defined slug. |
| `tests/Filament/Resources/HitsResourceClickhouseRowSetTest.php` (new, RequiresClickhouse) | Own-kit hits listed whatever their hotlist org (L, S, Z, foreign order); foreign kit, OverWatch and NICB absent; literal New Hit listed; Disposition cell rendered through `toEmbeddedHtml()`; cards count S/Z. |
| `tests/Filament/Resources/HitsResourceMetricCardsClickhouseTest.php` | New pin: a stale Open version replaced by a Repossessed version (same sort key) is not counted under the default Open filter (red with `FINAL` removed; RN6 M5). |
| `tests/Filament/Resources/HitsResourceTest.php`, `tests/Feature/Otto/OttoSearchToolsTest.php` | PG null-meta Disposition rendering pin; Otto CH sibling asserts `kit_id in` and no `hotlist_org_id` in WHERE; actionable filter keeps the literal New Hit. |

**Built, then withdrawn (AC-2).** `Hit::scopeForHotlistOrgs`, `HitsListQuery::hotlistOrgIds()` (own org ∪ `Org::stagingOrgIds()` ∪ silent orgs) and `silentOrgIds()` (10-minute cache plus a cold-path `Cache::lock`, hardened across RN1-RN4) shipped through QA, then were removed in `65740d7df` at Dalton's request. `Org.php` was never touched.

### WHY — root causes

- **Routing never reached ClickHouse.** `shouldUseClickhouse()` checked `Feature::active('clickhouse:hits')`. Pennant only defines `FeatureEnum::CLICKHOUSE_HITS->slug()` = `Str::slug('Clickhouse Hits')` = `clickhouse-hits`; `Str::slug()` cannot emit a colon, so the literal never matches and only `admin_impersonator` sessions used ClickHouse. Config routing is the DEV-2591 fix for Scans. Prod flag true (recorded 2026-09-15, DEV-2546; re-confirmed by Dalton), so fixing routing flips everyone.
- **Why the prune was tried, and why it went.** `hits_hot`/`hits_cold` are `ORDER BY (hotlist_org_id, toYYYYMM(hit_created_at), hit_created_at, id)` (confirmed on prod) and the query filtered only `kit_id` (not in the sort key; the `idx_kit_id` skip index does the work). `forOrg()` would drop Staging Live, because `find_hits()` stamps S/Z hits with the staging org, so the prune set had to be own ∪ approved staging ∪ silent orgs. Measured, it bought little (Today 4/4 granules unchanged; 30d cold PK granules -10% / -14%) and cost 0.09% of rows versus Postgres (revoked approvals). The silent part matched zero rows: production's one silent org owns only NICB orders (`integration_id = 5`), and ClickHouse holds no hits for it at all. Dalton withdrew AC-2; with `kit_id IN` alone the ClickHouse row set is scoped exactly like Postgres.
- **"New Hit" filter.** CH `forDispositions` turned New Hit into `disposition IS NULL` and dropped the literal; Postgres matches NULL or the literal (5,262 of 25.5M CH rows store it). Scout shares that helper, so its SQL is pinned unchanged.
- **Disposition column showed "New Hit" on every CH row.** `->default('New Hit')` turned the missing `scan_metadata` into the string before `formatStateUsing` ran, so the `$record->disposition` fallback never fired. Pre-existing, but only impersonating staff reached CH until this flip. The first fix removed `->default()` and blanked the cell (Filament's `TextColumn::toEmbeddedHtml` returns early on a blank state); RN3 caught it, and the pin now asserts the rendered HTML.
- **Stale versions need FINAL.** `hits_hot`/`hits_cold` are ReplacingMergeTree. The MV re-emits rows on hotlist status changes with an unchanged sort key and a newer `_version`; until a background merge both versions exist, so reads without `FINAL` return stale `hotlist_status` and duplicate ids. On hot windows `FINAL` is nearly free and collapses them (org 14 today page 1: 51 distinct of 51, was 46 of 51). It cannot collapse rows for one id that differ in `hit_created_at` (a sort-key column), which is why the cards keep `uniqExact(id)`. On cold `FINAL` is ~3.1x memory.

### Resolved questions (`Q → answer · source`)

- Q1 (AC-6 prod flag and rollout) → prod `CLICKHOUSE_HITS_ENABLED = true`; Dalton "I'll leave it on"; deploy flips every org and Otto, no staged rollout · human:Dalton McCleery 2026-09-29
- Q2 (staging-org source for the prune) → reuse `Org::stagingOrgIds()` unchanged, add silent orgs · human:Dalton McCleery 2026-09-29 — **superseded: prune withdrawn**
- Q3 (current-value prune drops revoked/de-flagged) → accepted; measured 834 / 892,313 = 0.09% over 30d · human:Dalton McCleery 2026-09-29 — **superseded: prune withdrawn, gap gone**
- Q4 (fix or document PG-vs-CH differences) → 4.1 accept, other hit types not wanted; 4.2 FIX "New Hit" filter (Hits list and Otto only, Scout unchanged); **4.3 REVISED (AC-5): `FINAL` on `hits_hot` only** ("Lets do FINAL only on hits_hot table", after the prod benchmark); 4.4 accept status lag; 4.5 ship after DEV-2739's fix (revised 2026-09-30: in the same release as #469 or later); 4.6 measured tiny (PG-only 0.009%, CH-only 0.11%), document · human:Dalton McCleery 2026-09-29
- Q5 (measure the prune benefit) → done read-only on prod; modest (see derivation) · human:Dalton McCleery 2026-09-29
- Q6 (config vs Pennant slug) → config + `admin_impersonator` (DEV-2591) · ticket
- Q7-Q10 (prune predicate, user org vs kits' orgs, silent orgs, widening) → answered in code for the prune · code — **superseded: prune withdrawn**
- Q11 (`hotlist_status` frozen or re-emitted) → re-emitted by the chlocal MV (near-live, ~1 min); conflicts with `okf/concepts/hit.md:66`; doc fix queued post-approval · code
- Q12 (consumers of `baseQuery`) → 3: ListHits (plus the cards), `HitSearchTool.php:142`, `InvestigationDataController.php:133`; grep backstop found 0 missed · code
- Q13 (ProTierEnum / Pennant define / okf cleanup) → out of scope, as DEV-2591 left it · ticket
- **AC-2 withdrawn** → "I think we should remove both" (the `hotlist_org_id` prune and the silent-org lookup), after the silent org was shown to be NICB-only; disposition fixes kept ("Keep both disposition fixes") · human:Dalton McCleery 2026-09-29
- AC-5 revision approval: recorded in `approvals` (2026-09-29) · human:Dalton McCleery

### What this ticket did NOT do

- **AC-2 (withdrawn):** no `hotlist_org_id` predicate; the ClickHouse Hits query relies on partition pruning by `hit_created_at` plus the `idx_kit_id` skip index, as before.
- **`scope.out_of_scope`:**
  - ProTierEnum `CLICKHOUSE_SCANS/HITS` entries, `Feature::define` lines, FeatureEnum cases.
  - Any `okf/` or `docs/plans` edit in flight; queued post-approval: `hit.md:66`, `feature-flags.md:36-37`, `hit-address-distance.md:52,98`, `otto.md`, `docs/plans/otto.md:160`, the `clickhouse:hits` wording in `HitsResourceMetricCardsClickhouseTest.php:22`, `HitDirectHotspotQuery.php:16` ("frozen at write"), and a note that CH `forDispositions` now matches PG while Scout's rule and Missed Opportunities do not.
  - `FINAL` on `hits_cold` (AC-5 revised: hot only).
  - Other PG-vs-CH differences (hit types, status source, distance columns); documented only.
  - chlocal DDL/MV changes (`hits_cold_mv` INNER JOINs, DEV-2189 late-scan drop).
  - Flipping `CLICKHOUSE_HITS_ENABLED` in prod (ops) and adding it to `.env.example`.
  - `buildPostgresQuery`, shared-kit support on Hits, `ListHits`, `TableMapComponent` id lookup; a `hit_created_at` bound on Otto `HitSearchTool`.
- **Deferred ACs:** AC-6 (ops gate; prod true, left on) and AC-8 (ship gate; same release as #469 or later).
- **Accepted, documented, not fixed:** matrix rows 1, 3, 4, 8; row 2 on `hits_cold`; Otto hydration `limit(count($hitIds))` can be crowded by unmerged duplicates on cold windows.
- **Residual review findings (open, none blocking):**
  - RN6 M4 / L11: `App\Clickhouse\Models\MissedOpportunity::scopeForDispositions` and its PG twin have no New-Hit NULL branch, so the Missed Opportunities "New Hit" filter misses NULL rows on both branches (parity holds; a different page).
  - RN6 L7: `phpunit.xml` pins the default suite to the Postgres branch; ClickHouse coverage of the Hits surface is the `RequiresClickhouse` classes only (they skip where CH is not configured; CI runs them).
  - RN6 L8 / RN5 L3: `scopeExcludeDisposition` (Hit, Scan, MissedOpportunity) would invert for `NEW_HIT`; current callers pass Invalid only.
  - RN6 L9 / RN2 L4: `validDisposition` inlined while `actionableDisposition` delegates; one rule, two constructions, pinned by SQL-identity tests.
  - RN2 L3: `HasHitMetricCards` docblock says M/F/P reach the table on both branches; false for CH.
  - RN2 L6: `OttoSearchToolsTest` date-only end-date test fails with 0 rows locally; PG branch untouched (pre-existing).
  - RN4 M3: `HitsResourceMetricCardsClickhouseTest` uses fixed ids/plates; local-only flake against the never-truncated CH test DB.
  - `SyncDispositionToClickhouse` hardcodes `clarity.` (pre-existing).
  - RN7 M3: the cards `FINAL` pin asserts an absolute 0 with no plate isolation; safe in CI (tables truncated per test), can flake locally on leftover rows.
  - RN7 L1: CH `forDispositions(null|[])` matches NULL only while PG applies no filter; unreachable from the Hits UI and Otto (both guard empty input).
  - RN7 L2: the hot/cold switch is `<= 90` days against the 90-day `hits_hot` TTL, so an Advanced range starting about 90 days ago can under-report its oldest slice.
  - RN7 L3: `TableMapComponent::fetchRecords()` applies `FINAL` on `hits_cold` for the map lookup (pre-existing, out of scope); live for every org after the flip.
  - RN7 L4: `forDispositions` `addslashes()` over a crafted nested filter payload raises a `TypeError` (500); the escaping itself is sound.
- **Security (found checking #469, pre-existing, not this PR):** `POST /nearest-given-address` never checks that `hotlist_id` belongs to the caller's org, so any authenticated Pro user can read another org's given address and a distance. It predates #469 (the old ClickHouse version had it too); after this flip every Pro org's Hits page calls it. A background task was offered to add the org check.
- **Follow-ups (none is a Jira ticket; none created without asking):** background tasks for the `RequiresClickhouse` truncate bug (hardcoded `clarity.*` tables), the `plate_variants` UDF in the `clickhouse-qa` container, and Hits filter analytics (never recorded: `FiltersLayout::Hidden` bypasses the `filtersApplyAction` hook; same for Scans). No task yet for the Missed Opportunities "New Hit" filter.

### Tests

| Command (file, `php artisan test`) | Pass | Fail | Note |
|---|---|---|---|
| Implement t1-t3 (prune era) | 9 / 74 / 9 | 0 / 4 / 0 | 4 Otto failures identical with t2 reverted; 4-mutation check on t3 |
| Regression `Hits\|Hit[A-Z]\|HitTest\|Clickhouse\|Otto\|ScanVehicle\|ListScans` (post-t3) | 821 | 154 | all 154 pre-existing local-env failures in 18 CH-backed classes; CI is the authoritative full run |
| AC-5 FINAL-on-hot change (RN5 run) | 117 | 6 | 4 Otto, `test_all_hit_type_filter`, local ClickHouse error 241 (2 GiB container cap) |
| AC-2 withdrawal + RN6 fixes, local Docker CH, per class after truncating this worktree's test `hits_*` (CI then failed the new cards pin: its plate filter took the flex-search path, which calls the `plate_variants` UDF that CI's ClickHouse schema lacks; the plate filter was dropped from the test, re-mutation-checked) | shape 12, routing 11, row-set 5, cards 6, HitsResourceTest 30, Otto 46 | 0, 0, 0, 0, 1, 2 | new cards `FINAL` pin red with `FINAL` removed, green restored; the 1 is `test_all_hit_type_filter`; Otto 2 = date-only end-date (pre-existing) + a scan-search test (no hits code) |

Pint clean. Shape tests: 12 pass on ClickHouse; on the pgsql bridge the grammar-dependent ones skip.

Pre-existing local failures, with proof:
- **`HitsResourceTest::test_all_hit_type_filter`:** fails 2/2 on untouched `main` at `fd89c4f` in a temporary worktree on the same isolated test DB.
- **Otto date-only end-date and leftover-row failures:** leftover rows in the never-truncated local CH test DB (`RequiresClickhouse::CLICKHOUSE_TABLES_TO_TRUNCATE` hardcodes `clarity.*`, a no-op in per-worktree `clarity_<wt>_test`); identical with t2 reverted. CI uses literal DB names.
- **ClickHouse error 241 in combined runs:** local container 2 GiB cap; classes pass individually.

### Review

| RN | Reason | Lanes | Score | Gate |
|---|---|---|---|---|
| 1 | initial | 3 (claude1/2/3) | 84 / 94 / 94 | PASS 94 via arbitration (lane 1's H1 was the Q2-accepted 2h `stagingOrgIds` lag) |
| 2 | scope | 1 fresh lane | 94 | PASS; M1 correct: `Cache::flexible` left the total-miss path unlocked |
| 3 | qa | 1 lane | 70 | FAIL: QA fix blanked the Disposition cell; `formatState()` pin was a false green |
| 4 | regression | 1 lane | 94 | PASS |
| 5 | rework | 1 lane | 94 | PASS (AC-5 FINAL-on-hot change) |
| 6 | rework | 1 lane | 80 | FAIL: H1 artifacts still described the withdrawn prune; M2 docblock overclaimed `FINAL`; M3 Boost block regenerated into `CLAUDE.md`/`AGENTS.md` (then swept into `d36279343` by the checkpoint and reverted in `88e4c7676`; net diff empty); M5 no pin for cards inheriting `FINAL` |
| 7 | rework | 1 lane | 94 | PASS; M1/M2 (PR body, review bookkeeping) fixed after; M3/M4 and L1-L4 recorded as residual |

All lanes ran as harness `claude -p`. Fixed across rounds: silent-org stampede (RN2 M1, later moot); `intval(...)` key-as-base; `phpunit.xml` pins; plate-scoped, clock-frozen row-set tests; RN3 H1/H2 (cell renders; rendered-HTML pin + PG pin); RN3 M1/M2 (honest `validDisposition` docblock; `actionableDisposition` delegates, SQL identical); RN4 M1/M2/M4; RN5 M1-M3; RN6 H1 (this record, PR body, `state.json`, `prep-context.json`), M2 (docblock), M5 (cards `FINAL` pin), L6 (cold "no FINAL" assertion grammar-guarded), L10 (`is_object` guard). Refuted: staging rows with NULL `integration_id` (0 of 1,086,041 S/Z rows in 30d).

### Acceptance criteria (verbatim from `state.json` `record.ac`)

| AC | Status | Evidence |
|---|---|---|
| AC-1 | SATISFIED | normal user (no impersonation) routes to ClickHouse via config; off → Postgres |
| AC-2 | N/A | WITHDRAWN by Dalton 2026-09-29 after QA: the hotlist_org_id prune (forHotlistOrgs + hotlistOrgIds + silentOrgIds) was built, reviewed and QA'd, then removed. The CH query is scoped by kit_id only, like Postgres. |
| AC-3 | SATISFIED | Staging Live listed on CH by construction (kit_id IN only, like PG); row-set test lists own-kit S/Z hits and an own-kit hit carrying an unrelated hotlist org; cards count S/Z; Otto HitSearchTool on the same builder |
| AC-4 | SATISFIED | no hotlist-org prune, so the CH row set is kit-scoped exactly like PG; every remaining PG-vs-CH difference is measured and documented in the PR matrix (8 rows); the Disposition column renders the CH row's disposition (re-verified: Invalid/New Hit/NULL, 0 blank of 23) |
| AC-5 (revised) | SATISFIED | FINAL on hits_hot only (shape pin + prod check: hot SQL has FINAL, cold none); org 14 today page 1 = 51 distinct of 51 (was 46 of 51 without FINAL) |
| AC-6 | N/A | human/ops gate — prod CLICKHOUSE_HITS_ENABLED=true confirmed by Dalton 2026-09-29, left on |
| AC-7 | SATISFIED | Disposition filter 'New Hit' returns NULL + literal 'New Hit'; 'Invalid' excluded (display bug tracked under AC-4) |
| AC-8 | N/A | ship gate — #469 (DEV-2739 Path A: Given = any geocoded hotlist address) is on main, so #485 merging to main ships with it or later; only a solo hot-fix of #485 to deploy would be unsafe. Revised by Dalton 2026-09-30 |

### QA performed

**Isolated environment.** Worktree `.claude/worktrees/DEV-2614`, site `clarity-claude_dev_2614.test`, Postgres `zod_claude_dev_2614` / `zod_testing_claude_dev_2614`, local Docker ClickHouse (`clarity-qa-clickhouse`) with per-worktree and `_test` databases; the Cloud host copied into `.env` was neutralized to 127.0.0.1. Seeded 4 QA orgs (repo, staging plus `approval_list`, silent, foreign) and 2 personas.

**Agent QA, two runs (prune era).** Run 1 @ `004baa874`: 15 items pass plus a prod `EXPLAIN`; one red found: the Disposition column (AC-4 NOT SATISFIED). Run 2 @ `cead8c001`: 7/7 incl. the Disposition cell (Invalid, New Hit, New Hit; 0 of 23 blank); `qa.verdict: PASS`. Yellows (unchanged): default Today window showed 0 QA rows (seed timing), Status filter has no "All" option, `POST /broadcasting/auth` 403 on first load, hyphenated plates match only by wildcard, "Invalid Scan" vs stored "Invalid" in filter options.

**Production-shaped seed (Dalton's request).** Shape measured read-only from a rank-40 org (~145 hits/day over 120 days, Z 65% / H 22% / S 4% / L 1%, 7 hotlist orgs, 77% foreign): 17,780 hits with a Postgres mirror; default Today view about 28 hits; CH vs direct parity 67/67.

**Final QA mode (Dalton's request).** Branch DB with org 14's config imported read-only (org 14 + 8 staging + 1 silent org, 209 kits with prod ids) and ClickHouse Cloud as `readonly_user` (SELECT-only). 1,109 hits today, 848 under default filters. First login 403 (missing `enable_genetech_hotlist` in the snapshot) fixed.

**Human QA.** Completed by Dalton McCleery 2026-09-29, incl. the pass on prod ClickHouse as org 14.

**What changed because of QA and after it.**
- Disposition-column fix (agent-QA red) and its RN3 correction.
- AC-5 revised to `FINAL` on `hits_hot` after the prod benchmark.
- AC-2 withdrawn after QA (Dalton): the prune and silent-org lookup removed; kit-only scoping re-tested on local Docker ClickHouse (the QA environment had been torn down and was re-provisioned for tests only).

**Side effects.** The local `RequiresClickhouse` trait TRUNCATEs literal `clarity.*` tables, which exist in the shared container (another session's plain DBs); local CH test runs in this worktree truncated them. Pre-existing harness bug; CI unaffected. The Herd site unlink was guard-skipped by teardown (ledger `herd_site` missing `.test`) and done by hand.

### How numbers were derived (aggregates only, read-only)

| Query shape | Params | Aggregate | Decision |
|---|---|---|---|
| `prod-cardinality.sh` hits:kit_id, hotlist:org_id, kits:org_id, approval_list:agent_org_id, hits:hit_type, orgs:silent_hit_generation | identifiers; `zod.public_*` CDC mirror | hits 321.37M (largest kit 7.85M; O 298.6M, Z 13.27M, H 4.70M, S 3.07M, L 0.95M, F/M/A/P/W ~0.72M); hotlist 137.5M; kits 5,669 (largest org 283); approval_list 3,085 (≤12 staging per agent); orgs 728,940 (1 silent) | Sized the envelope; identified the hit-type gap |
| `zod.public_hits FINAL` (7d, L/H/S/Z) ⋈ `public_hotlist FINAL`; PG-only vs CH-only drops | 7d, hit-type constants | 184,433 hits; PG drops 16 (0.009%); CH missing 208 (0.11%) | AC-4 / Q4.6 |
| `clarity.hits_cold` `countIf` disposition NULL / 'New Hit' | disposition constant | 25,518,016 rows: NULL 25,333,927; literal 5,262 (0.02%) | Q4.2: sized the filter gap |
| `hits_cold` `countIf(integration_id IS NULL / = 5)` for S, Z, 30d | 30d | S 95,904 / Z 990,137; 0 NULL, 0 NICB | RN1 premise refuted |
| `public_hits FINAL` (30d) ⋈ kits ⋈ hotlist: hotlist org outside own ∪ staging ∪ silent | 30d | 892,313 hits; 834 dropped (0.09%): S 101, Z 733, 0 L/H | Cost of the prune (withdrawn) |
| `SHOW CREATE TABLE` + `EXPLAIN indexes=1`, with vs without the prune, interleaved | 2 orgs; Today, 30d | sort key leads `hotlist_org_id`; Today 4/4 granules unchanged; 30d cold PK -10% / -14% | Benefit of the prune (withdrawn) |
| silent orgs' hits: `hits_cold`/`hits_hot` by `hotlist_org_id`; `zod.public_hits FINAL` via their hotlists; their hotlists by `integration_id` | all time; 7d | CH: 0 rows; PG mirror: S 560,212 / Z 91,213 / O 568,098 / F 4,944; all recent hotlists `integration_id = 5` (NICB) | Silent orgs contribute nothing to the Hits page; AC-2 withdrawn |
| Hits list and cards, hot (Today, 30d) / cold (120d), with vs without `FINAL`; 5 interleaved reps; `X-ClickHouse-Summary` | org 14 + top org; page-default filters; `readonly_user` | Today +2-4 ms; 30d list +29-51 ms; 120d cold list ~2x time, 3.1x memory (1.14 to 3.60 GB) | Settled AC-5 |
| `uniqExact(id)` / `count()` with vs without `FINAL`; `argMax(hotlist_status, _version)`; first-page ids | org 14 + top org | stale "Open" 0.7% Today, 4.5% 30d, 62% 120d cold; page 1: 46 of 51 distinct (org 14), 39 of 51 (top org) | What `FINAL` buys |
| `count()` vs `uniqExact(id)` on `hits_hot FINAL` | org 14 + top org; Today, 30d | equal in all four samples (e.g. 12,844 = 12,844) | Samples only: `FINAL` cannot collapse ids whose `hit_created_at` moved, so cards keep `uniqExact(id)` |
| `clarity.analytics` `page='hits'`, 60d | no identities | see "Hits usage" | Real usage before shipping |

### FINAL benchmark (prod, read-only, 5 interleaved reps, server-side metrics)

| Org | Window | Surface | Server ms: no FINAL → FINAL | Memory MB: no FINAL → FINAL |
|---|---|---|---|---|
| org 14 | Today (hot) | list | 46.1 → 50.4 | 54 → 58 (1.1x) |
| org 14 | Today (hot) | cards | 33.2 → 34.6 | 19 → 19 (1.0x) |
| org 14 | 30d (hot) | list | 99.8 → 128.7 | 670 → 708 (1.1x) |
| org 14 | 30d (hot) | cards | 60.4 → 68.9 | 192 → 193 (1.0x) |
| org 14 | 120d (cold) | list | 167.1 → 326.6 | 1145 → 3596 (3.1x) |
| org 14 | 120d (cold) | cards | 66.3 → 120.3 | 415 → 985 (2.4x) |
| top org | Today (hot) | list | 46.5 → 48.8 | 55 → 57 (1.1x) |
| top org | 30d (hot) | list | 98.2 → 149.2 | 637 → 697 (1.1x) |
| top org | 120d (cold) | list | 191.8 → 362.0 | 1128 → 3668 (3.3x) |

Measured with the prune in the query; removing it changes the ClickHouse work only by the modest PK-granule difference above.

### Hits usage (prod analytics, 60 days, aggregates only)

- Page views: 36,791 across 363 users and 198 orgs. Views per user p50 = 9, p90 = 226; 17 users with 500+ views account for 56% of views. Top 10 orgs = 58% of views.
- Distance From Given (Pro): 7,495 events, 159 users, 54 orgs (the exposure behind the AC-8 gate).
- Weekdays 83% of views; peak 9am-3pm Central.
- Filters: zero filter-applied events ever recorded (`FiltersLayout::Hidden` bypasses the hook); a follow-up task is running.

### PG-vs-CH difference matrix

With `CLICKHOUSE_HITS_ENABLED=true` every org reads the ClickHouse path. Both paths scope by `kit_id IN (user's kits)`. Known, measured differences:

| # | Difference | Size | Decision |
|---|---|---|---|
| 1 | Hit types F/M/A/P/W exist only on PG (the CH MV keeps L/H/Z/S) | ~725k PG rows total | accepted: not wanted on the Hits list (Q4.1) |
| 2 | Stale status versions + duplicate rows (ReplacingMergeTree, unmerged): `FINAL` on `hits_hot` (≤90-day windows) collapses status re-emissions; `hits_cold` has no `FINAL`; ids whose `hit_created_at` moved survive `FINAL` on either table | without FINAL: 0.7% stale today / 4.5% 30d / 62% 120d; page 1 had 5-12 duplicate rows of 51 | **fixed on hot** (AC-5 revised); cold accepted (~3.1x memory) |
| 3 | Order status: PG live vs CH copy updated by the MV (~1 min lag) | minutes | accepted (Q4.4) |
| 4 | Joins: PG drops hits with NULL lienholder / missing scans_metadata; CH drops hits whose scan/order has not synced | PG-only 16 (0.009%) · CH-only 208 (0.11%) of 184,433 / 7d | accepted (Q4.6) |
| 5 | Distance From Given / Nearest Given Address: PG computes it inline from any geocoded hotlist address; CH has no distance columns, so each cell calls `NearestGivenAddressController`, which read `recovery_address` `type_id=2` (83% vs 1.7% of rows resolve) until #469 moved it to the same Postgres `hotlist_address ⋈ address` source | same source once #469 is live; lookup is per row, indexed on `(hotlist_id, address_id)`, browser-cached | **resolved by #469**; ship in the same release or later (AC-8) |
| 6 | "New Hit" disposition filter: CH used to drop the literal `'New Hit'` | 5,262 / 25.5M CH rows | **fixed in this PR** (AC-7) |
| 7 | Disposition column always rendered "New Hit" on CH | every CH row | **fixed in this PR** (found by agent QA) |
| 8 | Disposition freshness: PG reads `scans_metadata.meta` live; CH's `disposition` is updated by the queued `SyncDispositionToClickhouse` `ALTER … UPDATE` | seconds to minutes; never if the job fails | documented (RN4) |
