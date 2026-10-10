# Change Log

Append-only chronological history of this wiki. Each entry uses the prefix form `## [YYYY-MM-DD] <op> | <subject>` so it is parseable with simple unix tools, e.g. `grep "^## \[" log.md | tail -n 10`.

Operations: `ingest`, `update`, `lint`, `query`, `sync`.
## [2026-10-02] update | Violent Debtor page rewritten as the topic; raw JSON blob locked.

Rewrote `concepts/rdn-violent-debtor.md` so it teaches the record, not the ticket commentary. A violent debtor is an RDN-pushed record Clarity keeps so a VIN can be flagged. Locked storage in PostgreSQL `zod.public` (ticket name like `violent_vin`; table still absent from this repo; platform writes it; ADR-004 exception; not `laravel-zod-migrations`): plaintext unique VIN, Laravel-encrypted first and last name in separate columns, and a **raw JSON blob of the full payload** beside those columns. Display starts flag-only on hotlist, hit, and scan. Webhook auth is an environment-matched GUID or comparable string, rotated by changing the env value. Implementation still waits on the DEV-2742 push contract. Future level/threat display, own columns for `violence_level` / `notes` / `created_at`, and the exact URL template stay **UNPROVEN**. Catalog blurbs and the `rdn.md` See also pointer match the topic page.

## [2026-10-02] ingest | DEV-2893 Violent Debtor Q2 lock (VIN plaintext + encrypted names; blob open).

