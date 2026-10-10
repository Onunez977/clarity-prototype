---
type: Playbook
title: Platform Gotchas
description: Recurring Clarity debugging pitfalls — setPermissionsTeamId ordering, FINAL/_peerdb_is_deleted in ClickHouse, org-scope leaks, factory org chaining, Pro gating, Livewire state, cache staleness, test env, Horizon, policies, N+1, panel placement, timezones, feature-flag scope, bounding continuous-GPS route queries, web catch-all vs /broadcasting/auth, scheduled-command process pile-up, functions defeating ClickHouse primary-key pruning, unguarded loops aborting a whole command, round-tripping a same-database join through PHP, sizing PHP memory by production cardinality, join_use_nulls=0 filling unmatched LEFT JOINs with type defaults instead of NULL, PostGIS ST_Union silently dropping NULL members, the test harness lock on public.spatial_ref_sys, hot/cold table selection driven by a caller-supplied window, releasing a completion-signal cache key before the state it guards, and two renderers of the same text drifting apart. Migrated from the legacy platform-debugging skill gotchas catalog, drift corrected.
resource: tests/TestCase.php
tags: [domain, lpr, debugging, gotchas, testing, clickhouse, filament, geo, performance, memory, scheduling]
timestamp: 2026-09-21T00:00:00Z
---

# Platform Gotchas

Recurring pitfalls that bite when working in Clarity, with symptom → cause → fix. Migrated from the legacy `.claude/skills/platform-debugging/platform-gotchas.md` (now deleted). Drift corrected: PG-018 (hotlist sharing) and the Filament-version gotcha were inaccurate.

## How to use this page

1. **Match a known gotcha.** Scan the list below (PG-001…PG-036) for the symptom.
2. **Identify the surface.** Which layer? Filament/Livewire, permissions, tests/DB, queues, or Laravel generic — the catalog is grouped roughly that way.
3. **Apply the fix** listed; cite the gotcha ID in your notes/logs.

For underlying concepts (ClickHouse `FINAL`, org scoping, Pro gating, cache TTLs), follow the **See** links to the relevant `okf/concepts/*.md` page.

## PG-001: `setPermissionsTeamId` ordering

- **Symptom:** "There is no role named [admin]" in tests.
- **Cause:** `assignRole()` called before `setPermissionsTeamId()`.
- **Fix:** Set the team id **first**. The `createUser()` / `createClientPortalUser()` helpers do this for you.
- **See:** [Testing Conventions](testing-conventions.md).

## PG-002: Missing `FINAL` in ClickHouse queries

- **Symptom:** Widget counts are 2–5x too high.
- **Cause:** `ReplacingMergeTree` without `FINAL` returns all row versions.
- **Fix:** Use `->final()` (`scopeFinal()`) on the model, or `<table> FINAL` in raw SQL. Remember `FINAL` is a no-op on the pgsql test driver.
- **See:** [ClickHouse Query Patterns](clickhouse-query-patterns.md).

## PG-003: Wrong database connection in a model

- **Symptom:** "Table not found" or wrong data.
- **Cause:** Model missing the right `$connection` (`zod` for core LPR, default `platform` for Clarity add-on, `clarity_clickhouse` for ClickHouse).
- **Fix:** Set the connection explicitly. See [Data Architecture](data-architecture.md).

## PG-004: Multi-tenant data leak

- **Symptom:** User sees another org's data.
- **Cause:** Query missing `org_id` filter.
- **Fix:** Use `forOrg($orgId)` scope (or `where('org_id', …)`); never hand-roll without a scope. The most common Clarity bug.
- **See:** [Multi-Tenancy](multi-tenancy.md).

## PG-005: Factory missing org relationship

- **Symptom:** Null constraint violation / orphaned records.
- **Cause:** Factory not chaining org relationships.
- **Fix:** Use `forOrg($org)` on the factory (e.g. `Scan::factory()->forOrg($org)->create()`).

## PG-006: Pro feature not gated

- **Symptom:** Non-Pro users can access Pro features.
- **Cause:** Missing `HasProFeature` trait / `Feature` check.
- **Fix:** Add `HasProFeature` + `getFeatureName()` to the widget/page/resource.
- **See:** [Filament Conventions](filament-conventions.md), [DTO and Enums](dto-and-enums.md).

## PG-007: Livewire component state lost

- **Symptom:** Form data disappears / widget resets.
- **Cause:** Component re-rendering from a parent change.
- **Fix:** Use `wire:key` to preserve component identity (common with nested Filament components).

## PG-008: Filament form validation not showing

- **Symptom:** Form submits but no validation errors.
- **Cause:** Missing `->required()` / validation rule on the field.
- **Fix:** Add the validation rule; also check the JS console for Livewire errors.

## PG-009: Dashboard widget cache stale

