# Insight AI — Build Record · DEV-2442

**Client Analytics — Stats by Location / branch filter returns no data**

Route: bug · Base: `main` · PR: https://github.com/insight-lpr/platform/pull/374

> **Posted to Jira** as comment 39014 on 2026-09-02; **updated in place 2026-09-30** with the `clarity.hotlist.first_*_at` finding (§3a).

> **2026-09-30 update — the chlocal dependency is gone.** `clarity.hotlist` now carries `first_open_at` / `first_repossessed_at` (chlocal migration 116, mirrored by SCHEMA-DRIFT-2657) on the same row as `branch_id`. That gives the headline cards a transition-based, branch-joinable source that picks up late enrichment. The §3 blocker is **superseded** by §3a. The headline cards are now fixable from this repo; the open question is semantics, not feasibility.

---

## 1. What was wrong, and why

The branch/location filter has **never returned data for any org**. The filter code is correct; the column it filters on is empty.

`clarity.*.branch_id` derives from `zod.public.hotlist_json.json['branch_id']`. The only writer of that key is `App\Jobs\Rdn\GetOrgHotlistCaseInfo`, dispatched only by `App\Jobs\Rdn\GetOrgHotlistCases` — which had **no dispatch site on `main`**.

**It is a regression, not an unbuilt feature.** Verified by git archaeology:

| Commit | Date | Effect |
| --- | --- | --- |
| `b191c658c` | 2025-10-22 | Jobs created (PR #97, DEV-1145) |
| `6ca402d4b` | 2025-10-22 | Dispatch added to `GenerateRdnCache::queueBatch()` + `->doesntHave('case')` guard |
| `f67a59464` | 2025-10-24 | **Both still present** — verified directly with `git show` |
| `6092806dd` | 2025-11-07 | "Cleanup" — **removed both**, no ticket ref, no explanation |
| `433b46265` | 2025-11-10 | PR #97 squash-merged already missing them |

`f67a59464` is **not** an ancestor of `origin/main` — the squash is why a `git log -S` pickaxe over main finds nothing, and why an initial automated sweep wrongly concluded "dead code from birth."

The branch-filter UI shipped in that same PR, so it has never had a backend.

## 2. Why each change was needed

| # | Change | Why |
| --- | --- | --- |
| 1 | Non-destructive `INSERT … ON CONFLICT DO UPDATE` + `jsonb_set` | The old write rebuilt the whole blob via `HotlistJsonData` (declares only `branch_id`), so Spatie discarded every other key. Restoring the dispatch unchanged would have **irreversibly wiped 21 externally-owned keys** across up to 91M rows. |
| 2 | `$org` added to the dispatch call | `GetOrgHotlistCaseInfo::__construct` has `public ?Org $org` with **no default** — the original call would have thrown `ArgumentCountError` immediately. Proof it never ran, even on its branch. |
| 3 | RDN-only candidates | `remote_order_id` is unique only per `(org_id, remote_order_id, integration_id)`. Without the filter, a mixed-integration org can have RDN's answer for a **different order** written as its branch. Also removes 68% of volume. |
| 4 | Bounded query (window, cap, newest-first) | Unbounded, the first run sweeps ~95.5M rows, one RDN SOAP call each, no bulk endpoint. |
| 5 | Non-null enrichment predicate | A key-presence guard sealed transient misses permanently — the inverse of the goal. |
| 6 | Give-up counter, derived from the window | Bounds forever-retrying unresolvable rows without hand-tuning two numbers that can drift. |
| 7 | Transport failures don't count | With the ceiling derived to a small number, one RDN blip would otherwise seal a row permanently. |
| 8 | `rdn-case-info` limiter; `rdn-api` restored | An earlier fix stripped the only **per-call** throttle from four paginating siblings, on a false premise (the two layers never shared a bucket — `RateLimited` keys on `md5(limiterName.key)`). |
| 9 | Both `$timeout`s derived from `retry_after` | At the confirmed production concurrency of 2, a job outliving its 90s reservation is released and duplicated → duplicate SOAP call + double attempt increment. |
| 10 | Kill switch + `--force` | No way to stop this without a code revert; and no way to canary without turning it on fleet-wide. |
| 11 | Dispatch spread | 76k jobs in one 05:00 burst on a single-process shared queue that also carries transactional mail. |
| 12 | Observability | The branch match is exact string equality and has never executed in production; a 0% match rate had to be detectable in one run. |

## 3. The blocker (2026-09-02) — superseded 2026-09-30 by §3a

`public.log_status_changes` is an `AFTER INSERT OR UPDATE` trigger on `public.hotlist`:

```sql
IF NEW.status <> COALESCE(OLD.status, 0) THEN
    INSERT INTO hotlist_status_log (hotlist_id, old_status, new_status)
    VALUES (NEW.id, COALESCE(OLD.status,0), NEW.status);
END IF;
```

On INSERT, `OLD` is null ⇒ `COALESCE(OLD.status,0) = 0`, so a hotlist inserted at `status = Open(1)` writes a `(0 → 1)` row **at insert time**. That is exactly `HotlistStatusAggregate::scopeAccepted` (`new_status = Open AND old_status IN (Other, NewFromClient)`).

`clarity.hotlist_status_aggregate` is append-only with `branch_id` in its sorting key, and is permanently off-limits. Enrichment lands 24–48h later. Therefore those rows are frozen at `branch_id = 0` **permanently**.

**Measured (production, 14 days, transitions to Open):** `old_status = 0` → 2,455,313 (**91.87%**); `old_status = 90` → 35,142 (1.31%).

Result under a branch filter: **Assignments ~0 / Repossessions N / Recovery Rate 0%.**

Every platform-side alternative available on 2026-09-02 was tested and measured — see §5. At that time the fix required a chlocal-side change. **That conclusion no longer holds — see §3a.**

## 3a. The new route (2026-09-30): `clarity.hotlist.first_*_at`

chlocal migration 116 added `first_open_at`, `first_hold_at`, `first_repossessed_at`, `first_declined_at`, `first_closed_at`, `first_invalid_at` to `clarity.hotlist`. `hotlist_incremental_mv` computes each as `minIf(created_at, new_status = N)` over `zod.public_hotlist_status_log`, and re-emits a hotlist's row whenever its status log **or its `hotlist_json`** changes. So when enrichment writes `branch_id`, the row is rebuilt with both the branch and the transition timestamps. **Late branch data now reaches old transitions** — the property the append-only aggregate can never have. `LprRepossessionsQuery` already reads `first_repossessed_at` in production (DEV-2660).

**Measured (production, read-only, aggregates only):**

| Check | Result |
| --- | --- |
| Backfill | Populated back to 2022. Last 90d: 4,255,274 of 4,812,060 hotlists have `first_open_at` (88%; the NULLs are never-Open statuses) |
| Repossessions vs aggregate (Aug 2026, Clarity orgs) | 339,952 vs 340,154 — **0.9994**; per-org quantiles `[1, 1, 1]` |
| Opens vs aggregate (Aug 2026, 2,046 Clarity orgs) | 1,823,838 vs Accepted 1,791,172 (**1.018**) and vs all-Open 1,902,910 (**0.958**); 207 orgs differ > 5% |
| Cost, one large org, 12 months | 2,016,392 rows / **55 MB / 63 ms** (vs 4.6–4.7 GB for the status-log route) |
| `branch_id` today | Still 0 on every row — **this PR's enrichment remains the prerequisite** |

An initial unrestricted comparison showed 0.35. That gap was entirely 6 non-Clarity orgs (~10M hotlists each) that are in the aggregate but excluded from `clarity.hotlist` by the MV's `WHERE h.org_id IN clarity.orgs`. They never appear on this page.

**Why the numbers differ by a few percent** (exact decomposition, Aug 2026, Clarity orgs):

| Group | Hotlists | Aggregate counts it? | `first_open_at` counts it? |
| --- | ---: | --- | --- |
| Inserted/new → Open, first time, in Aug | 1,790,261 | Yes | Yes |
| **Reopened in Aug**, first opened earlier (e.g. Hold → Open) | 79,242 + 1,902 | Yes (any-Open count) | **No** — first time only |
| First opened in Aug, but from another status (e.g. inserted at Hold) | 79,577 | Only the any-Open count; **not** `scopeAccepted` (old ∈ 0/90) | Yes |

So a `first_open_at` count runs **~4% low** against the any-Open cards (reopens dropped) and **~2% high** against the Accepted cards (non-0/90 first opens added). The aggregate also uses `uniq` (approximate) where `clarity.hotlist` allows `uniqExact`, a smaller third source of difference.

**Limits:** `first_*_at` records the first transition only and drops `old_status`, so `scopeClosedCohort` / `scopeDeclined` (which filter on `old_status`) cannot be reproduced exactly.

**Open decision (needs a human):** use `first_*_at` only when a branch is selected (unfiltered numbers unchanged; a filtered view can differ from the unfiltered total by up to ~4%), or switch the cards to it always (one source everywhere; unfiltered numbers move 2–4% for every client).

## 3b. Exactness requirement and DEV-2892 (2026-09-30)

Decision (human): branch-filtered numbers must be **exact**, and only used when a branch is selected (unfiltered cards unchanged).

`first_*_at` is not exact for the reference org 14 (Brad Webb / Premier Adjusters), Mar–Sep 2026: Assignments −1.7% to −5.1% per month (reopens), Accepted +8.4% to +26.1% per month (367 of 4,180 August first opens came from Invalid 99), Repossessions exact. Excluding hotlists with any earlier non-Open status overcorrects to −7% to −12%, because many go 99 → 90 → Open.

A per-hotlist transition array (open times + from-status, repossession times) rebuilt from the status log reproduces the aggregate **exactly** for org 14: Mar–Aug, 0 difference on all three metrics. September (in progress) differs by 1–2, from the 15-minute refresh. Reading that at request time costs 5.4 GB, so it must be materialized.

**DEV-2892** (Sprint 42, blocks DEV-2442) adds `status_transitions Array(Tuple(at, old_status, new_status))` to `clarity.hotlist` via `hotlist_incremental_mv`, the same pattern as DEV-2657. DEV-2442 then points the headline cards at it under a branch filter.

Branch merged with `main` on 2026-09-30 (`cfbdbc00a`). It was committed directly with human authorization, because `checkpoint.sh` hard-blocks main's `.env.example` changes. The plate token in the DEV-2739 artifacts was redacted to `<plate>`.

## 4. Resolved questions

| Question | Answer | Source |
| --- | --- | --- |
| Why was the dispatch removed in `6092806dd`? | Not recalled — designed defensively as a result | human |
| Which option should ship? | Originally full backfill; withdrawn once `hotlist_status_aggregate` was confirmed unrebuildable; then MVP forward-only | human |
| Can `hotlist_status_aggregate` be fixed? | **No — off-limits, period.** A rebuild was measured tractable (387M-row source retained to 2022; 62.9M-row / 1.67 GiB target) and **rejected on purpose**, not on cost | human |
| Production `{low}` concurrency? | `HORIZON_SUPERVISOR_2_PROCESSES = 2` per host; host count is a deploy-time env var, so 2 is the **floor** | human |
| Must the metrics be transition-based? | **Yes, hard requirement** — which eliminates the creation-based alternative | human |
| Is it universal or Action-Towing-specific? | Universal — 0 of 95,542,923 rows | measured |
| Do sibling filters share the flaw? | No — client/lienholder dropdowns derive options from the same column they filter on, so they're immune by construction | code |
| Does the MV chain propagate `branch_id`? | Yes, verified end-to-end. The MV layer is **not** implicated | measured |

## 5. How every number was derived

All against production ClickHouse via the read-only `readonly_user`. **Aggregates only — no raw rows.**

| Query shape | Result | What it grounded |
| --- | --- | --- |
| `countIf(JSONHas(json,'branch_id'))` over `zod.public_hotlist_json` | 0 of 95,542,923 | The root cause |
| `countIf(branch_id != 0)`, `uniqExact(branch_id)` × 7 consumer tables | 0 nonzero, 1 distinct, each | Universality |
| `JSONHas(properties,'rdn_rate_limit')` over `org_meta` | 345 of 23,029 configured; all value `100` | Rate-limit posture |
| `public_hotlist` grouped by rate-limit posture | 139,807,840 rows / 3,148 orgs unthrottled | Blast radius |
| `system.tables.create_table_query` on the MV chain | `branch_id` propagates via `JSONExtract` → `argMax` | Cleared the MV layer |
| 14-day arrivals by `integration_id` | RDN 76,307/day (1,781 orgs); other 160,997/day (69 orgs) | The RDN filter (−68% volume) and the MVP sizing |
| Mocked-SOAP throughput, real worker, 1 process | 250ms→226.5 jpm · 500→116.6 · 1000→59.2 · 2000→29.8 | Resource envelope; ×2 for prod concurrency |
| Candidate query `EXPLAIN (ANALYZE, BUFFERS)` at 50k cardinality | Bitmap scan → Sort 47,928 rows, 56.5ms worst case | Confirmed the SAOP sort finding; refuted it as a cost problem |
| `jsonb_set` write with/without GIN | +37–48% relative, **≤0.062ms absolute** | Refuted GIN amplification on magnitude |
| Status-log `old_status` distribution for `new_status=1` | **91.87% INSERT-synthesised** | **The blocker** |
| Transitions vs distinct hotlists (30d) | 5,563,130 vs 5,390,106 | Semantics of the alternative |
| Lag from `created_at` to non-INSERT Open transition | median 715h, p95 7,134h | Why creation-based is not equivalent |
| Status-log ⋈ `clarity.hotlist`, one org, 30d | 0.86s, **392,347,098 rows, 4.70 GB** | Exact-semantics route unaffordable |
| Same, pre-filtered by the org's hotlist ids | 388,652,195 rows, 4.58 GB, 4.25s | Pre-filtering does **not** prune |
| `count()` from `clarity.hotlist` | 0.01s, 211,974 rows | The cheap route — but creation-based |
| *2026-09-30:* `countIf(first_open_at IS NOT NULL)` etc. on `clarity.hotlist`, 90d + by year | 88% populated; backfilled to 2022; `branch_id>0` = 0 | The new columns are usable; enrichment still missing |
| *2026-09-30:* per-org `uniqMergeIf` on the aggregate vs `uniqExactIf(id, first_*_at in window)` on `clarity.hotlist`, Aug 2026 | Opens 1.018× Accepted / 0.958× all-Open; repos 0.9994 | §3a equivalence |
| *2026-09-30:* same, unrestricted by `clarity.orgs` | 0.35 fleet ratio from 6 non-Clarity orgs | Ruled out a data gap |
| *2026-09-30:* status log ⋈ `clarity.hotlist`, split by old-status bucket × first-open timing | 1,790,261 / 81,144 reopens / 79,577 non-0/90 first opens (one-off, 7.6 GB) | The drift decomposition |
| *2026-09-30:* `uniqExact(id)` on `clarity.hotlist`, one large org, 12 months, with/without `branch_id` | 2.0M rows / 55 MB / 63 ms | §3a is affordable |

## 6. Tests

`php -d memory_limit=2G vendor/bin/phpunit --filter='GetOrgHotlistCaseInfoTest|GetOrgHotlistCasesTest|RdnApiRateLimiterTest|PullRdnOrgClientsTest|ClientTableBranchFilterTest|RDNTest|RdnJobTest'` → **130 passed / 366 assertions**.

Full suite: 1,671 tests, 3 failures **verified pre-existing** by re-running with all app changes reverted to `diff_base` (`e03d4e6a0`) — identical failures.

Every fix was mutation-checked: each new test confirmed to fail when its specific fix is reverted.

## 7. QA performed

Provisioning reused a standing isolated env (branch Postgres DB + testing DB + Docker ClickHouse with literal `clarity`/`zod` databases + Herd site); no rebuild.

**Agent QA: AC1–3 SATISFIED** against seeded data across 3 real branches including a negative control, verified three ways (raw ClickHouse SQL, live Eloquent scopes, in-browser). Backend verified: `--force` canary dispatches with the kill switch off and no-ops without it; key preservation on **both** write paths; the seal fires; failing SOAP calls hit the transport-failure path leaving data untouched.

**Caveat, and it matters:** the seed wrote `branch_id` directly into ClickHouse. It therefore validated *the end state*, not *the pipeline* — enrichment latency versus an INSERT-time transition was never exercised. **That is why QA passed while the blocker in §3 went undetected.** The QA scope was set by the driver; this is a process finding, not an agent-QA defect.

**Resource envelope: measured.** Initially FAIL against the repo default of 1 worker process; **revised to PASS** on confirmation that production runs 2. Binding threshold moves 363ms → ~726ms; ~45% headroom at the assumed 500ms. That confirmation also un-masked the live `retry_after` defect fixed in change #9.

## 8. Review

11 rounds. Round 11 (3 in-session lanes, after `review.sh` returned `Not logged in` — provenance recorded honestly as `in-session-agent`, not fabricated): **80 / 88 / 72**.

Gate threshold is 90; **not met**. Open findings are listed on the PR. Notably, four separate rounds surfaced defects introduced by driver-issued instructions — the key-presence guard, the FIFO reordering, the `gatedByQueue` premise, and the deferral of `retry_after` on a wrong single-process assumption.

## 9. Not done

- Assignments/Accepted/Recovery Rate under a branch filter — **now fixable in this repo via `clarity.hotlist.first_*_at` (§3a)**; not yet implemented, pending the semantics decision
- `RdnJob`'s four paginating siblings share the `$timeout` vs `retry_after` window — pre-existing, needs a redesign
- Per-org cap silently truncates high-volume orgs (`capped` is logged, nothing alerts)
- `okf/concepts/rdn.md`, `okf/models/hotlist_json.md`, `okf/concepts/jobs-and-queues.md` are stale — queued for the post-approval wiki pass per the WRITE rule
- Follow-up ticket **DEV-2892** (chlocal `status_transitions` column) created 2026-09-30 at the human's request; it blocks the headline-card work
- ~~Rebase required~~ — merged with `main` 2026-09-30 (`cfbdbc00a`)

## Implement RESUME — Soft #18 + Cloud RO (2026-10-07 10:38 CDT)

### Schema mirror
- clarity.hotlist_status_transitions + _mv into database/schema/clickhouse-schema.sql (Cloud RO SHOW CREATE; ReplacingMergeTree ORDER BY (org_id, created_at, id); Docker Soft #18 has table only, no 15m REFRESH MV).

### Models
- HotlistStatusTransition: forOrg (stashes orgId), forBranch/forClient via hotlist FINAL join, between() with toStartOfHour UTC, uniqExact(hotlist_id).
- HotlistStatusSource: usesTransitions when branchId set; totalExpression uniqExact vs uniqMerge.

### Consumers (branch-only switch)
Widgets/charts/batch/drilldown call HotlistStatusSource::query(branchId) — unfiltered path unchanged on aggregate.

### Soft #18 Path A
Ports Clarity :18094 · PG :15446 · CH :18137. Suite filter GetOrgHotlistCaseInfo|Cases|HotlistStatusTransitionBranchFilter → **96 passed / 278 assertions**.

### Cloud RO perf (org 165, 12-mo, branch=0)
- Interleaved 3 cards: ~490–643ms elapsed each.
- One-query page (assignments+accepted+repos): **471ms** wall — prefer over per-card caching.
- Counts: assignments 2012221, accepted 1768882, repos 5418.

### Round-11 (prior commit fa390fa6)
newest delay 0 · ShouldBeUniqueUntilProcessing · --force requires org · try/finally · prune-failed · Branch ORDER BY id · cap unused-retry→fresh.

---

## Act-on rn=12 (2026-10-07)

Tip `a33571412356a440c45f99181b755cde90d1a279`. Soft #18 Path A 139 passed / 503 assertions; pint dirty=0; ports :18094/:15446/:18137 LEFT UP.

1. CH tests for transitions consumers (batch/sparkline/drilldown/byMonth + scopes)
2. NULL/0/deleted/cross-org isolation seeds
3. `created_at < window_end` + filtered `h` on groupBy*
4. Unused HotlistStatusAggregate imports removed
5. Run summary: real `dispatched` + `candidates`/`failed`/`suppressed`
6. Product commit without `[skip ci]`; agent identity

## Act-on rn=13 (2026-10-07)

Closed Sam should-fixes #1–#7: hour-bucket `created_at` bound + midnight last-hour test; single filtered `h` (`applyJoin: false` on batch/sparkline); HasStatDrillDown branch consumer; mid-month `setTestNow`; drop RequiresClickhouse swallow-all catch; head without `[skip ci]`; driver-branch Days-to-Repo DDL. Soft #18 Path A **141 / 508**. Stay draft.

## Act-on rn=14 (2026-10-07)

Product tip `d5fefbbb7944bf25b343b05394b178fe6eb52daf`. Soft #18 Path A **143 / 526**; pint dirty=0; ports :18094/:15446/:18137 LEFT UP; driver=clickhouse.

1. `HotlistStatusAggregate::scopeForBranch` / `scopeForClients` — ignored `bool $applyJoin = true` (signature parity with `HotlistStatusTransition`; Aggregate has no filtered-h join path)
2. Unfiltered `ClientRankedMetricsBatch` / `BatchMonthlySparklineData` `handle()` Feature tests with `branch_id` null/0 — seed aggregate, assert per-client counts non-zero (fails if Aggregate rejects named `applyJoin`)

Stay draft · no QA Ready.