Question Gate for the RDN Violent Debtor receive endpoint. Source: First Glance comment [42726](https://insightlpr-team.atlassian.net/browse/DEV-2893?focusedCommentId=42726) (supersedes [42545](https://insightlpr-team.atlassian.net/browse/DEV-2893?focusedCommentId=42545)) plus the DEV-2893 description. Wiki-only; no app code.

- **New** `concepts/rdn-violent-debtor.md`. **Q1** unchanged: platform-only writes into PostgreSQL `zod.public` (ticket name like `violent_vin` — table does not exist in this repo); do not touch `laravel-zod-migrations`; ADR-004 fight accepted. **Q2** amended 2026-10-02 ~11:14am America/Chicago: VIN plaintext unique key (lookup-by-VIN) + Laravel-encrypt first/last names; display flag-only. Supersedes the 2026-09-30 "VIN + raw JSON blob" lock. **Q3** unchanged: Hold / park until the DEV-2742 push contract is written. Branch path main→main when unblocked. Auth is a GUID path segment only.
- **UNPROVEN / open:** blob strategy — raw vs encrypted whole payload vs names-extracted beside VIN. No choice recorded.
- Out of scope: Patroller UI (DEV-2741), RDN outbound client. Parent epic DEV-2740.
- Cross-link from `concepts/rdn.md` See also. Catalog rows in `index.md` and `concepts/index.md`.

## [2026-09-25] ingest | DEV-2739 Hits Distance from Given / Nearest Given Address N/A contract.

Documented when the Hits columns show a value vs **N/A**, without inventing a root cause for the Brad Webb all-N/A demo.

- **New** `concepts/hit-address-distance.md`: feature `HIT_ADDRESS_DISTANCE`; Path A = `ListHits::buildPostgresQuery` over PostgreSQL `zod.public.hotlist_address` ⋈ `address` (`geo IS NOT NULL`); Path B = blades → `NearestGivenAddressController` over ClickHouse `clarity.recovery_address` (PG MV = `type_id = 2` only). Admin impersonation forces the CH hit list (no distance select) → Path B. Explicit **UNPROVEN**: Brad Webb/DEV-2739 root cause; product-canonical source between the two paths; Path A missing `(0,0)` exclusion; `'clickhouse:hits'` vs Pennant slug mismatch.
- Cross-links: `hit.md`, `hotlist.md`, `dto-and-enums.md`, `feature-flags.md`; catalog entries in `index.md` + `concepts/index.md`.
- Sources: DEV-2739 / DEV-1611 Jira; `HitsResource`, `ListHits`, `NearestGivenAddressController`, blades, `FeatureEnum`/`ProTierEnum`/`PennantServiceProvider`; package MV `public.recovery_address`. Out of scope: DEV-2736, DEV-2738.

## [2026-09-24] update | LPR Assist: accept the view without FINAL (DEV-2429).

`concepts/lpr-assist.md` now states that ClickHouse `clarity.lpr_assists` has **no `FINAL`** on `clarity.recovery_scans`. The Client Analytics distinct-recovery metric is **best-effort**, with latent tombstone and ReplacingMergeTree merge-lag risk. Prod measure 2026-09-24 (Cloud read-only): **delta 0**, `view_uniq` = `final_uniq` = **269429**. That count agrees today with FINAL-on-junction admin reads (`RecoveryLeaderQuery`; the card-cited repossession-count comparator class is not on main) and **differs by design** after the accept disposition. Do not add `FINAL` to the view or `LprAssist` without a new ticket. Model page `models/clickhouse/lpr_assists.md` cross-links the same section. Measure artifacts: `docs/investigations/DEV-2429/`.

## [2026-09-23] ingest | Insight LPR company identity (DEV-2695)

Insight LPR is the company that owns Platform and Clarity. Public reference: https://insightlpr.com. Marketing on that site is not a product requirement. Customer wording Otto loads is `resources/otto-knowledge/global/who-is-insight-lpr.md`. Engineering page: `concepts/insight-lpr.md`.

## [2026-09-21] update | Merge main into otto: keep published PG-030–PG-032, renumber Otto gotchas

`origin/main` had already published PG-030 (`join_use_nulls`), PG-031 (`ST_Union` drops NULL), and PG-032 (`spatial_ref_sys` test lock). The Otto branch had used PG-030–PG-032 for hot/cold windows, completion-signal release, and dual `stripToolCalls()` renderers. Those Otto entries are now PG-034, PG-035, and PG-036. The `/broadcasting/auth` catch-all, previously cited as PG-022 (which is the GPS-bounding gotcha), is PG-033. Zone ZIP refusal lives on `PersistZone`, shared by `ManageZones::save()` and Otto accept.

## [2026-09-01] update | Kit: the "GL" naming rule is false; kit ownership is only fixed in the app; `zod.kit_last_scan` documented.
## [2026-08-17] update | Otto conversation ownership + widget/detail contracts

`Chat::$conversationId` is locked; send/stop/poll and `RunOttoChat` require the conversation to belong to the acting user. Staff impersonation without `use-otto` is documented as intentional QA. Investigation rows use `searchIds`; detail events use `result_type` / `result_id`. Updated `concepts/otto.md`.

## [2026-08-12] update | Merge main: keep PG-021/PG-022, GPS bounding becomes PG-023

Corrections landed after DEV-2448, all measured read-only against production ClickHouse on 2026-08-31 (query shapes + aggregates only).

**The `GL` naming rule was wrong.** `concepts/kit.md` said `kit_type = 1` kits' names "usually start with `GL`". Only **2 of 3,535** do — while 13 `kit_type = 2` kits do. What "GL" actually names is `kits.kit_mapping`, labelled "GL Number" in the UI (`ScoutUsersResource`) and the key `clarity.sc_positions` is keyed by. The filter is still `kit_type = 1` (`KitType::GENETEC`), now justified by the real evidence: it is the **only** kit type appearing in `clarity.sc_positions` at all (all 540 kits reporting GPS in a 14-day window). Never use a `name LIKE 'GL%'` pattern.

**"Ownership is fixed" was over-stated.** The app offers no ownership transfer, and that is unchanged — but `concepts/kit.md`, `multi-tenancy.md` and `scan.md` stated it as an unconditional fact. Derived ClickHouse tables retain historical org assignments: `zod.kit_last_scan` is keyed `(org_id, kit_id)` and **275 of 4,755 kits carry rows under more than one `org_id`** (worst case 3), where the stale row can be the newer one. The rule is now scoped to the app, with the CH caveat stated.

**`KitUsersResource.php` still did not exist.** The [2026-06-26] entry below records replacing this reference in `kit.md`, but the page still carried it at `concepts/kit.md` "Where it lives in code", and `filament-conventions.md` still listed it among key admin resources. Both fixed, and both now name the resources that do exist (`ScoutUsersResource`, and `GlKitsResource` from DEV-2448). Worth noting the failure mode: a logged correction is not evidence the page was corrected.

**New in `clickhouse-integration.md`:** a section on `zod.kit_last_scan` — the incrementally-maintained last-scan-per-kit table. Measured **0.23 s / 0.25 MB** per org versus **1.61 s / 2.16 B rows / 9,586 MB** for the equivalent `scans_cold` aggregate, and verified exact for active kits (1,571/1,571 within 1 s). Documents its three constraints: un-merged `ReplacingMergeTree` (needs `max()`), the multi-org stale-row trap, and that it is chlocal-owned with platform as its first consumer.

**Not fixed here, needs its own ticket:** `database/schema/clickhouse-schema.sql` misstates the sort key of `clarity.scans_cold`/`scans_hot` as `(kit_id, scanned_at, id)`; production is `(org_id, scanned_at, kit_id, id)`. That stale dump misled all three DEV-2448 prep candidates into a design that would have scanned ~9.4 GB per page load.
## [2026-09-01] update | Otto branch: turn lifecycle, page actions, investigation hydration, Scout bounds (PG-029…031)

Ingested the parts of the `otto` branch the wiki did not yet describe, plus nine correctness fixes from a review of PR #333. Code changes are on the branch and **not merged** — this pass was written at the user's explicit request ahead of the normal post-approval gate, so treat the page as describing the branch, not production.

- **`concepts/otto.md` — four new sections.**
  - **Turn lifecycle.** The three cache keys (`otto:run` lock TTL 210s, `otto:pending_prompt`, `otto:stop`) and why `turnCache()` forces redis when `CACHE_STORE=file`. The **ordering contract**: `pollTurn()` reads the absence of the pending key as "complete" and re-renders the thread from the database, so the key is released *after* the investigation, `meta['page_actions']`, and the title are written. Completion is measured against `Chat::$turnBaselineAssistantCount`, not "any assistant message". Assistant rows are always resolved by `id` (`Str::uuid7()` in `laravel/ai`'s `DatabaseConversationStore`), never second-granular `created_at`.
  - **Page actions.** propose → Accept → adapter applies. `ProposesPageActions` broadcasts `OttoPageActionProposed` and returns a string forbidding "applied" claims *and* restating the changes; `RunOttoChat::persistProposedPageActions()` puts them on the newest assistant row so the card survives refresh; `InteractsWithOttoPageActions` + `AppliesOttoPageFilters` are the adapter side, with `ottoPageActionAuthorized()` as the real authorization boundary. `DefersToPageSearch` has **no model-controlled escape** — only `Chat::send()`'s server-gated strips. New rule: an unresolvable client or kit **aborts** the proposal (`$filterResolutionError`) instead of vanishing from it, because dropping it widens the search the user asked for and the model is told not to enumerate the changes.
  - **Investigations and the results pane.** `platform.agent_investigations`, ids-only recording capped at `SEARCH_LIMIT` (50) per search, and `GET /otto/investigation/{conversationId}` hydration through `Scan::forOrg` / `HitsListQuery` / `Hotlist::visibleToOrg`.
  - **Scout driver locations.** The Bird's-Eye soft gate, and the two bounds that must stay: `0.0,0.0` positions dropped **before** they are counted, and the 8-driver cap applied on the identity path too (geocoding is one blocking round-trip per distinct location against `timeout = 180` / `tries = 1`).
- **New `platform-gotchas.md` PG-029** — a hot/cold table split driven by a caller-supplied window silently loses rows. Hot is a 90-day TTL, cold is all history, and `now()` is the one window value that means the opposite of what it looks like: it always resolves to hot. `InvestigationDataController` hydrated `hits_cold` ids against `hits_hot`, so the chat reported N hits over an empty table and map. Fix: pass the originating search's window, or `null` for "no window".
- **New PG-030** — releasing a completion-signal cache key before the state it guards is written. A key doing double duty as mutex and "work finished" signal must be released last; the `finally` release is a backstop, not the primary path. Corollary: "some result exists" is not "my result exists" — capture a watermark at dispatch.
- **New PG-031** — two renderers of the same text drift, and only one is ever watched. `stripToolCalls()` exists in both `otto-stream.js` and `MessageRenderer`; three patterns had accumulated on the JS side only. `Str::markdown(html_input: 'strip')` drops tags but keeps inner text, so it is not a substitute.
- Refreshed the `otto.md` and `platform-gotchas.md` rows in `index.md` / `concepts/index.md`; catalog range is now PG-001…PG-031.

## [2026-08-21] update | LPR Assist time window: exact calendar bounds, not “shortly after.”

Expanded `concepts/lpr-assist.md` with a **Time window (exact)** section from Cloud DDL (`aggregate_recovery_kit_scans_backfill`): `scanned_at ∈ [toDateTime(repo_date) − 90d, toDateTime(repo_date) + 1d]`. Documented that `+ 1 day` means “include the whole repo calendar day” (midnight end), with a worked example. Separated (1) that 90-day scan window from (2) hotlist `lookback_days` (historical hits, often 270 — **not** used by the assist MV) and (3) the job’s `repo_date >= now() − 1 month` processing scope. Corrected `models/recoveries.md` which had claimed `lookback_days` was the LPR-assist attribution window.

## [2026-08-21] ingest | LPR Assist domain page (product + engineering).

Added `concepts/lpr-assist.md`: what an LPR assist means (one recovery credited when LPR scanned the plate within ~0.1 mi / 162 m of the repo site), what it is not (not scan counts, not missed opportunities, not PG writes), attribution via `clarity.recovery_scans` → view `clarity.lpr_assists`, and dashboard counting via `LprAssist` (`count(DISTINCT recovery_id)` on `repo_date`). Wired into `index.md` / `concepts/index.md`, cross-linked from recovery / client-analytics / data-architecture / models `lpr_assists`. Corrected stale `clarity.lpr_assist` singular naming to `clarity.lpr_assists`.

## [2026-08-19] update | DEV-2386: 36–209 min recorder cycles are incident history, not current matcher time.

The 2026-08-12 104-min averages were the PHP `values()` chunk storm. After DEV-2342 W2+W3 + `clarity.scout_open_addresses`, do not size lookback/slots from those numbers. Cloud rewrite p50 was ~113 ms/kit (org 14, 2026-08-18). `clarity.scout_open_addresses` is live in ClickHouse Cloud (DEV-2383); local chlocal `main`/`cloud_export` is not the proof of Cloud.

## [2026-08-19] update | DEV-2386: recorder does not probe scout_open_addresses freshness.

Platform no longer `SELECT max(refreshed_at)` or skip cycles on a stale/empty book. Rebuild of `clarity.scout_open_addresses` is chlocal-owned and out of this repo's scope. Dropped `book_freshness_minutes`. Empty book → SUCCESS with zero matches.

## [2026-08-19] update | DEV-2386 sibling pages: check-in tile reads scout_open_addresses; schedule is live.

Aligned the catalog and the dashboard-stats page with the shipped matcher/rollup (DEV-2386). `ScoutCheckInRollupQuery` no longer joins `clarity.hotlist FINAL` → `zod.public_hotlist_address` → `zod.public_address`; it reads `clarity.scout_open_addresses`. Heatmaps still use the zod CDC pair — that path is unchanged. Catalog blurbs in `index.md` and `concepts/index.md` no longer say the recorder schedule is disabled pending W1/W4.
## [2026-08-14] update | Otto hotlist assignment search (DEV-2319)

Otto can search hotlist assignments (wanted vehicles) via `HotlistSearchTool`, reusing ClickHouse `Hotlist` scopes. New `visibleToOrg()` composes `forOrg` + staged rows from `Org::stagingOrgIds()`. Distinct from hit/match search; no default date window. Updated `concepts/otto.md`.

## [2026-08-13] update | Production OOM post-incident ingest: 5 new gotchas (PG-023…PG-027) + 5 disproved claims corrected on the recorder page.

`scout:record-check-ins` exhausted memory on the production host on 2026-08-12; the box was rebooted and the command has been disabled since (DEV-2342). An evidence audit of the incident report — and separate research into DEV-2309 — disproved several claims **that were already written into this wiki**, so this pass is as much correction as addition.

- **New `platform-gotchas.md` PG-023** — a scheduled command whose runtime exceeds its cadence piles up processes. `withoutOverlapping(N)` is a lock *expiry*, not a duration cap; both directions are traps (explicit small N ⇒ pile-up, measured peak **17 concurrent** holding ~29 GB of a 68 GB host; **no argument** ⇒ Laravel's 1440-minute default ⇒ a hard-killed run strands the mutex for a day, which two entries in `routes/console.php` currently do).
- **New PG-024** — wrapping a ClickHouse `ORDER BY` prefix in a function disables primary-key pruning. `lower(kit_mapping)` against `clarity.sc_positions` measured **3056/32732 granules vs 13/32732** exact-case (~600 ms vs ~20 ms). Records that an ALIAS column does not help and ORDER BY is immutable, so a projection is the fix; **nine** call sites share the shape (verified by grep — the original note listed seven), and the DDL is chlocal-owned.
- **New PG-025** — an unguarded loop lets one bad row abort the whole command, and console-only output hides it. `CloseInactiveShiftsCommand` aborts on the first failing shift, so its logs only ever name one kit (which turned out to be the 187th largest, not an outlier — the real cause was an unindexed `max()` over a 4.94-billion-row table). Adds the observability corollary: `$this->info()` in a `runInBackground()` command reaches nothing.
- **New PG-026** — do not round-trip a join through PHP when both sides live in the same database. The recorder exports an org's address list from ClickHouse and re-injects it as `values(...)` literals per kit (~5,500 queries/cycle, memory `O(input)`), when `ScoutCheckInRollupQuery` already computes the same match server-side and its docblock offers the CTE for reuse.
- **`scout-check-in-recorder.md` — status banner + 5 corrections.** Marked **disabled in production**. Withdrew *"the concurrency is load-bearing"* (measured against throughput only; the same concurrency held ~29 GB and the host was rebooted — this reasoning was cited when the lock fix was reverted in `102a3430`). Corrected ~7–8 concurrent → **17** (Little's-law estimate superseded by interval reconstruction of 58 cycles), *"a plateau, not a runaway"* → 104 → 161 min at day scale with crash-day cycles at 63–209 min, and *"the other ~97% is unaccounted for"* → answered by the PG-024 chunk storm. Flagged the `overrun_ms` 150-minute default as already-stale calibration. Added the measured memory shape.
- **New PG-027** — PHP memory is sized by production cardinality, not by fixtures. Ties the three measured cases together (~1.5 KB/GPS point, `O(addresses_per_org)`, × concurrent processes) and gives the pre-flight discipline: measure the **largest group** via the `zod.public_*` CDC replicas before writing the loop, compute `rows × per-row × processes` against the **128M** CLI limit, and prefer server-side aggregation over materializing. Anchors: `zod.public_scans` **4,944,026,220 rows / 4,799 kits** (median 357,159), vs a 3-address volume test against a production chunk size of 2000.
- **`scout-clickhouse-queries.md`** — the page explained *why* every read wraps `lower(kit_mapping)` (DEV-2012 casing mismatch) but never that it **defeats the primary key**. Added the granule measurement and a pointer to PG-024; the `lower()` is correct, the performance is the trap.
- **Drift fix:** `zod.public_hotlist_address` re-measured at **318M** (page said ~311M). `clarity.sc_positions` verified at **270,972,394**, confirming the ~268M figure used in the DEV-2342 analysis.
- Refreshed the `platform-gotchas.md` catalog rows in `index.md` / `concepts/index.md`. **No `app/` code changed.** The DEV-2342 fix architecture is deliberately **not** ingested — it has not shipped, and writing an unshipped design into this wiki is what produced the never-shipped 90-minute lock claim this pass had to correct.

## [2026-08-13] update | Merged `main` into `claude/DEV-2342` — reconciled recorder rewrite with the PG-023…027 ingest.

`main`'s post-incident wiki pass (PG-023…027 + corrected recorder claims) landed while this branch had already rewritten the recorder page for the shipped W2+W3 code. Merge kept the DEV-2342 architecture as current and folded the evidence audit into it.

- **`scout-check-in-recorder.md`** — kept the server-side CTE / N-slot / schedule-disabled description. Folded in 318M `public_hotlist_address`, peak **17** (not 7–8), duration **104 → 161 / crash-day 63–209**, stale 150-min `overrun_ms`, hung 10–11 processes on crash day, and PG-023/024/026 pointers. Heartbeat now exists (AC4); the "no in-flight signal" note is scoped to overrun, not the slot lease.
- **`platform-gotchas.md` PG-023/026/027** — PG-026 cause is past tense (DEV-2342 lifted the CTE); PG-023 fix names the N-slot semaphore; PG-027 notes AC6's 20k `numbers()` seed vs the old 3-address fixture.
- **`routes/console.php`** — still commented; comment cites the .142 OOM and W1/W4 gate (dropped the informal main-only disable note).
- The earlier "fix architecture is deliberately not ingested" line above is superseded by this merge — the code is on this branch.

## [2026-08-12] update | Otto one-Enter one-bubble send path

`Chat::send()` no-ops while `otto:pending_prompt:{id}` is set. `OttoUserMessagePosted` is `->toOthers()` (other tabs); `otto-stream.js` also ignores Echo of the sender’s own prompt because Livewire often omits `X-Socket-ID`. Hang-net `pollTurn` injects assistant HTML only when a live user bubble already exists. Updated `concepts/otto.md`.

## [2026-08-12] update | PG-022 catch-all after withBroadcasting; QA login 404

`withBroadcasting(..., web)` registers `Broadcast::routes()` in `booted`. The `{any}` → `/app/login` catch-all moved to a *later* `AppServiceProvider` booted callback so it cannot steal `/broadcasting/auth` or `/__qa/login`. Local QA login 404 is `abort_unless` when `platform.users` has no row for the requested id (not a missing route). Updated `platform-gotchas.md`, `concepts/otto.md`.

## [2026-08-12] update | Otto Thinking hang (PG-022 catch-all vs /broadcasting/auth)

`POST /broadcasting/auth` was swallowed by the web `{any}` → `/app/login` catch-all because `withBroadcasting()` registers too late. Echo never joined `otto-thread.*`, so a finished `RunOttoChat` left the UI on "Thinking…". Documented PG-022; restored missing PG-021 (PHP 8.3) in the gotchas catalog. Session `Broadcast::routes()` now lives in `web.php` before the catch-all; `Chat::pollTurn()` is the Livewire hang-net. Updated `concepts/otto.md`, `platform-gotchas.md`.

## [2026-08-12] update | Horizon otto pending (PG-011 stop-when-empty-for)

Documented that Laravel 12.66+ `queue:work` reads `--stop-when-empty-for` while Horizon 5.46's `horizon:work` omits it, so workers crash on boot and `{otto}` jobs sit pending while supervisors look running. Shim is `App\Horizon\WorkCommand`. Redis `retry_after` is 300s (must exceed Otto's 180s timeout). Updated PG-011, jobs-and-queues, otto concept.

## [2026-08-12] update | Otto Echo ws:// (pusher-js HTTPS forceTLS ignore)

Documented that pusher-js 8 `shouldUseTLS()` returns true on `https:` pages and ignores `forceTLS:false`. Local Filament Echo in `resources/js/app.js` now hard-codes `forceTLS: false` / `enabledTransports: ['ws']` and patches `Pusher.Runtime.getProtocol` so the browser opens `ws://localhost:6001` from `https://platform.test`.

## [2026-08-12] update | Otto realtime + stack (concepts/otto.md)

Expanded the Otto concept page from a KB pointer to current product reality: admin `/app` only; laravel/ai + Bedrock IAM SigV4 (never IAM in laravel/ai `key`); local Soketi via `ws://localhost:6001` (not wss; do not nginx-proxy `/app/`); dual Echo clients (`resources/js/app.js` vs Scout); Horizon `{otto}` + redis queue; customer KB vs engineering okf; pointer to `docs/plans/otto.md`. Linked from `concepts/index.md`; refreshed `index.md` catalog row.

## [2026-08-11] ingest | Otto customer-KB pointer (concepts/otto.md)

Added a light concept page noting that Otto’s customer-facing knowledge lives under `resources/otto-knowledge/` and is separate from engineering `okf/`. Dense AI reference: `docs/plans/otto.md`.

## [2026-07-10] ingest | Scout Bird's Eye + ClickHouse query reference (2 new pages) + real-time flesh-out + drift fix.

Code-verified deep-dive (three parallel extraction passes over the current post-DEV-2164-revert `main` tree) of Bird's Eye, the real-time/polling layer, and every Scout ClickHouse query.

- **New page `scout-clickhouse-queries.md`** (Pattern): full SQL reference — the base class (`report()`+return-`[]` on throw in prod/testing, cache TTL + 3s impersonation, underscore-key exclusion, `polygonLiteral`, `clarityDb`) and the `DB::clickhouse()` macro, then each class with tables/DB, `FINAL`, verbatim SELECT/WHERE/GROUP BY, org scoping, geohash/dedup bucketing, and the load-bearing gotchas: `KitScanPathsQuery`, `KitHistoricPositionsQuery`, `OrgLivePositionsQuery`, `OrgLivePathsQuery`, the inline `kitPosition()` 3-tier fallback + `livePath()` cursor, `HitDirectHotspotQuery`, `HotlistAddressGeohashQuery`, `ScoutCheckInRollupQuery`; plus a Scout-vs-non-Scout usage matrix.
- **New page `scout-birds-eye.md`** (Concept): the admin org-map backend — access/routing (`ClarityScoutAdminMiddleware`, catch-all ordering), every endpoint + backing query, `scopedKits()` kit_ids intersection, the Kit(zod)/KitSettings(platform) cross-connection enrollment lookup, per-driver trail cursors, and a driver-vs-Bird's-Eye differences table (run-area vs zone clip, org-wide heatmaps, `trip_count`=0, no Echo). Frontend adminMode deferred to `scout-frontend.md`.
- **`scout-real-time-tracking.md`** fleshed out: the 10s chained-`setTimeout` poll loop (per-tick behavior, hits every 12th tick), cursor-based live-path, `useBirdsEyePolling`; **drift fix** — the "Geohash heatmaps" subsection wrongly credited the Scout honey-hole heatmap to `HitDensityGeohashQuery` (5-tier) and implied client-supplied precision; corrected to `HitDirectHotspotQuery` (3-tier, current-status semi-join) with precision **forced to 7** in prod, and `HitDensityGeohashQuery` labeled the non-Scout `KitUserPathController` heatmap. Client-merge/persistence + service-worker detail pointed at `scout-frontend.md` / `scout-pwa-and-offline.md` rather than duplicated.
- **`scout-dashboard-stats.md`:** noted DEV-2138 (Repos tile pulled from the UI on all breakpoints; backend `repos_affected` still computed) and cross-linked the new query reference.
- Cross-linked the two new pages from `scout.md`, `scout-frontend.md`, `scout-real-time-tracking.md`, `scout-dashboard-stats.md`; added catalog rows to `index.md` (restoring the dropped `scout-dashboard-stats` row) and `concepts/index.md`. No `app/` code changed.

## [2026-07-09] update | Fleshed out Scout shifts; demoted Trips to parked.

Substantially expanded `scout-shifts-and-trips.md` from a code-verified deep-dive of the shift machinery (`EnsureOpenShift`, `KitShift` + migration, `ClarityScoutShiftController`, `CloseInactiveShiftsCommand` + `routes/console.php`, `SendEndOfShiftSummary`, `BuildShiftSummary`, the `ShiftSummaryDto`/`ZoneSummaryDto`, `EndOfShiftSummary` mailable + Blade, `MapboxStaticMapBuilder`).

- Added depth on: lazy materialization (no scan trigger — opened on-demand by `currentOpenForKit` status poll / `forKit`); the "multiple shifts per day" 180-min gap rule and `inferStartedAt` gap-walking; the partial-unique-index INSERT-race handling; confirmed-vs-assumed close matrix.
- **Automation 1** — the `scout:close-inactive-shifts` sweep: every-30-min schedule, double flag-gate, `withoutOverlapping`/`runInBackground`/`onOneServer`, latest-activity = max(last Postgres scan, last `sc_positions` GPS), zero-activity closed at `started_at`, `--dry-run`.
- **Automation 2** — the `SendEndOfShiftSummary` queued job (`{low}`, tries 5, `lockForUpdate` idempotency via `summary_sent_at`, `no_recipients`/`mail_failed` skip reasons) and full email contents (subject format, green/amber banner, stats panel, hotlinked token-gated Mapbox map, per-zone table); recipients = driver **To** + admin **CC** with permission-team save/restore, driver-missing → admins-only.
- Documented the summary metric sources (scans `scans_hot/cold FINAL`; hits `hits_cold` by `kit_org_id`, `hit_type='L'`; miles via `KitHistoricPositionsQuery`+`HistoricPathBuilder`; per-zone via **PostGIS `ST_Contains`**, time-in-zone capped 30 min) and the analytics-vs-audit split under impersonation.
- **Drift fix:** the old page wrongly attributed the per-zone shift roll-up to `ScoutCheckInRollupQuery` — corrected to the PostGIS query in `BuildShiftSummary` (`ScoutCheckInRollupQuery` powers the dashboard check-in tile, not the shift email).
- Trips demoted to a short "parked — feature-gated" section (still in code behind `CLARITY_SCOUT_TRIPS`, 90-day `kit_trips` TTL). Updated both catalogs. No `app/` code changed.

## [2026-07-09] ingest | Scout dashboard stats & map counting (new page) + drift fixes.

Ingested from a code-verified deep-dive of the Scout counting surfaces (`ClarityScoutDashboardController`, `ClarityScoutMapController` + `Concerns/ScoutMapHelpers`, and `app/Clickhouse/Queries/{HitDirectHotspot,HotlistAddressGeohash,ScoutCheckInRollup}Query`, `ClickhouseQuery` base). Every fact re-verified against code with file:line before writing.

- **New page `scout-dashboard-stats.md`** (Pattern): fills the gap where the wiki listed the query *classes* but never documented how the numbers are produced. Covers: `kit_org_id` vs `hotlist_org_id` scoping; the five stat tiles and their exact aggregates (scans `FINAL`; direct/staging `uniqExactIf((ocr, toStartOfHour(...)))` = one per (plate,hour); **repos = `uniqExactIf(hotlist_id, hotlist_status = Repossessed AND L)` — distinct repossessed *orders*, un-deduped**; trips flag-gated); the cache/TTL ladder (today 10s … last_60 15m, ~3s impersonating); **current-vs-frozen hotlist status** (markers DEV-2113 / heatmaps / check-ins re-resolve current; recent-hits list still frozen = DEV-2117); heatmap **geohash precision forced to 7 in production** via `resolveDebugPrecision()` (the query-class `?? 6` never reached in prod; `DEDUP_PRECISION = 8` is an internal ~38m bucket); order density = live `count(DISTINCT lat,lng)` (the precision-6 MV was retired) with completeness/bad-address filters and `a.id IN (...)`-not-JOIN; check-in rollup at `geoDistance <= 160.934` m; the Postgres-only `hotlist-stats` surface.
- **Correction** in `scout-real-time-tracking.md`: the query-class table lumped `HitDirectHotspotQuery / HitDensityGeohashQuery` together — clarified that `HitDensityGeohashQuery` is the *non-Scout* repo heatmap (`KitUserPathController`); Scout uses `HitDirectHotspotQuery`. Added a pointer to the new counting page.
- Added a **frozen-`hotlist_status`** business rule + cross-link to `hit.md`.
- Cross-linked the new page from `scout.md` (hub + See also) and updated both catalogs (`index.md`, `concepts/index.md`).
- No `app/` code changed.

## [2026-07-06] update | Drift reconciliation from `evaluate wiki against code` (full sweep): 4 stale claims fixed, Scout post-2026-06-30 changes ingested, PHP-version gotcha added.

Full-wiki drift audit (4 parallel code-verification passes + mechanical lint, which passed clean). Fixes:

- `hit.md`: removed the phantom `hits.org_id` column claim — there is no `org_id` on `zod.hits`; org ownership is derived via `hotlist.org_id` (`Hit::scopeForOrg()` joins `hotlist`).
- `recovery.md`: "Where it lives in code" said `zod` connection, contradicting the (correct) body — `Recoveries` uses the default `platform` connection (`platform.recoveries`), no `$connection` override.
- `kit.md`: `KitUsersResource.php` does not exist — replaced with the real surfaces (`Pages/KitUserPath`, `Pages/KitUserRecoveries`, `Widgets/Dashboard/KitUsers`, `Tables/Filters/KitUsersFilter`).
- `dto-and-enums.md`: `FeatureEnum` table listed nonexistent `SCANNER_PORTAL`; replaced with `CLARITY_SCOUT` / `CLARITY_SCOUT_TRIPS` (matching `app/Enums/FeatureEnum.php`).
- `scout-api.md`: added `POST /api/scout/track` (DEV-2130) to the Driver API table; documented the `range` param + `hit_type` (`L`/`S`) payload field on driver and admin `dashboard/stats` + `dashboard/recent-hits` (DEV-2137); noted `repos_affected` still returned but unrendered (DEV-2138).
- `scout-real-time-tracking.md`: Bird's Eye now shows the date-scoped Live Hits Pending Repo list with Direct/Staging pills (DEV-2137); Repos stat hidden in UI (DEV-2138).
- `scout-user-assignment.md` / `scout-shifts-and-trips.md`: documented DEV-2130 audit logging (`LogsScoutActivity` concern → `activity('scout-user')`; `activity('scout-shift')` from `ClarityScoutShiftController`).
- `platform-gotchas.md`: added **PG-021** — production runs PHP 8.3 while local/CI run newer; keep app code 8.3-compatible (composer platform pin `8.3.99` guards deps, not syntax).
- Outside `okf/` (drift in agent docs, not wiki): `CLAUDE.md` said "Filament v4 (recently upgraded from v3)" — corrected to v5 with manifests-are-authoritative note, plus the PHP 8.3 constraint.

Known gaps deferred (not authored in this pass, flagged in the audit): RDN integration, invites & kit sharing lifecycle, ClearPlan integration, impersonation/force-login, notification channels, activity-logging overview, geospatial services, exporters, subscriptions/ProTier.


## [2026-06-30] ingest | Scout (zones, user assignment, real-time tracking, API, shifts/trips).

Seeded the previously-undocumented Scout feature area as six new concept pages, synthesized from the code (`app/Models/Zone.php`, `KitSettings.php`, `KitShift.php`; `PennantServiceProvider`; `routes/{api,web,channels}.php`; `app/Clickhouse/Queries/*`; `app/Services/{Scout,Zones}/*`; `resources/js/clarity-scout/*`) and verified against it.

- `scout.md` (Concept): feature hub — the three surfaces (driver PWA, admin Filament panel, Bird's Eye org map), the GPS→ClickHouse→Vue data architecture, and the explicit-grant-only `CLARITY_SCOUT` / config-only `CLARITY_SCOUT_TRIPS` gating with the five gate points and `admin_impersonator` bypass.
- `zone.md` (DomainEntity): `platform.zones` (PostgreSQL `platform` connection) — columns, preset color palette, `is_no_scan_zone`, the `definition`→`geom` PostGIS rebuild (schema-qualified `public.ST_*`), `platform.zone_user` pivot, `ZonePolicy` (ADMIN, soft-delete only), spatial helpers.
- `scout-user-assignment.md` (Pattern): kit ↔ user ↔ zone chain via `platform.kit_settings`; `users.kit_id` is now an accessor (column dropped); `ScoutUsersResource` flow, 1:1 enforcement, `clarity-scout` permission grant + onboarding; kit-assigned-only zone eligibility.
- `scout-real-time-tracking.md` (Pattern): `clarity.sc_positions` pipeline, ClickHouse query classes, 10s chained-setTimeout polling + incremental `live-path` cursor, `navigator.onLine`+Echo offline detection (5s grace), `ZoneUpdated`/`ZoneAssignmentsChanged` private-channel broadcasts, Bird's Eye fan-out, impersonation cache TTL.
- `scout-api.md` (Registry): full endpoint catalog — driver `/api/scout/*`, broadcast auth, admin `/scout/org-map/api/*`, auth/SPA web routes, non-prod QA workflow.
- `scout-shifts-and-trips.md` (Concept): `platform.kit_shifts` (one-open-per-kit partial unique index), `EnsureOpenShift`/`CloseInactiveShiftsCommand`, `ShiftEndTypeEnum`, `BuildShiftSummary`→`ShiftSummaryDto`→`SendEndOfShiftSummary`, and the feature-gated Trips endpoints.

Cross-linked `kit.md` to `scout-user-assignment.md`; added a "Scout" category to `concepts/index.md` and six rows to `index.md`.

## [2026-06-26] ingest | Initial OKF bundle seeded with 7 core domain concepts, data-architecture disambiguator, and cross-repo registry; wired CLAUDE.md, AGENTS.md, and .cursor/rules/okf-wiki.mdc.

Seeded concept pages: scan, hit, hotlist, recovery, organization, kit, multi-tenancy, data-architecture, repositories. Authored as curated synthesis from `.claude/docs/business-rules.md`, `.claude/docs/tech-stack.md`, `.claude/docs/clickhouse-integration.md`, and the codebase. Existing `.claude/docs/` left in place but demoted to superseded supporting detail via convention-file wiring.

## [2026-06-26] update | Corrected hotlist sharing/expiration and hit types.

- `hotlist.md`: removed false `expires_at` claim (expiration is not used in Clarity); corrected sharing — hotlists are not shared via the `Shared` pivot (that's for Kits); hotlists from other orgs surface as Staging Hotlists (`is_staged`) producing hits of type `S` (Staging Live) and `Z` (Staging Historical).
- `hit.md`: replaced generic `Staging_*` with the exact `App\Enums\HitTypeEnum` values (`L`/`H`/`S`/`Z`/`M`/`F`/`P`); corrected source — `HitTypeEnum` is in this repo (`app/Enums/HitTypeEnum.php`), not Black Adam.
- `multi-tenancy.md`: clarified the `Shared` pivot is for Kits; added the Staging Hotlist distinction and `S`/`Z` hit types; fixed the "sharable assignments" See-also label.
- `repositories.md`: removed `HitTypeEnum` from the Black Adam enum list (it lives in this repo).

## [2026-06-26] update | Corrected PostgreSQL schema/connection split across data-architecture and entity pages.

The core LPR tables are NOT in the `platform` schema — they are in the `public` schema of the `zod` database, accessed via the `zod` connection (`$connection = 'zod'`). Only Clarity's add-on tables (`recoveries`, `shared`, `client_org_access`, `invites`, `alert_settings`) are in the `platform` schema (default `platform` connection).

- `data-architecture.md`: rewrote "Two databases, two roles" to describe the two PostgreSQL connections/schemas; updated the data-flow diagram (separate `zod` public-schema and `platform` schema sources); corrected the disambiguation table (`zod.hotlist`/`zod.scans`/`zod.hits` instead of `platform.*`; `platform.recoveries` kept); added a schema-name pitfall callout; expanded the "Which connection am I on?" rule to cover both connections and list all connections from `config/database.php`; fixed the See-also table paths.
- `scan.md`, `hit.md`, `hotlist.md`: corrected body table references from `zod.platform`/`platform.*` to `zod.scans`/`zod.hits`/`zod.hotlist` (public schema, `zod` connection).
- `recovery.md`: corrected the body to state `recoveries` lives in the `platform` schema via the default `platform` connection (not "mirroring the upstream platform schema").
- `CONTRIBUTING.md`: updated the data-architecture-first rule and the connection decision rule to reflect the two PostgreSQL connections/schemas.

## [2026-06-26] update | Documented trigger-driven hit creation and the laravel-zod-migrations package.

Hits are created by PostgreSQL triggers/functions, not application code: `find_hits()` on `zod.public.scans` INSERT/UPDATE (live hits `L`/`S`); `find_historicals()` on `zod.public.hits` INSERT (historical hits `H`/`Z`). These schemas/functions live in the `insight-lpr/laravel-zod-migrations` composer package (https://github.com/insight-lpr/laravel-zod-migrations), which owns the core `zod.public` schema.

- `hit.md`: added "How hits are created (PostgreSQL triggers)" section; added trigger-driven-creation business rule; recorded `find_hits()`/`find_historicals()` and the package in "Where it lives in code".
- `repositories.md`: added `insight-lpr/laravel-zod-migrations` as a confirmed registry entry (owns `zod.public` schema + hit triggers/functions).
- `data-architecture.md`: noted core `zod` schema ownership by `laravel-zod-migrations` in "Where it lives in code".

## [2026-06-26] update | Added drift-watch mechanism to keep wiki aligned with `laravel-zod-migrations` package changes (scoped to Clarity hotlists).

Added a persistent drift registry and a chat-triggered sync workflow so the wiki's hotlist business-rule docs stay current when the upstream `insight-lpr/laravel-zod-migrations` package changes (e.g. `find_hits()` / `find_historicals()` definitions, hotlist table columns) — but ONLY for changes that pertain to Clarity hotlists.

- `concepts/drift-watch.md` (new, type: Registry): enumerates the exact package surfaces the wiki depends on (`find_hits()`, `find_historicals()`, `zod.public.hotlist`, `zod.public.scans`, `zod.public.hits`), the wiki pages each affects, and a `last_verified` stamp per surface (package ref + ISO timestamp). Includes an explicit "Out of scope" section (kits/orgs/scans_metadata, `HitTypeEnum` which lives in this repo, Black Adam enums) so non-hotlist package drift does NOT trigger wiki edits.
- `CONTRIBUTING.md`: added the `sync wiki from laravel-zod-migrations` chat-triggered workflow and a "Sync workflow (upstream package drift)" section — diff the installed package against `last_verified`, apply the Clarity-hotlist relevance gate, re-verify only the listed pages, stamp `last_verified`, record a no-op `log.md` entry when no tracked surface changed, and log every run. Added `sync` to the direct-maintenance trigger list.
- `index.md`: added drift-watch to the page catalog and the "interacted with directly" bullet; updated the "How to use this wiki" pointer to mention the sync workflow.
- `concepts/index.md`: listed drift-watch under Meta / Cross-cutting.
- `log.md`: added `sync` to the operations list.

## [2026-06-26] sync | laravel-zod-migrations `e77f9845…` → `e2979238…` (dev-main): corrected trigger events/tables for `find_hits()` and `find_historicals()`.

Ran `composer update insight-lpr/laravel-zod-migrations --with-dependencies`; lock ref moved from `e77f9845d848749aca83c22fde0bad93083d0ada` to `e29792387b31536539f4a50d3abc234aa4fce42d` (`dev-main`). Verified the hit-creation triggers/functions against `vendor/insight-lpr/laravel-zod-migrations/database/schema/zod-schema.sql` and found the wiki had the trigger events/tables wrong — in-scope (Clarity hotlist) drift.

- `hit.md`: "How hits are created" — corrected `find_hits()` to **AFTER INSERT ON `public.scans`** (was "INSERT / UPDATE"); corrected `find_historicals()` to **AFTER INSERT OR UPDATE ON `public.hotlist`** (was "INSERT on `public.hits`"). Added the `L` vs `S` (Repo Company direct vs Staging Hotlist, `approval_list` / `silent_hit_generation` gating) and `H` vs `Z` (direct vs Staging, `lookback_days`) distinctions. Updated the trigger-driven-creation business rule and "Where it lives in code" with the correct trigger events.
- `drift-watch.md`: stamped `last_verified` for `find_hits()`, `find_historicals()`, `zod.public.hotlist`, `zod.public.scans`, `zod.public.hits` to `2026-06-26T16:33:00Z` against ref `e2979238…`; corrected the `find_hits()` / `find_historicals()` surface descriptions to match the installed triggers.
- No edits to `scan.md`, `hotlist.md`, or `data-architecture.md` were required — their hotlist-relevant claims still hold; `data-architecture.md` line 132 only names the functions (no event claims).

## [2026-06-26] ingest | Documented canonical PostgreSQL hotlist queries (opens, staging, addresses, repos) in hotlist.md.

Ingested the production PostgreSQL queries used to derive org-scoped hotlist metrics (direct opens, staging opens, direct addresses, staging addresses, direct repos, direct repo addresses last 7 days) and the business rules they encode. Verified schema surfaces against `vendor/insight-lpr/laravel-zod-migrations/database/schema/zod-schema.sql` (`hotlist_address`, `address` incl. `type_id` and PostGIS `geo`, `hotlist_meta.repo_date`, `approval_list`) and `HotlistStatusEnum` (`1` Open / `3` Repossessed) in `app/Enums/HotlistStatusEnum.php`.

- `hotlist.md`: added "Canonical PostgreSQL queries (open / staging / repo counts & addresses)" section with the six queries (normalized to use `<org>`/`<agent>` placeholders). Added business rules for `HotlistStatusEnum` semantics, the `length(plate) > 3` valid-plate filter, staging scope via `approval_list` + `is_staged`, `hotlist_meta.repo_date` as the repo date, and the `address.type_id = 2` = repo-address / PostGIS `0,0` sentinel conventions. Updated Relationships to note `HotlistMeta.repo_date`, the `hotlist_address`→`address` pivot, and `ApprovalList` (`staging_org_id`/`agent_org_id`).
- Verified `address.type_id = 2` = repo address against the package recovery views (`WHERE a.type_id = 2`).

## [2026-06-26] update | Corrected the Kit concept: a Kit is a USER that signs in to a physical LPR camera, NOT the device.

This is one of the most commonly misunderstood concepts in Clarity (by users and AI tools). The actual physical device is recorded **per scan** as `scans.camera_name`, not on the kit. A kit can log in to multiple devices; `kit_type=1` (cameras usually starting with `GL`) is the meaningful type. Verified against `vendor/insight-lpr/laravel-zod-migrations/database/schema/zod-schema.sql` (`kits` has `kit_type`/`name` but no device column; `scans.camera_name` exists) and the ClickHouse schema (`clarity.scans_cold`/`scans_hot` carry `kit_id` + `kit_name` + `camera_name` together — the kit↔camera_name aggregate).

- `kit.md`: rewrote — new opening "Kit is NOT a physical camera" callout, a "Kit vs. device vs. scan" disambiguation table (Kit / Device / Scan / Kit↔camera aggregate), corrected key-attribute descriptions (`name` = login name, `kit_type=1` = the type that matters), added business rules (kit is a user; kits can use multiple devices; `kit_type=1`; ownership fixed; sharing is Pro), updated "Where it lives in code" to point at the ClickHouse scan tables as the kit↔device aggregate.
- `scan.md`: reframed the opening (captured by a physical LPR camera; carries `kit_id` = logged-in user AND `camera_name` = device); corrected the `kit_id` and `camera_name` column descriptions; fixed the See-also that called the kit "the capturing device".
- `concepts/index.md`, `index.md`: updated the kit catalog summary to "a user that signs in to a physical LPR camera — not the device".
- `multi-tenancy.md`, `organization.md`: fixed See-also labels that called kits "devices".

## [2026-06-26] ingest | Migrated durable, code-verified knowledge from the legacy .claude/docs/ into 10 new OKF concept pages (engineering patterns + domain). Not a 1:1 copy: each old doc was reviewed, verified against code, and drift corrected.

Reviewed all 13 `.claude/docs/*.md` files. Migrated only durable domain + Clarity-specific engineering knowledge; excluded invalid/stale/process docs. Each new page was verified against the real code (`app/Clickhouse/*`, `app/Filament/*`, `app/Jobs/*`, `app/Events/*`, `app/Observers/*`, `app/Dto/*`, `app/Enums/*`, `config/database.php`, `config/queue.php`, `config/horizon.php`, `tests/TestCase.php`) and corrected drift from the legacy docs.

- `clickhouse-integration.md` (new): connections (`clarity_clickhouse`/`clarity_clickhouse_write`/`public_clickhouse`/`use_clickhouse`), PeerDB CDC columns, real `clarity.*` tables + engines (corrected stale names: `client_stats_aggregate`, `client_days_to_repo_hourly`, `aggregate_recovery_kit_scans` do not exist; `missed_opportunities`/`recoveries` are tables not views), PG→CH mappings, pgsql test bridge. Real model path `app/Clickhouse/Models/*` (not `app/Models/Clickhouse/*`).
- `clickhouse-query-patterns.md` (new): real `ClickhouseQuery` base (`app/Clickhouse/Queries/ClickhouseQuery.php`) with cache/admin-impersonator/fail-gracefully mechanics, `FiltersCdcDeletes` global scope + manual `_peerdb_is_deleted=0`, `scopeFinal()`, `HasMonthGrouping` (ClickHouse-only, not driver-aware), `Scan::hot()` hot/cold, `HotlistStatusAggregate` scopes (corrected `declined()` = `ClosedPositive (4)`, not `Closed (5)`; `closedCohort()` = `Closed (5)`).
- `client-analytics.md` (new): metric definitions/formulas per widget, `HotlistStatusEnum` status-transition model, `ClientStatHelper` date types, two-layer caching + `ClientRankedMetricsBatch`, real widget list incl. `TotalClientAssignmentsWidget`/`TotalClosedWidget`/`Lifetime/*` the old doc missed.
- `filament-conventions.md` (new): two panels, the 5 real concerns (`HasProFeature`/`HasClickableFilter`/`HasFilterActivityLogging`/`HasFilterAnalytics`/`HasRetryQuery`), custom columns (`DateTimeColumn`/`RemoteOrderColumn`), custom filters (`PlateFilter`/`KitUsersFilter`/`DateTogglesFilter`/`AddressFilter`/`FlexSearchToggle`).
- `jobs-and-queues.md` (new): real Redis hash-tagged queues `{low}`/`{default}`/`{high}` + Horizon supervisors, `RdnJob` base (`tries=3`/`backoff=10`/`timeout=600`/`RateLimited('rdn-api')` per-org), `Bus::batch()`, serialize-IDs convention.
- `dto-and-enums.md` (new): real `FeatureEnum` (16 cases, `platform_repo_pro_*` values, `minimumTier()`), full `HitTypeEnum` (L/H/S/Z/M/F/P), `HotlistStatusEnum` (incl. `Invalid 99` + `isTerminal()`), `InviteTypeEnum`/`NotificationTypeEnum`/`DispositionEnum`/`ProTierEnum`/`RdnMetricType`, ArchTech+Black-Adam metadata machinery, real DTO list.
- `events-and-observers.md` (new): `HitEvent` M-normalization, `HitEventListener` (`WithoutOverlapping`), `InviteObserver`→`ShareOrgKits`/`ClientOrgAccess`, `NotificationLogEventHandler`, event-vs-observer-vs-job decision guide.
- `testing-conventions.md` (new): sacred rules, `zod_testing`, real `connectionsToTransact()` = `['zod','platform']` (corrected old doc's claim it included `clarity_clickhouse`), `HasZodDatabase` materialized-view refresh, `TestCase` helpers, pgsql bridge.
- `alert-settings.md` (new): `AlertSetting` polymorphic model, `AlertSettingSettingsDto`, `AlertSettingsCast`, `HitAlertSettings` subscriber pipeline (`ProFilter`/`ClientFilter`/`HotlistStatusFilter`), channels (Twilio/Slack/Webhook/mail), `ALERT_SETTINGS` pro feature.
- `missed-opportunity.md` (new): `M`/`F` hit types, `hotlist_org_id` scoping (not `org_id`), `App\Models\MissedOpportunity extends Hit`, `clarity.missed_opportunities` table, `MISSED_OPPORTUNITY` pro feature.
- Updated `data-architecture.md` (corrected stale CH table list, model/query paths, `$connectionsToTransact`, removed invented `getTable()` prefixing + driver-aware `getMonthSelectSql` claims; added cross-links), `hit.md` (cross-links + repointed `.claude/docs` cite), `repositories.md` (added `insight-lpr/laravel-clickhouse-eloquent`, corrected `find_hits`/`find_historicals` trigger descriptions, confirmed Black Adam remote).
- `index.md` + `concepts/index.md`: added all 10 new pages to the catalogs (grouped Core Domain / Engineering Patterns / Meta).

## [2026-06-26] update | Deleted the legacy duplicate knowledge stores (.claude/docs/, skill companions, .serena/memories/) after migrating their durable content into OKF; thinned the 4 knowledge skills to defer to the wiki; cleaned convention-file references.

The legacy `.claude/docs/` (13 files), `.claude/skills/*/` knowledge companions (`domain-glossary.md`, `decisions-log.md`, `pattern-examples.md`, `platform-gotchas.md`), and `.serena/memories/` (7 files) were duplicate/stale knowledge stores that overlapped with OKF and contained drift. After migration (see the ingest entry above), they were deleted and the agent surfaces repointed at OKF so agents stop maintaining/creating the old docs.

- **Migrated 2 extra OKF pages from the companions** (per the guardrail to preserve durable content not already in OKF):
  - `architecture-decisions.md` (new): 9 ADRs from `decisions-log.md` (framework, dual-DB, org multi-tenancy, schema split, dual panel, Redis, Black Adam, Pennant, CI) — corrected ADR-002/ADR-004 drift (the `public`-vs-`platform` schema split and `zod`/`platform` connections).
  - `platform-gotchas.md` (new): 20 gotchas from `platform-gotchas.md` — corrected PG-018 (hotlists are Staging, not "shared hotlists"; `Shared` pivot is for Kits) and the Filament-version gotcha.
  - `index.md` + `concepts/index.md`: added both new pages to the catalogs.
- **Thinned the 4 knowledge skills** (`.claude/skills/{business-domain,architecture-decisions,platform-patterns,platform-debugging}/SKILL.md`) to pointers that defer to `okf/`; fixed drift (`app/Clickhouse/Queries` path, `zod` vs `platform` schema, Kit=user); removed the stale `memory-update` reference. `agent-qa` and `release-prep` (workflow skills) left intact.
- **Deleted**: 4 skill companion files; 7 `.serena/memories/*.md` files; 13 `.claude/docs/*.md` files (and the now-empty `.claude/docs/` directory). `.serena/project.yml` (Serena project config, not a knowledge store) retained.
- **Cleaned references**: `CLAUDE.md` (removed the deleted-doc table + "retained for history" wording; promoted OKF), `AGENTS.md` (removed `.claude/docs/` source-of-truth tier + "Useful Internal Docs" bullets; repointed to OKF pages), `.cursor/rules/okf-wiki.mdc` (updated source-of-truth ordering + description), `okf/CONTRIBUTING.md` (removed `.claude/docs/` as an ingest source; added the Serena-re-enabled-must-write-to-`okf/` note).
- No code under `app/` changed; docs only.

## [2026-06-26] update | Removed the 4 thinned knowledge skills (business-domain, architecture-decisions, platform-patterns, platform-debugging) — redundant now that OKF + the always-on .cursor/rules/okf-wiki.mdc route to the wiki. Only workflow skills (agent-qa, release-prep) remain under .claude/skills/.

The 4 knowledge skills had been thinned to pointers that re-stated the OKF catalog — a second place to keep in sync and the same duplicate-source-of-truth drift risk the migration just eliminated. Routing to OKF is already handled by the always-on `.cursor/rules/okf-wiki.mdc` rule and the `AGENTS.md`/`CLAUDE.md` pointers, so the skills added no discoverability that OKF's `index.md`/`concepts/index.md` catalogs don't already provide.

- Folded `platform-debugging`'s 3-step diagnostic procedure into a "How to use this page" header in `okf/concepts/platform-gotchas.md` (no workflow lost).
- Deleted `.claude/skills/{business-domain,architecture-decisions,platform-patterns,platform-debugging}/` (each was a single thinned SKILL.md pointer; their durable knowledge already lives in OKF).
- Updated `okf/index.md` provenance line to reflect that the knowledge skills are gone (not "deferring").
- No other files referenced the skill names (verified: no hooks/config/other skills wired to them).

## [2026-06-26] update | Replaced the flat `type: Concept` taxonomy with a meaningful controlled vocabulary so `type` carries routing signal (the one OKF-required field was previously near-uniform).

OKF makes `type` the only required frontmatter field precisely because it is the machine-actionable signal agents filter/route on; 19 of 22 concept pages were `type: Concept`, which satisfied the letter of OKF v0.1 while carrying almost no information. Reclassified per the page's actual kind. No conformance change (all pages already had a non-empty `type`); `php scripts/okf-lint.php` still passes (26 files, 0 warnings).

- `DomainEntity` (8): scan, hit, hotlist, kit, recovery, organization, missed-opportunity, alert-settings.
- `Pattern` (6): clickhouse-query-patterns, dto-and-enums, events-and-observers, filament-conventions, jobs-and-queues, testing-conventions.
- `ADR` (1): architecture-decisions.
- `Playbook` (1): platform-gotchas.
- `Concept` retained (4): data-architecture, multi-tenancy, clickhouse-integration, client-analytics — foundational/cross-cutting, not a single record.
- `Registry`/`Index`/`Schema` unchanged (drift-watch, repositories / concepts/index.md / CONTRIBUTING.md).
- `CONTRIBUTING.md`: documented the `type` controlled vocabulary under Conventions so the taxonomy is discoverable and extensible.

## [2026-06-26] infra | Added the OKF upkeep mechanism — a tool-agnostic CI linter + a Claude Code PostToolUse drift nudge — so the wiki stops silently drifting.

The wiki was previously maintained only passively (always-on routing rules + chat-triggered workflows in CONTRIBUTING). Nothing forced or reminded upkeep, so drift could accumulate until someone ran `evaluate wiki against code`. Two layers now close that gap:

- **CI linter (tool-agnostic, durable):** `scripts/okf-lint.php` checks broken internal `.md` links, `resource:` frontmatter paths that no longer exist in the repo, and orphan pages (warning). Wired in `.github/workflows/okf-lint.yml` to run on PRs touching `okf/**` and on a weekly Sunday drift sweep. Fails CI on hard errors. This is the safety net that works across Cursor / Claude Code / Codex / humans.
- **Claude Code PostToolUse nudge:** `.claude/hooks/okf-nudge.php` (registered in `.claude/settings.json`) fires after an Edit/Write/MultiEdit on `app/`/`config/`/`database/`/`tests/` and prints a non-blocking one-line reminder to run `update wiki from <path>` (deduped to once per file per 10 min). Claude-Code-specific; the CI linter covers other agents.
- Documented both in `okf/CONTRIBUTING.md` (Lint workflow → Automated lint + CI enforcement; new Upkeep hooks section); updated the `lint the wiki` trigger to run the script first.
- No `app/` code changed; no tests run from the agent (CI-first policy preserved).

## [2026-07-06] ingest | Added the Data Models reference — one OKF `DataModel` page per table/model (57 pages) so agents can find every model in zod `public`/`platform` and ClickHouse `clarity`/`zod`.

The wiki previously documented domain *concepts* (scan, hit, hotlist, …) but had no per-table schema reference. Agents had to re-derive columns/types/relationships from source each time. New `okf/models/` section closes that gap:

- **Coverage:** all ~40 PostgreSQL Eloquent models (both `public` and `platform` schemas, plus the `staging_orgs_hotlist_count` view and the `tiger_data.zcta5_all` reference table) under `okf/models/`, and all ClickHouse models under `okf/models/clickhouse/`. Filenames are the table name (schema/db prefix stripped). The two recovery-report CH models share `recovery_kit_scan_report.md`.
- **Column types are authoritative:** pulled live from the databases — PostgreSQL via `Schema::getColumns()` per connection (real Postgres types + nullability), ClickHouse via `system.columns` on ClickHouse Cloud (verbatim CH types + comments). Model casts/enums are listed separately, not substituted for the DB type.
- **Each page** states its database + schema/connection explicitly (data-architecture rule), lists columns, casts/enums, relationships (with cross-links to sibling model pages and `concepts/` pages), notable global scopes/CDC behavior, and the PostgreSQL↔ClickHouse mapping.
- **New `type` value:** `DataModel`, added to the `CONTRIBUTING.md` controlled vocabulary. Cataloged in `okf/models/index.md` (grouped by database/schema, with a PG↔CH quick-reference table); linked from the root `index.md` page catalog.
- Generated by 8 parallel writer subagents from a shared spec + live-DB column data; `scripts/okf-lint.php` passes clean (91 files, 0 warnings). No `app/` code changed; no tests run from the agent (CI-first policy preserved).

## [2026-07-06] ingest | Fleshed out Scout — new frontend-architecture + PWA/offline pages, plus geohash-heatmap and shift-automation detail on existing pages.

An audit of the Scout code (`resources/js/clarity-scout/` frontend + the backend controllers/services/queries/commands) against the wiki found three real coverage gaps beyond the existing hub + 5 sub-pages. Two new pages and two expansions close them; the bell-notification subsystem was left un-paged by choice (it's covered inline on the tracking/frontend pages).

- **New `concepts/scout-frontend.md`** (Pattern) — consolidates the Vue PWA client that was previously scattered piecemeal: the `main.js` (driver) vs `birdseye.js` (admin) boot and `__CLARITY_SCOUT_CONFIG__`/`FORCE_AUTH` injection, the flat query-param routing over one persistent `MapShell`→`ScannerMap`, the 5 Pinia stores + 13 composables, the Google-Maps+deck.gl layer stack, `lib/fetch` (token vs session/CSRF, `handleUnauthorized`) + `lib/adminScope` (auto `kit_ids[]` scoping), the client-side 500 ft trail-merge, and the browser-persistence keys.
- **New `concepts/scout-pwa-and-offline.md`** (Pattern) — the previously-undocumented PWA layer: `manifest.json` + `useInstallPrompt`, the three-tier `clarity-scout-sw.js` cache (shell network-first / assets cache-first / API network-first, 50-entry trim), the `version-sw.mjs` byte-stamp + `SW_UPDATED`/60 s-poll update lifecycle, and the `CLEAR_API_CACHE` PII purge on logout/401/force-login (with the client-initiated-purge nuance vs. the F2 audit note). Only the driver shell registers the SW.
- **Expanded `scout-real-time-tracking.md`** — added a geohash-heatmap subsection: precision 6–9, `HitDensityGeohashQuery` tier cutoffs + hot→cold color ramp, `HotlistAddressGeohashQuery` Direct(purple)/Staging(green) split with the `is_staged` fix (DEV-2080) and `validate_polygons=0` run-area clip.
- **Expanded `scout-shifts-and-trips.md`** — the assumed-close automation (`scout:close-inactive-shifts`, 180 min, every 30 min, flag-gated), the `SendEndOfShiftSummary` `lockForUpdate` idempotency + `summary_skipped_reason` outcomes, and the `KitTrip`/`kit_trips` backing (refresh-built ReplacingMergeTree queried FINAL, `path_simplified`), cross-linked to the model page.
- Cataloged both new pages in `index.md` + `concepts/index.md`. Verified every fact against source (`clarity-scout-sw.js`, `lib/fetch.js`, `router/index.js`, `CloseInactiveShiftsCommand`, the two geohash query classes, `KitTrip`). No `app/` code changed.

## [2026-07-06] ingest | Added five cross-cutting/integration concept pages — RDN, ClearPlan, feature flags & Pro tiers, impersonation, run areas.

A structural scan of the app (services, jobs, the 17 `FeatureEnum` Pro features, both Filament panels) against the concept pages found feature areas with real code and many scattered mentions but no home. Five new pages close the biggest ones; facts gathered by four parallel read-only inventory agents and then verified against source.

- **New `concepts/rdn.md`** (Concept) — the Recovery Database Network integration: the per-org SOAP client (`app/Services/RDN.php`, `services.rdn.api_url`, `rdn_id`/`rdn_api_key`), the `RdnJob` per-org rate-limited pull family, and the hourly RDN-metrics push to SQS (`DispatchRdnMetricsHourly` → `PublishRdnMetric` → `RdnMetricsPublisher`, counts from `clarity.hits_hot`/`scan_report_hourly`).
- **New `concepts/clearplan.md`** (Concept) — the outbound hit-replay integration over SQS FIFO (`ClearPlan::sendHit`, `HitPayload`), resend vs synthetic flex-match (`Probable_Match`/`P`) jobs, the Filament/HTTP triggers, and the `CLEARPLAN_RESEND`/`FLEX_SEARCH` gates.
- **New `concepts/feature-flags.md`** (Concept) — the runtime gating system complementing `dto-and-enums` (values) and `multi-tenancy` (scoping): Pennant resolvers + experimental config guards, `Org::isProEnabled`/`proTier` (individual-flag-first, then tier), the `zod.public.org_meta` tier store (Backyard-written), enforcement patterns + `admin_impersonator` bypass, the `platform.features` cache + `FlushPennant`.
- **New `concepts/impersonation.md`** (Concept) — the `admin_impersonator` mechanism referenced on 11 pages: Backyard Black-Adam-signed `/auth/impersonate` entry, the `impersonator` vs `admin_impersonator` keys, the effects (Pro-gate bypass, analytics skip, ~3s cache TTL), the Scout force-login token, and the DEV-2089 419 fix. **Corrects a stale audit belief:** the `clarity-scout-force-token` IS revoked on `leave()`/`exitAdminMode()` (F1 fixed).
- **New `concepts/run-area.md`** (DomainEntity) — `platform.org_run_areas`: Backyard-authored state/MSA/polygon `inputs` compiled to a PostGIS `MultiPolygon` by `RunArea\Materializer`, used to clip the Scout order-density heatmap; cross-links the `org_run_areas` model page.
- Added an **Integrations** group to `concepts/index.md`; cataloged all five in both index files; added back-links from `multi-tenancy` and `jobs-and-queues`.
- **Drift fix:** corrected `jobs-and-queues.md`, which wrongly listed `GetOrgHotlistCases`/`GetOrgHotlistCaseInfo`/`DispatchRdnMetricsHourly`/`PublishRdnMetric` as `RdnJob` subclasses — only `GetOrgRdnClients`/`GetOrgBranches`/`GetOrgBranchZips`/`GetOrgAgents` extend `RdnJob` (the rest implement `ShouldQueue` directly). No `app/` code changed.

## [2026-07-22] ingest | Documented the `disposition-saved` cross-surface analytics event + `from_scout` flag (3.8.0).

3.8.0 added a `from_scout` boolean to the `disposition-saved` analytics event so Scout-driver dispositions and Filament/web dispositions stay filterable while still aggregating under one shared event name. An audit of every `Analytics::track()` emission confirmed `disposition-saved` is the **only** crossover — every other Scout event is self-identifying via its `clarity-scout-` prefix (enforced by `ScoutTrackRequest`'s enum allow-list), so no other event needs a source flag.

- **Expanded `scout-analytics-taxonomy.md`** — added a "Cross-surface shared event — `disposition-saved`" subsection (surface→emitter→`from_scout` table + the *absent-or-false ⇒ web* segmentation rule for pre-3.8.0 rows), plus a caveat in the Naming scheme section flagging it as the one deliberate break from the `clarity-scout-*` namespace. Cited `ClarityScoutScanDispositionController` (`true`) and `DispositionController` (`false`).
- Refreshed the catalog description + date in `index.md` and `concepts/index.md`. Verified `from_scout` present in both controllers; confirmed the event is absent from `ScoutAnalyticsEvent`. No `okf`-tracked table/view semantics changed.

## [2026-08-11] ingest | Kit User Path route assembly moved into ClickHouse — documented `KitRangePathsQuery` + PG-022.

A client-portal Kit User Path selection (All Kits × 7 days) began returning *"Selection too large to render"* for a 200-kit org. Root cause was not the query and not the browser: the `MAX_RENDER_POINTS = 120000` guard (DEV-2177 `c6da55b7`) modeled **PHP's** ~1.5 KB/point cost of inflating every GPS point for `HistoricPathBuilder`. Measured on live ClickHouse, that org exceeded the cap at *every* date range, including Yesterday (~121k points). Fix moved the hour split + 500 ft break into SQL and returned each path pre-encoded, taking PHP to `O(segments)`; 7 days went from ~1 GB (refused) to ~20 MB / 3,491 segments / 13.5 MB payload.

- **Expanded `scout-clickhouse-queries.md`** — new `KitRangePathsQuery` section (the class was previously undocumented): the full `arraySplit`/`arrayFilter`/`arrayJoin` segmentation shape, the segments-not-points return contract, the pre-encoded `path_json` that is spliced into the HTTP response verbatim, viewer-timezone hour bucketing + `safeTimezone()`, the per-call clamped `DEDUP_METERS`, and `countPoints()` as a pre-split upper bound. Added it to the Scout-vs-non-Scout table and cross-linked from the `KitHistoricPositionsQuery` decimation note.
- **New `platform-gotchas.md` PG-022** — bounding a continuous-GPS route query: why widening decimation is unsound (speed-dependent spacing trips the fixed 500 ft break → blank map), why the real cost is usually PHP rather than ClickHouse or deck.gl, the distance-dedup lever and its clamp, and the previously-recorded dead ends (proportional widening, `limitBy()` absent from the base builder, `limit()` dropping whole kits).
- Refreshed catalog rows + dates in `index.md` and `concepts/index.md`.

**Lint finding, not fixed:** `log.md` (2026-07-06) records adding **PG-021** (production runs PHP 8.3) and `CLAUDE.md` cites `platform-gotchas.md PG-021`, but that section is absent from the page — lost in a later edit, leaving a dangling reference. This ingest used PG-022 to avoid colliding with the claimed number.

## [2026-08-13] ingest | DEV-2342 recorder rewrite — withdrew the load-bearing-concurrency claim; documented server-side match + disabled schedule.

The 2026-08-12 OOM was the PHP round-trip of the org address book (`ScoutOpenOrderAddressesQuery` + `values()` inject + `firstOrCreate`) multiplied by unbounded `withoutOverlapping(10)` overlap. DEV-2342 landed the server-side CTE lift and an N-slot ceiling; the schedule stays commented. The wiki still taught the old two-query split and the withdrawn "concurrency is load-bearing" conclusion (the exact mid-flight edit CLAUDE.md warns about).

- **Rewrote `scout-check-in-recorder.md`** — one `ScoutCheckInMatchQuery` CTE per kit; `::handle()` not `::query()`; `insertOrIgnore`; 2-slot `Cache::add` + heartbeat (lease 10 ≤ lookback 15); `ScoutOpenOrderAddressesQuery` deleted; schedule disabled pending W1/W4; Option 3 (`{low}` per-org jobs) named as a follow-up, not current design; incident numbers kept as history. Withdrew the load-bearing-concurrency sentence.
- **Updated `check-in.md`** — mechanism summary + "not real-time" bullet (no 15-min address cache; staging-partner lag remains).
- **Updated `scout-clickhouse-queries.md`** — catalog: deleted `ScoutOpenOrderAddressesQuery`; matcher is server-side CTE; added a short `ScoutCheckInMatchQuery` section + rollup vs recorder grain note.
- **Updated `concepts/index.md`** catalog blurb; added missing `check-in.md` / `scout-check-in-recorder.md` rows to the bundle-root `index.md`.
- Verified against `ScoutRecordCheckIns.php`, `ScoutCheckInMatchQuery.php`, `config/scout.php`, `routes/console.php`. No `app/` code changed in this ingest.
## [2026-09-09] ingest | DEV-2463 Unique LPR Case Claims — added the Company Metrics card-family and Billing Events pages; documented the engine-dependent tombstone trap as PG-028 and the real widget TTL ladder under PG-009.

## [2026-09-17] ingest | DEV-2252 zone-from-ZIP — recorded the `ST_Union`-drops-NULL class as PG-031 and the `spatial_ref_sys` test lock as PG-032; corrected `zone.md` and `run-area.md`, both of which taught the pre-fix behaviour.

Creating a zone from ZIP codes failed in three ways and all three failed **quietly**: a ZIP with no Census boundary saved a `geom = NULL` zone under a "Zone saved" toast (invisible on every map, surfaced nowhere — the Zones list never selects `geom`); a 4-digit fragment, the shape Excel produces when it strips a leading zero, was accepted and prefix-matched; and a 5-digit ZIP with no exact match fell back to `rows[0]`, attaching an entirely unrelated geography. `RunArea\Materializer` carried the same silent-empty hole and additionally **stamped `materialized_at` over the NULL**, so a re-materialize could destroy a good boundary.

- **New `platform-gotchas.md` PG-031** — `ST_Union` **drops** NULL members rather than propagating NULL, so any one member resolving is enough to produce a valid, non-empty result. An emptiness check therefore proves only that *everything* failed, never that *something* did; completeness needs the members resolved separately. Records the two traps that came with it: the lookup must run **outside** the write transaction (a swallowed error inside an open Postgres transaction turns `COMMIT` into a silent `ROLLBACK` under a success toast — caught in review, not in testing), and a NULL geometry in `tiger_data.zcta5_all` is **schema-permitted** (`CHECK (geometrytype(the_geom) = 'MULTIPOLYGON' OR the_geom IS NULL)`), so fixtures must seed one deliberately.
- **New `platform-gotchas.md` PG-032** — the test harness holds an `AccessExclusiveLock` on `public.spatial_ref_sys` for the life of every test (read from `pg_locks`), so any `ST_Transform` **hangs** rather than fails. That makes the entire resolvable-input happy path of both Zone and Run Area untestable in PHPUnit; pin the failure paths there and move the happy path to browser QA. Also records that seeding TIGER on the `platform` connection instead of `zod` makes the hang worse, not better.
- **Corrected `zone.md`** — it documented `rebuildGeometryFromDefinition()` as if a returned geometry meant success. Added the two-part save guard (`NULL`/`ST_IsEmpty` **and** `unresolvedZipCodes()` outside the transaction), the persistent danger notification and *why* a notification rather than a field error (`definition` is a `Hidden` component with no error slot, and the page has no form-level error region, so a validation failure renders nothing at all), and the exact-5-digit client rule with the prefix-vs-exact mismatch between `searchZipCodes()` and `rebuildGeometryFromDefinition()` that made it necessary.
- **Corrected `run-area.md`** — the Materialization section described the union and the stamp with no mention of failure. Added the three throw cases, the fact that they run inside the `platform` transaction so a rollback preserves the prior geometry, and two live caveats: the new `whereNotNull` on `simplified_geom` turns **mixed** polygon+state run areas from silently under-clipping into loud `failed_jobs` entries (nothing in this repo populates that column — `StateSeeder` writes `tiger.state`, a different table), and the happy path is browser-QA-only per PG-032.
- Bumped the `PG-001…PG-027` range in the "How to use this page" list to `PG-001…PG-032`; it was already stale at PG-030 before this ingest.

Verified against `app/Filament/Pages/ManageZones.php`, `app/Services/RunArea/Materializer.php`, `resources/js/components/zone-manager.js` and `app/Models/Zone.php` as merged on `claude/dev-2252-f748a2`. No `app/` code changed in this ingest.

**Not fixed, carried forward:** `simplified_geom` population in production remains unverified — an attempt to check it read-only against the production CDC mirror was denied by the environment's permission classifier and was not worked around. The query is in DEV-2252's Build Record. The long-standing PG-021 dangling reference noted in the 2026-08-13 entry is still unresolved.

## [2026-09-22] ingest | DEV-2651 Company Metrics 16→12 — merged-card pattern, the corrected blast-radius set, and the Chart.js legend/draw-order traps.

Landed after PR #427 merged to `main` (merge commit `a36de652`). Verified against merged `main`, not against the feature branch.

**`concepts/company-metrics-dashboard.md`** — the registry table was wrong in two ways at once: the count (16, now **12**) and the order, since DEV-2651 both removed four cards and re-ordered every survivor. Four classes were deleted outright (`ScanCount`, `UniqueScansToRepo`, `UniqueScansToCaseClaims`, `UniqueScansToLprRepossession`), so any older doc, spec or fixture naming them or quoting an index is stale.

New **Merged cards** section covering what the pattern actually requires, each item from a defect found in review or QA rather than from the design:

- the shared numerator (`RatioAgainstUniqueScans`) deliberately carries **no denominator** — zip against the headline's own month array, because two independently bucketed queries drift;
- co-plotting is what **exposed** a calendar mismatch that was invisible while the two series sat on separate cards (a raw total rendering *below* a distinct-plate count, which is structurally impossible). One zone must feed both the `WHERE` bounds and the `GROUP BY`;
- counts are bars, a ratio is a **line** on its own right-hand axis; a pair of counts keeps **one** axis;
- `makeDataset()`'s **per-month colour array** makes Chart.js build the legend swatch from index 0 — the leftmost month, never the highlighted one — so a legend shows grey beside accent-coloured bars. Use `staticColourDataset()` on any charted series with a legend;
- Chart.js sorts datasets by `(order, index)` ascending then draws that list in **reverse**, so a line needs a *lower* `order` than the bars or it is buried where it crosses one;
- a failed figure renders `--`, never `0`, and `UNAVAILABLE_VALUE` is deliberately not `LOCKED_VALUE`;
- absorbing a card collides its cache key with the survivor's unless the ported leg gets a sub-namespace — at a one-year TTL.

**Gating.** The gated set this page recorded was a DEV-2464 snapshot and had drifted twice since. `UniqueScansLeaderboard` joined at **DEV-2468** (PR #396, checked against the commit that introduced the override rather than inferred). `UniqueScanCounts` **left** at DEV-2651 — it absorbed an ungated card, so gating would have removed a number orgs already saw, at a measured cost of a 60,738,996-row / 2.44 GB scan no longer bounded to entitled orgs. DEV-2651 also added the new `canViewRatioFigure()` **partial** gate on `RecoveryCounts` — card open, figure gated.

**Corrected a claim this wiki was teaching wrongly.** The blast-radius table and PG-029 both said **"four of sixteen"** cards propagate a ClickHouse failure to a grid 500. Re-measured per *leg* against merged `main`: it is **six of twelve**, with two more mixed. The deciding factor is `ClickhouseQuery::handle()` (no wrapper, propagates) versus `::query()` (catches `Throwable`, returns `[]`) — **not** the card's class, which is how the old figure was derived. The corollary is now stated in both places: a "did the query fail?" guard written around `::query()` is **dead code**, because the failure became an empty array before the guard could run. DEV-2651 review found precisely that — a regression test that stubbed the seam above the swallow and certified a guard that could never fire.

**Not recorded, deliberately.** A follow-up removing **all** Pro gating from this grid (and deleting `FeatureEnum::COMPANY_METRICS`) is open as PR #431 and **not merged**. It is not documented here: the wiki is a source of truth, not a scratchpad, and DEV-2342 is the standing example of what mid-flight documentation costs. The gating sections above describe merged `main` and will need a second pass if #431 lands.

No `app/` code changed in this ingest.