- **Symptom:** Widget shows old data after a change.
- **Cause:** Aggressive widget caching (10-min default; 3s/10s when impersonating; 5s non-prod).
- **Fix:** Clear cache / reduce TTL. `php artisan cache:clear`.
- **Also:** for the Company Metrics family the ladder is `DashboardWidget::getCacheTTL($index)` —
  **1 year** for `$index !== 0` (historical sparkline buckets, treated as immutable), **10 min** for
  `$index === 0`, **10 s** while impersonating. A card that caches through its own path (e.g.
  `Cache::flexible`) **bypasses the ladder entirely**, including the impersonation collapse, so an
  impersonating admin can see one card far staler than its neighbours. A cached *monthly map* must
  carry the window in its cache key, or the 1-year TTL can serve a map whose newest month is absent.
- **See:** [Company Metrics Dashboard](company-metrics-dashboard.md) (the ladder + the
  `class_basename` key trap), [Client Analytics](client-analytics.md) (cache TTLs),
  [ClickHouse Query Patterns](clickhouse-query-patterns.md).

## PG-010: Test using wrong env

- **Symptom:** Tests affect the development database.
- **Cause:** Not using `.env.testing` / `zod_testing`.
- **Fix:** Run tests with the testing env; confirm `.env.testing` has `DB_DATABASE=zod_testing`.
- **See:** [Testing Conventions](testing-conventions.md).

## PG-011: Horizon jobs stuck pending

- **Symptom:** Jobs dispatched but never processed. Supervisors may still show as running (e.g. `RunOttoChat` piled up on `{otto}`).
- **Cause:** Horizon not running; wrong queue name (remember `{low}`/`{default}`/`{high}`/`{otto}` braces); **or** Laravel 12.66+ `queue:work` reads `--stop-when-empty-for` while Horizon 5.46's `horizon:work` omits that option, so every worker crashes on boot (`The "stop-when-empty-for" option does not exist`) while Horizon keeps reporting the supervisor as running.
- **Fix:** Confirm the lerd Horizon worker is up. Queue names must use braces. `App\Horizon\WorkCommand` shims the missing option until `laravel/horizon` is >= 5.47. Redis `retry_after` must exceed the longest job `$timeout` (`config/queue.php` uses 300; Otto is 180s). Restart Horizon after config/code changes. Check `storage/logs/laravel.log` for the stop-when-empty-for exception.
- **See:** [Jobs and Queues](jobs-and-queues.md), [Otto](otto.md).

## PG-012: Policy not being checked

- **Symptom:** Authorization always passes/fails.
- **Cause:** Policy not registered, or wrong method name.
- **Fix:** Register the policy; ensure the method matches the action (`viewAny`, `view`, `create`, …).

## PG-013: Filament table N+1 queries

- **Symptom:** Slow table loading, many queries.
- **Cause:** Missing eager loading.
- **Fix:** `->modifyQueryUsing(fn ($q) => $q->with(['kit', 'org']))`.

## PG-014: Client panel showing admin resources

- **Symptom:** Client users see admin-only features.
- **Cause:** Resource in the wrong panel directory.
- **Fix:** Admin → `app/Filament/Resources/`; client → `app/Filament/Client/Resources/`.
- **See:** [Filament Conventions](filament-conventions.md).

## PG-015: Timezone mismatch in reports

- **Symptom:** Date-based reports off by hours.
- **Cause:** Mixing UTC storage with local display.
- **Fix:** Store UTC, convert on display (Carbon timezone). `DateTimeColumn` handles this for Filament.
- **See:** [Filament Conventions](filament-conventions.md) (`DateTimeColumn`).

## PG-016: Filament major-version breaking changes

- **Symptom:** Code that worked in one major version fails after upgrade.
- **Cause:** Major-version upgrade (namespace/signature/renamed methods).
- **Fix:** Check the Filament upgrade guide for the version in `composer.json`. (Older docs referenced a v3→v4 upgrade; the current manifest version is authoritative.)

## PG-017: Missing audit logging

- **Symptom:** Compliance audit fails; no action record.
- **Cause:** Significant action not logged.
- **Fix:** Add audit logging (`LogsActivity` / model observers) for significant user actions.

## PG-018: Staging/sharing data confusion

- **Symptom:** Hotlist data from other orgs appears unexpectedly / misunderstanding of "shared" data.
- **Cause:** Confusing the `Shared` pivot (used for **Kits**) with hotlists from other orgs. Hotlists from other orgs surface as **Staging Hotlists** (`is_staged`), gated by `ApprovalList` — they are **not** "shared hotlists".
- **Fix:** Use staging-aware queries (`is_staged`, `approval_list`); don't query `Shared` for hotlists.
- **See:** [Hotlist](hotlist.md), [Multi-Tenancy](multi-tenancy.md), [Kit](kit.md).

## PG-019: Feature-flag scope mismatch

- **Symptom:** Feature shows for wrong users/orgs.
- **Cause:** Using a user scope instead of an org scope.
- **Fix:** Most features are **org-scoped** — use `Feature::for($user->org)->active(...)` (or `Feature::active(...)` within the org context), not `Feature::for($user)`.
- **See:** [DTO and Enums](dto-and-enums.md) (`FeatureEnum`).

## PG-020: ClickHouse materialized view not updated

- **Symptom:** Dashboard shows stale aggregate data.
- **Cause:** MV not refreshing, or source rows not yet replicated (PeerDB lag ~1s; aggregate MVs refresh ~10 min).
- **Fix:** Check the MV definition + source-table inserts; query the source table directly to compare. In tests, `HasZodDatabase` refreshes the PG materialized views.
- **See:** [ClickHouse Integration](clickhouse-integration.md), [Testing Conventions](testing-conventions.md).

## PG-021: Production runs PHP 8.3 while local/CI run newer

- **Symptom:** Code that passes locally and in CI fails at runtime on production, typically on syntax or a stdlib signature introduced after 8.3.
- **Cause:** Production runs **PHP 8.3**; local and CI environments run newer. The `composer.json` platform pin (`config.platform.php = 8.3.99`) constrains **dependency resolution**, not the syntax your own code may use — so nothing stops you writing 8.4+ code that resolves cleanly and then breaks in production.
- **Fix:** Keep application code 8.3-compatible. Treat `composer.json` `require.php` (`^8.3`) as the floor for language features, and do not rely on the platform pin to catch syntax.
- **See:** [Architecture Decisions](architecture-decisions.md), `composer.json` (authoritative for all framework/runtime versions).

## PG-022: Bounding a continuous-GPS route query

- **Symptom:** A whole-fleet / long-range map selection OOMs, times out, or is refused with *"Selection too large to render."* The obvious fix — widen the ClickHouse decimation interval — produces a **blank or shredded map** instead.
- **Cause (two layers, and the second is usually the real one):**
  1. **Decimation widening is unsound.** Segmentation breaks a line on any `>500 ft` jump between consecutive points and drops `<2`-point segments. At decimation `N` seconds consecutive points sit ~`N × speed` apart, so above ~`500/N` ft/s the break fires on *every* pair, every segment becomes a lone point, and every point is dropped. At `N = 7s` that is ~49 mph — ordinary highway driving vanishes.
  2. **The memory cost is almost always PHP, not ClickHouse and not the browser.** Inflating each GPS point into a keyed PHP array for `HistoricPathBuilder` costs ~1.5 KB/point against a 256 M `memory_limit`. The map is deck.gl (`PathLayer`, GPU) and eats 1 M+ vertices without complaint, and ClickHouse assembles the route in seconds. **Measure PHP before assuming the query or the client is the bottleneck** — a point-count guard sized from PHP's cost is a PHP guard wearing a data-volume costume.
- **Fix:**
  - Keep decimation **pinned at 3s**. Bound volume with the **distance dedup** (`DEDUP_METERS`) instead: it guarantees a minimum *spacing* regardless of speed, so it cannot shred a route as long as it stays under the break distance (clamp it — `KitRangePathsQuery` uses `BREAK_METERS / 3`). Expect ~40-45 % reduction, not 5×: it collapses idle/slow travel, since highway points are already ~40-90 m apart at 3s.
  - If that is not enough, take PHP out of the per-point path entirely: segment **inside ClickHouse** and return each path **pre-encoded** (`toJSONString`), so cost becomes `O(segments)` rather than `O(points)`. `KitRangePathsQuery` is the worked example — 7 days × all kits for a 200-kit org went from ~1 GB (refused) to ~20 MB / 3,491 segments.
- **Also dead ends:** proportional widening (a soft bound — does nothing when native cadence is already coarser than the interval); `->limitBy()` (`LIMIT n BY`) is not on the base builder `DB::clickhouse()` returns; a plain `->limit()` drops whole kits.
- **See:** [`KitRangePathsQuery`](scout-clickhouse-queries.md#kitrangepathsquery--kit-user-path-multi-kit-route-non-scout), [Scout real-time tracking](scout-real-time-tracking.md).

## PG-023: A scheduled command whose runtime exceeds its cadence piles up processes

- **Symptom:** A scheduled command appears to be "running slow", then the host runs out of memory. Logs show many overlapping runs of a command that should never overlap.
- **Cause:** `withoutOverlapping(N)` takes a **lock expiry in minutes — it is not a duration cap.** Once a run outlives `N`, its own lock expires while it is still executing and the next scheduler tick starts a *second* process. Each spawn re-arms the mutex, so new processes appear roughly every `N` minutes for as long as the run continues. Both directions are traps:
  - **Explicit small `N`** → pile-up. On 2026-08-12 `scout:record-check-ins` ran `everyFiveMinutes()->withoutOverlapping(10)` while **that day's** PHP-chunk cycles took **63–209 minutes**, producing a measured peak of **17 concurrent processes** holding **~29 GB** on a 68 GB host. Production was rebooted (DEV-2342). Those durations are the incident, not the post-W2/W3 / `scout_open_addresses` matcher.
  - **No argument at all** → Laravel's default expiry is **1440 minutes (24 h)**, so a hard-killed run (deploy, OOM, eviction) strands the mutex for a full day and the command silently stops. `routes/console.php` currently has three `withoutOverlapping()` calls and **two pass no argument** — `SendApprovedClientInviteMails` (`everyMinute()`) and `CloseInactiveShiftsCommand` (`everyThirtyMinutes()`).
- **Fix:** Bound the work per cycle so duration cannot exceed the cadence — that is the only fix that makes the lock meaningful. Never treat the expiry as a cap. If you must cap concurrency directly, derive the ceiling from **measured peak RSS against host RAM**, not from throughput — DEV-2342's recorder uses an in-process N-slot `Cache::add` semaphore (heartbeat-refreshed; lease ≤ lookback). DEV-2386 re-enabled the schedule; do not re-comment it. Host-verify the live cycle (AC-4 / AC-6) before treating duration as settled. Before raising any expiry, check the coupling: a stranded lock means the next cycle only reaches back its own lookback window, so `expiry − lookback` of data is examined by **no** cycle and lost silently.
- **See:** [Scout Check-in Recorder](scout-check-in-recorder.md), [Jobs and Queues](jobs-and-queues.md), PG-025.

## PG-024: Wrapping a ClickHouse `ORDER BY` prefix in a function disables primary-key pruning

- **Symptom:** A ClickHouse query filtered on what looks like the primary key is orders of magnitude slower than expected, and `EXPLAIN indexes = 1` shows it reading most of the table's granules.
- **Cause:** The sort key is matched **literally**. `clarity.sc_positions` is `ORDER BY (kit_mapping, timestamp_utc)`, but every Scout reader filters `lower(kit_mapping)` (DEV-2012), so the primary index cannot prune the prefix. Measured on Cloud: **3056 / 32732 granules scanned vs 13 / 32732** for exact-case equality — roughly **600 ms per query instead of ~20 ms**. At scale that difference is the whole problem: it turned a per-kit chunked matcher into a multi-hour cycle (DEV-2342).
- **Fix:** Add a **projection** ordered by the functional expression (`ORDER BY (lower(kit_mapping), timestamp_utc)`) — the optimizer then picks it up with **no query rewrite**. Alternatives that do *not* work: an `ALIAS`/virtual column (ClickHouse inlines the expression and the index still cannot prune), and changing `ORDER BY` (immutable — it needs a table rebuild). A stored, already-normalized column plus a rewrite of every reader is the heavier fallback.
- **Blast radius:** nine call sites read `clarity.sc_positions` through `lower(kit_mapping)` — `ScoutCheckInMatchQuery`, `KitHistoricPositionsQuery`, `KitRangePathsQuery`, `OrgLivePositionsQuery`, `OrgLivePathsQuery`, `OrgScanPathPositionsQuery`, `ClarityScoutMapController`, `ClarityScoutBirdsEyeController`, `CloseInactiveShiftsCommand`. All of them benefit from one projection.
- **Note:** DDL on `clarity.*` is owned by **chlocal**, not platform — platform is SELECT-only here (precedent DEV-2177).
- **See:** [Scout ClickHouse Queries](scout-clickhouse-queries.md), [ClickHouse Query Patterns](clickhouse-query-patterns.md).

## PG-025: An unguarded loop lets one bad row abort the whole command — and console-only output hides it

- **Symptom:** A scheduled command fails on every run, and the logs always name the *same* record — which looks like that record is special.
- **Cause (two layers):**
  1. **The loop is unguarded.** `CloseInactiveShiftsCommand` calls `latestActivityFor()` bare inside `foreach ($openShifts as $shift)`, so the first shift that throws aborts the entire command. The logs only ever show one kit because **iteration #2 is never reached** — not because that kit is an outlier. (It was the 187th largest kit, at 0.11% of the table.) Result: 100% failure rate for over a week and zero shifts closed (DEV-2309).
  2. **The underlying throw** was a `max(scanned_at)` filtered by `kit_id` + `is_visible` against a **4.94-billion-row** table with no supporting index — any kit would have timed out.
- **Fix:** Wrap the per-item body in `try/catch` so one failure degrades that item, not the run, and log the failure with its identifier. `ScoutRecordCheckIns` is the pattern to copy — it guards both the per-org and per-kit body and emits a `Log::warning` with `org_id`/`kit_id`. Then check the index actually supports the aggregate before assuming the loop was the whole problem.
- **Observability corollary:** that command's status lines are all `$this->info()` / `$this->line()`, and the schedule is `runInBackground()` — so **nothing but the exception ever reaches `laravel.log`**. A `runInBackground()` command with console-only output is unobservable by construction; there is no way to tell whether it has ever succeeded. Use `Log::` for anything you would want to see in production.
- **See:** [Jobs and Queues](jobs-and-queues.md), PG-023.

## PG-026: Do not round-trip a join through PHP when both sides live in the same database

- **Symptom:** A command issues thousands of queries per cycle and its memory grows with the *input* size rather than the result size.
- **Cause:** Pre-DEV-2342, `scout:record-check-ins` pulled an org's entire open-address list **out** of ClickHouse into a PHP array, re-serialized it as inline SQL `values(...)` literals, and pushed it **back in** 2000 rows at a time — repeated **per kit**. Both sides of that join live on the same `clarity_clickhouse` connection (positions in `clarity.sc_positions`, addresses in `clarity.hotlist` → `zod.public_hotlist_address` → `zod.public_address`). The round-trip produced roughly **5,500 queries per cycle** and made per-process memory `O(addresses_per_org)`. Aggravating detail: **`array_chunk()` copies the entire array**, so peak memory is ~2× the list during every kit, and caching does not help because each concurrent process materializes its own copy.
- **Fix:** Compute the join **server-side** and return only the matched rows. DEV-2342 lifted `ScoutCheckInRollupQuery`'s matches CTE onto `ScoutCheckInMatchQuery` — one statement per kit, PHP sees only matches. Prefer that lift over writing a parallel implementation. DEV-2386 re-enabled the schedule; do not re-comment it.
- **See:** [Scout ClickHouse Queries](scout-clickhouse-queries.md), [ClickHouse Integration](clickhouse-integration.md), PG-024.

## PG-027: PHP memory is sized by production cardinality, not by your fixtures

- **Symptom:** A command, job, or export dies with *"Allowed memory size … exhausted"* — or worse, the **host** runs out of memory — while the same code passes every test and looks fine in local QA.
- **Cause:** Two multipliers that fixtures never expose:
  1. **PHP structures are far larger than the data they hold.** A row inflated into a keyed PHP array costs vastly more than its bytes on the wire — measured in this repo at **~1.5 KB per GPS point** (PG-022). Ten thousand rows is not "small" once hydrated.
  2. **Production cardinality is orders of magnitude above test data.** Measured 2026-08-13: `zod.public_scans` holds **4,944,026,220 rows across 4,799 kits** (median **357,159** per kit); a single org's open-order address list runs to **millions**. The pre-DEV-2342 matcher volume test used **3 addresses** against a production chunk size of **2000** — a good test of chunk-merge correctness that said nothing about cost at scale. DEV-2342's AC6 test seeds **20k** far addresses via ClickHouse `numbers()` and asserts peak RSS after the seed; it still cannot hold a 255k-address org locally.
  Then multiply by concurrency: a scheduled command that overlaps itself holds one copy **per process** (PG-023), and that is how a per-process footprint became **~29 GB** across 17 processes and took a 68 GB production host down (DEV-2342).
- **Fix — measure before you write the loop:**
  - **Get the real number first.** The `zod.public_*` CDC tables in ClickHouse mirror production Postgres, so cardinality is one read-only query away. Ask for the **largest group**, not the average — code that loops per-org and holds a group in memory is sized by the biggest org:
    ```sql
    SELECT org_id, count() AS n FROM zod.public_hotlist_address
    WHERE _peerdb_is_deleted = 0 GROUP BY org_id ORDER BY n DESC LIMIT 10
    ```
    (Skip `FINAL` on `zod.public_*` and filter `_peerdb_is_deleted = 0` — the house pattern; `FINAL` on a billion-row table is its own problem.)
  - **Compute the expected peak** — `rows × per-row cost × concurrent processes` — against the real limit. Note the CLI/queue `memory_limit` is **128M** here, not the web value; a scheduled command does not inherit your browser request's headroom.
  - **Prefer not materializing at all:** aggregate or join **server-side** and return only results (PG-026), stream with a generator/`LazyCollection`, or paginate. Watch for hidden copies — **`array_chunk()` duplicates the entire array**.
  - **Assume growth.** In one day this fleet went 29–32 → 43 kits and 11 → 12 orgs; a budget with no headroom is already stale.
- **See:** PG-022, PG-023, PG-026, [Jobs and Queues](jobs-and-queues.md), [Data Architecture](data-architecture.md).

## See also

- [Testing Conventions](testing-conventions.md), [ClickHouse Query Patterns](clickhouse-query-patterns.md), [Filament Conventions](filament-conventions.md), [Jobs and Queues](jobs-and-queues.md), [Multi-Tenancy](multi-tenancy.md)

## PG-028: `FINAL` does not drop tombstones on `zod.public_billing_events`

- **Symptom:** Deleted rows still counted even though the query uses `FINAL`; or the opposite
  confusion — two docblocks in this repo disagreeing about whether `FINAL` removes tombstones.
- **Cause:** Whether `FINAL` treats `_peerdb_is_deleted = 1` as a deletion depends on the **engine
  declaration**, not on `FINAL`. `ReplacingMergeTree(_peerdb_version, _peerdb_is_deleted)` (the
  2-arg form) drops them; `ReplacingMergeTree(_peerdb_version)` (1-arg) only deduplicates.
  **`zod.public_billing_events` is the only 1-arg mirror of the 25 `zod.public_*` tables** — every
  other one, including `public_orgs`, `public_api_keys`, `public_hotlist` and `public_scans`, is
  2-arg.
- **Fix:** On `billing_events`, always filter `WHERE _peerdb_is_deleted = 0` explicitly; `FINAL` is
  not a substitute. On the other mirrors `FINAL` alone does remove tombstones, so both docblocks
  were "right" about different tables. Check the declaration in
  `database/schema/clickhouse-schema.sql` before assuming either behaviour.
- **See:** [Billing Events](billing-events.md), [ClickHouse Query Patterns](clickhouse-query-patterns.md),
  PG-002.

## PG-029: a local dashboard 500 is not production behaviour (the env gate)

- **Symptom:** One ClickHouse hiccup takes the whole Company Metrics grid down locally, and it looks
  like a production outage waiting to happen.
- **Cause:** Both `ClickhouseQuery::query()` and `SharedHitStatsData` are gated on
  `in_array(config('app.env'), ['production', 'testing'])` — they `report()` and degrade **inside**
  that gate and `throw $e` **outside** it. Under `APP_ENV=local` every card therefore propagates, so
  a local grid 500 says nothing about production. In production only the cards with no swallow
  anywhere propagate. **Re-measured against merged `main` after DEV-2651 (2026-09-22), and the set
  GREW**: `UniqueScanCounts` (two catch-free current-month legs since it absorbed `ScanCount`),
  `RecoveryCounts` (now `RecoveryTotalsBatchQuery::handle`), `UniqueScansLeaderboard`
  (`ScanPlateStatsBatchQuery::handle`), `MissedOpportunities` (Eloquent on `ClickhouseModel`), and
  the two bespoke funnel cards `unique-lpr-case-claims` and `lpr-repossessions` (deliberately, so a
  failure cannot render as a legitimate-looking `0`) — **six of twelve**, with
  `StagingLiveHitLeader` and `RecoveryLeader` mixed (some legs swallow, some propagate). The old
  "four of sixteen" figure is superseded.
- **The deciding factor is per LEG, not per card:** `ClickhouseQuery::query()` catches and returns
  `[]`; `ClickhouseQuery::handle()` never enters that wrapper. Grep the card for `::handle(` versus
  `::query(` rather than inferring from its class — and note the corollary, that a "did it fail?"
  guard written around `::query()` is **dead code**, because the failure became an empty array
  before the guard could see it.
- **Scope of the propagate guarantee:** it holds on the **synchronous** path only. `Cache::flexible`'s
  deferred stale refresh runs inside `rescue()`, so a failure there is swallowed and the stale value
  keeps serving. Counting a `Cache::flexible` card as unconditionally fail-loud is wrong.
- **Fix:** Reproduce with `APP_ENV=production` (or `testing`) before believing a blast-radius claim,
  and check the specific card's path — swallow, bypass-and-degrade, or propagate.
- **See:** [Company Metrics Dashboard](company-metrics-dashboard.md),
  [ClickHouse Query Patterns](clickhouse-query-patterns.md),
  [clickhouse-query-swallows-throwable](clickhouse-query-patterns.md).

## PG-030: `join_use_nulls = 0` — an unmatched `LEFT JOIN` yields a type default, not `NULL`

- **Symptom:** A funnel stage counts **more** than the stage above it, or a guard written as
  `WHERE x IS NOT NULL` / `IS NULL` never fires. On DEV-2464 a chained `LEFT JOIN` inflated a
  billing-adjacent figure by **~31%**.
- **Cause:** Production ClickHouse runs `join_use_nulls = 0` (confirmed in `system.settings`). Under
  that setting an unmatched left-join row is filled with the column's **type default** — `0` for
  integers, `''` for strings — not `NULL`. So `h.id` on a non-matching row is `0`, a perfectly valid
  value that then flows into the next join, matches other zero-filled rows, and multiplies. Every
  `NULL`-based guard is silently dead code.
- **Fix:** Guard on the **sentinel**, not on `NULL`: `AND h.id != 0`, `AND d.agent_org_id != 0`. Do
  this on **every** join key that a later stage consumes, not just the last one — the inflation
  comes from zero-filled rows joining to each other. Two further notes:
  - A test fixture will not catch this unless it deliberately contains a row whose key **is** the
    sentinel (e.g. an org with `org_id = 0`). Seed one on purpose; otherwise the guard is untested
    and a mutation removing it stays green.
  - Do not "fix" it by setting `join_use_nulls = 1` for one query — it changes every column's
    nullability and therefore the semantics of the whole statement.
- **Fingerprint:** if a downstream count exceeds an upstream one in a funnel, suspect this before
  suspecting the business logic.
- **See:** [ClickHouse Query Patterns](clickhouse-query-patterns.md),
  [ClickHouse Integration](clickhouse-integration.md), PG-028.

## PG-031: PostGIS `ST_Union` silently **drops** NULL members — emptiness is not a completeness check

- **Symptom:** A geometry assembled from several lookups saves successfully and looks healthy, but one
  of its inputs is missing from the shape. A guard written as "reject if the result is NULL or
  `ST_IsEmpty`" passes. On DEV-2252 a zone built from one resolvable ZIP plus one unresolvable ZIP
  saved with a valid, non-empty `geom` that silently covered only the first ZIP.
- **Cause:** `ST_Union` over an array or aggregate **skips** NULL members rather than propagating
  NULL. So *any* single member that resolves is enough to produce a valid, non-empty result. The
  emptiness of the output tells you only that **everything** failed — never that **something** did.
  The all-or-nothing intuition from SQL arithmetic (where `NULL + 1` is `NULL`) is exactly backwards
  here.
- **Fix:** Resolve the members **separately** and check the set, before or alongside the geometry
  check. Ask "which inputs produced no row?", not "is the union empty?".
  `Zone::rebuildGeometryFromDefinition()` is paired with `PersistZone`
  (`app/Services/Zones/PersistZone.php`), which queries `tiger_data.zcta5_all` for the requested
  codes **outside** the write transaction and refuses the save when any code has no usable boundary.
  `ManageZones::save()` turns that refusal into a persistent danger notification.
  `Materializer::unresolvedReferences()`
  (`app/Services/RunArea/Materializer.php`) does the same for `state`/`msa` references with an
  explicit `whereNotNull(<geometry column>)`, because a TIGER row can exist with a NULL geometry.
  Both refuse the write when that set is non-empty, and both name the offending inputs.
- **Two traps that come with it:**
  - **Run the lookup outside the write transaction.** A swallowed query error *inside* an open
    Postgres transaction poisons it, and the `COMMIT` then returns as a silent `ROLLBACK` underneath
    a success message — reintroducing the exact bug the guard exists to prevent. This was caught in
    review on DEV-2252, not in testing.
  - **A NULL geometry is schema-permitted, not corruption.** `tiger_data.zcta5_all`'s own constraint
    is `CHECK ((geometrytype(the_geom) = 'MULTIPOLYGON') OR (the_geom IS NULL))`. Seed a NULL-geometry
    row deliberately in fixtures; a "missing row" fixture does not exercise the same path.
- **Fingerprint:** a geometry feature where *some* of the requested areas are present and nobody got
  an error. Suspect this before suspecting the projection or the input parsing.
- **See:** [Zone](zone.md), [Run Area](run-area.md), PG-032.

## PG-032: `ST_Transform` cannot be exercised in PHPUnit — the harness holds `public.spatial_ref_sys`

- **Symptom:** A test covering a *resolvable* ZIP / state / MSA — the happy path — hangs
  indefinitely rather than failing. Switching the seed to a different connection to "fix" it makes
  the hang worse.
- **Cause:** `ST_Transform` must read `public.spatial_ref_sys` to look up the SRID. The test setup
  holds an `AccessExclusiveLock` on that table for the life of every test (confirmed on DEV-2252 by
  reading `pg_locks`), so any statement that reprojects blocks forever. Every ZIP→geometry and
  `state`/`msa`→geometry path reprojects NAD83 (4269) → WGS84 (4326), so this covers the whole happy
  path of [Zone](zone.md) and [Run Area](run-area.md).
- **Fix:** Do not try to defeat the lock. **Pin the failure paths in PHPUnit** — unresolvable input,
  NULL geometry, partial resolution — and move the resolvable-input happy path to browser QA, where
  it runs against a real database. Say so explicitly in the ticket; a reviewer will otherwise read
  the missing happy-path test as an oversight.
- **Related trap:** seeding `tiger_data.zcta5_all` on the `platform` connection instead of `zod`
  makes the suite hang rather than fail, because the `platform` connection then waits on the same
  `public.spatial_ref_sys` lock. Keep TIGER seeding on `zod`.
- **Fingerprint:** a geometry test that **hangs** instead of failing is a lock, not a slow query.
- **See:** [Testing Conventions](testing-conventions.md), [Zone](zone.md), PG-031.

## PG-033: Web catch-all swallows `/broadcasting/auth`

- **Symptom:** Otto job finishes in Horizon but the UI stays on "Thinking…" / Stop stays visible. Echo never receives `stream_start` / `text_delta` / `stream_end`. Browser Network shows `POST /broadcasting/auth` **302 → `/app/login`**. Local `/__qa/login` can also 404 if the catch-all is registered *before* that route (catch-all itself 302s; the QA route 404s when `loginUsingId` finds no `platform.users` row).
- **Cause:** `Route::any('{any}', … → /app/login)` matches every leftover path. `withBroadcasting()` registers `Broadcast::routes()` in `app->booted()`. `withRouting()` loads `web.php` earlier. If the catch-all is registered **before** broadcasting, Echo 302s. Scout's `/api/broadcasting/auth` is Sanctum-only and does not cover Filament session Echo.
- **Fix:** Keep `->withBroadcasting(..., ['middleware' => ['web']])` in `bootstrap/app.php`. Register the `{any}` catch-all in `AppServiceProvider` on a **later** `booted` callback so broadcasting wins. Hang-net: `OttoChat.vue` polls `OttoConversation::poll()` while thinking so a dead WebSocket still hydrates the persisted reply. Local QA login is `GET /__qa/login/{id?}` (needs `DEV_LOGIN_ENABLED=true`, `APP_ENV=local`, and a seeded `platform.users` row — `LocalDataSeeder` id 1).
- **See:** [Otto](otto.md), `docs/plans/otto.md`.

## PG-034: A hot/cold table split driven by a caller-supplied window silently loses rows

- **Symptom:** A list, pane, or map comes back **empty** for records the same application just reported finding — and the older the record, the more reliably it vanishes. Nothing errors; the count in the message and the rows on screen simply disagree.
- **Cause:** ClickHouse hot/cold pairs (`clarity.hits_hot` / `hits_cold`, `clarity.scans_hot` / `scans_cold`) are a *performance* split, not a completeness one: **hot is the last 90 days under a 90-day TTL; cold is all history.** `HitsListQuery::baseQuery($user, $windowStart)` chooses between them with `Carbon::parse($windowStart)->diffInDays(now()) <= 90`, so the choice is only as good as the window the caller passes. A caller that passes `now()` — the natural-looking "I have no window, just give me the query" value — always resolves to `true` and pins itself to the hot table. `InvestigationDataController` did exactly that while hydrating hit ids that `HitSearchTool` had legitimately read out of `hits_cold`, so Otto reported "here are the most recent N hits" over an empty table and map.
- **Fix:** Pass the window the *data* came from, not the current time. When hydrating ids, that is the originating search's own start (`InvestigationDataController::hitWindowStart()` takes the earliest across the searches being hydrated). When there is genuinely no window, pass **`null`** — every one of these helpers treats null as "cold table", which is slower and always correct. Never let `now()` stand in for "no window": it is the one value that means the opposite of what it looks like.
- **Generalize:** any `$useHot`-style branch is a correctness decision disguised as a performance one. Grep for callers passing `now()`, `today()`, or a default-initialized date into one.
- **See:** [ClickHouse Integration](clickhouse-integration.md), [Otto](otto.md), [Hit](hit.md).

## PG-035: Releasing a completion-signal cache key before the state it guards is written

- **Symptom:** A background job's result renders **partially** — the main content is there, but an associated widget, card, or side panel is missing. It is intermittent, disappears on refresh, and never reproduces under a debugger.
- **Cause:** A cache key is doing double duty as a mutex *and* as the "work finished" signal a poller reads. `otto:pending_prompt:{conversationId}` is the Otto case: `OttoConversation::poll()` treats its absence as "turn complete" and **immediately re-renders the whole thread from the database**. `RunOttoChat` used to `forget()` that key before the investigation, the proposed page actions, and the title were persisted — so a poll landing in that window read state that had not been written yet. At a 1.5s poll interval this is a real race, and it fires on exactly the path that exists because the WebSocket is down, so no broadcast covers it.
- **Fix:** Release the signal **last**, after every write the reader will look for, and keep any `finally`-block release as a backstop rather than the primary path. If a slow tail step (title generation, notification fan-out) makes that unacceptable, split the signal from the mutex rather than moving the release earlier.
- **Corollary — "done" needs a baseline.** The same poller called a turn complete whenever *any* assistant message existed in the thread, so a turn that died before persisting a reply reported success on the strength of the previous turn's answer. Capture a watermark when the work is dispatched (`OttoConversation::turnBaselineKey()`) and require the assistant-message count to advance. "Some result exists" is not "my result exists."
- **See:** [Otto](otto.md), [Jobs and Queues](jobs-and-queues.md).

## PG-036: Two renderers of the same text drift, and only one of them is ever watched

- **Symptom:** Content looks correct while it streams, then a refresh (or any server-side re-render) brings back markup, boilerplate, or leaked model narration that was not there a moment ago.
- **Cause:** Streamed assistant text is sanitized **twice by two independent implementations** — `stripToolCalls()` in `resources/js/otto/lib/markdown.js` for the live stream, and `MessageRenderer::stripToolCalls()` in PHP for every render from the database. Fixes land where the bug was seen, which is almost always the live stream, so the PHP list falls behind. Patterns that have drifted before: the GLM/DeepSeek `<｜…｜>` delimiters, Kimi's hallucinated `<div class="filter-confirmation">` card, and a `**Proposed filter changes:**` bullet list.
- **Fix:** Treat the two lists as one artifact — change both, and keep a comment in each pointing at the other. Do not assume the markdown pipeline covers it: `Str::markdown(..., html_input: 'strip')` removes the *tags* but keeps their **inner text**, so a stripped-out fake UI card comes back as its own bare text.
- **Generalize:** whenever a live path and a persisted path render the same content, the persisted path is the one nobody looks at. It needs the test.
- **See:** [Otto](otto.md).
