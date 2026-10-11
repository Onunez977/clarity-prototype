# DEV-2442 — Implementation Plan

> **Scope change — node t5 ("honest UI") was withdrawn by the ticket owner and is NOT in
> this PR.** Disabling the branch filter behind an `org_meta` readiness flag was never part
> of the ticket's ask ("make the branch filter work"); it was an orchestrator addition that
> drifted the scope, and it was the single largest source of review findings across four
> rounds. Everything t5 introduced has been deleted: the `branch_analytics_ready` and
> `branch_enrichment_started_at` flags, `Org::isBranchAnalyticsReady()` /
> `branchEnrichmentStartedAt()`, the readiness evaluation in `GetOrgHotlistCases`, the
> `branch_analytics_ready_months` config key, and the `->disabled()` / `->helperText()` on
> the `branch_id` Select. **The branch filter is always enabled.** Enrichment (t1–t4) is
> what makes it start returning real data, filling in as it runs. Every t5 mention below is
> retained only as a record of what was planned — read it as withdrawn.

**Decision (owner, already approved):** Option **C+B — forward-only restore + honest UI**.
Supersedes the earlier Option A (full historical backfill), withdrawn once
`clarity.hotlist_status_aggregate` was established as unchangeable.

**Standing approval.** The ticket owner has already approved *this specific work* — a
forward-only restore with a bounded first run. Nodes tagged `scheduled_command` /
`external_api_dispatch` below (t1, t2, t3, t4) therefore carry that standing approval and do
not need a fresh hard-stop, **except** for the one item flagged in "Open for review" at the end.

---

## Two corrections to the brief (both load-bearing, both measured)

The brief asked for a verbatim restore of two lines. Neither can be restored verbatim, and
restoring them as written would produce a silent no-op plus production data loss. Both were
verified against production ClickHouse (read-only aggregates, counts only).

### 1. `->doesntHave('case')` is not restorable and would silently skip ~half the fleet

At `f67a59464`, `Hotlist::case()` pointed at a dedicated `App\Models\HotlistCase` model
(its own table, written *only* by this job). The squash-merge of PR #97 (`433b46265`)
replaced it with `hotlistJson()` → `App\Models\HotlistJson`, a **shared sidecar with an
external writer**. Measured:

| Measurement | Value |
| --- | --- |
| `hotlist_json` rows created in the last 1 day | **135,604** |
| `hotlist` rows created in the last 1 day | **273,924** |
| → share of new hotlists that already have a row before this job runs | **~49.5%** |
| live `hotlist_json` rows with a **non-empty** json blob | **95,542,900 / 95,542,925** |

So `->doesntHave('hotlistJson')` would permanently and silently skip about half of all new
hotlists. The correct forward-only guard tests for **key absence inside the blob**:

```php
->whereDoesntHave('hotlistJson', fn (Builder $query) => $query->whereRaw("jsonb_exists(json, 'branch_id')"))
```

`jsonb_exists()`, not `whereNull('json->branch_id')` — `GetOrgHotlistCaseInfo` legitimately
writes `branch_id => null` when RDN's `vendor_branch_name` matches no `Branch.branch_name`
by exact string (`GetOrgHotlistCaseInfo.php:76-82`). A null must count as *enriched*, or the
job re-calls RDN for that row every day forever.

Both `Hotlist` (`app/Models/Hotlist.php:104`) and `HotlistJson`
(`app/Models/HotlistJson.php:14`) are on the `zod` connection, so this is a same-connection
subquery — not the cross-connection trap that silently returns empty.

### 2. NEW BLOCKER — the restore as written destroys production data

`app/Jobs/Rdn/GetOrgHotlistCaseInfo.php:88-91` rebuilds the entire json blob through
`HotlistJsonData`, which declares **only** `?int $branch_id` (`app/Dto/HotlistJsonData.php`).
The `firstOrNew` → `toArray()` → `from(array_merge(...))` → `save()` round trip therefore
**deletes every other key**. Production key histogram of `zod.public_hotlist_json`:

| key | rows |
| --- | --- |
| `case_number` | 91,209,893 |
| `vehicle_color` | 87,573,754 |
| `description` | 81,546,891 |
| `entering_agency` | 57,322,782 |
| `expires_at` | 12,025,695 |
| `plate_type` / `file_type_decode` / `plate_expired_at` / `title_number` | 2,776,976 each |
| + ~11 more (`vin`, `vehicle_type`, `vehicle_year/model/make`, …) | |

This is not hypothetical: restoring the dispatch without fixing it wipes these keys on
every row the job touches (~196k on the first run). **t1 is a hard prerequisite for t3** and
is wired that way in the DAG.

Recommended fix: a targeted `jsonb_set` update that touches only the `branch_id` key, with an
insert fallback when 0 rows are affected. Rejected alternative: widening `HotlistJsonData` to
enumerate the observed keys — the blob is externally owned, so any enumeration is stale the
moment a new key appears.

---

## The bound (the single most important design decision)

**Chosen:** `->open()` + a **7-day** recency window + a **500-per-org-per-run** cap, ordered
`created_at DESC`, both values configurable, the cap additionally overridable per org.

```php
$this->org->hotlists()
    ->open()
    ->where('created_at', '>=', now()->subDays(config('services.rdn.case_info_lookback_days')))
    ->whereDoesntHave('hotlistJson', fn (Builder $query) => $query->whereRaw("jsonb_exists(json, 'branch_id')"))
    ->orderByDesc('created_at')
    ->limit($this->maxPerRun())
    ->cursor()
```

### What it costs (measured, production)

| Scenario | RDN SOAP calls |
| --- | --- |
| Unbounded first run (the hazard) | **95,542,923** |
| 7d window, all statuses, cap 500 | 263,854 |
| **7d window, open only, cap 500 — the chosen bound, FIRST RUN** | **196,324** |
| **Steady state (1d inflow, open only, cap 500) — upper bound** | **≤ 57,144 / day** |

**487× reduction** on the first run. The steady-state figure is an upper bound; the actual
number is lower because already-enriched rows in the window are skipped by the guard.

### Why each piece

**`->open()` — does double duty, and this is the load-bearing justification.**

*Index alignment.* The only usable index is `hotlist_org_id_status_created_at (org_id, status,
created_at)`. There is **no** plain `(org_id, created_at)`. Without `status` in the predicate,
`WHERE org_id = ? AND created_at >= ? ORDER BY created_at DESC` falls back to
`ix_hotlist_org_id` and then **sorts** — 17.3M rows for the largest org, on a 135.3M-row table.
That alone is a production incident. With `->open()` it becomes an O(limit) backwards index
range scan.

*Semantic exactness.* `clarity.hotlist_status_aggregate_mv` is `REFRESH … APPEND TO`, driven
solely by `public_hotlist_status_log`'s watermark. A **future status-log row is the only path**
by which `branch_id` ever reaches the Total Assignments / Total Repossessions cards — and only
**open** orders can still produce one. Enriching a closed order cannot ever surface on those
cards. So the window is not a compromise; it is precisely the set of rows where enrichment can
still pay off.

**7 days, not 1.** The command runs `dailyAt('5:00')` (`routes/console.php:24-29`). A 1-day
window means a single missed or failed nightly run orphans that day's hotlists *permanently*.
7 days gives six days of self-healing slack at a first-run cost of 196k instead of ~57k, paid
once.

**500 per org per run.** Per-org new-hotlist distribution (1-day): p50 = 20, p90 = 113,
p99 = 1,485, **max = 69,667**. A cap of 500 fully covers p90 and most of the long tail while
preventing one org from starving the other ~1,440 — which matters acutely because Horizon's
`{low}` supervisors default to **1 process each** (`config/horizon.php:214,219`). At 500, only
132 of 1,512 active orgs are capped in a 7-day window.

**Configurable, not magic.** `config('services.rdn.case_info_lookback_days')` (default 7) and
`config('services.rdn.case_info_max_per_run')` (default 500), env-overridable, plus a per-org
override at `org_meta.properties['rdn_case_info_max_per_run']` mirroring the established
`rdn_rate_limit` override (`app/Providers/AppServiceProvider.php:85`, `app/Services/RDN.php:140`).
That per-org override is how Action Towing gets dialled up for AC-4 verification **without**
changing fleet-wide exposure.

### Honest limitation

For the very largest orgs the sliding window means un-enriched rows age out permanently — the
max org creates ~59k open hotlists/day against a 500/run cap, i.e. ~0.8% coverage. That is
correct behaviour for "forward-only, best-effort, bounded", and it is exactly why the honest-UI
node (t5) matters: the filter should not claim to work until someone has measured that org's
actual coverage.

---

## DAG

```
t1  Non-destructive + idempotent GetOrgHotlistCaseInfo  ─┐
t2  Bound + corrected guard in GetOrgHotlistCases  ──────┼─→ t3  Restore the dispatch
                                                   └─────→ t4  Default rate limit (100/min)
t5  Honest UI (independent — can ship first, or alone)
```

| id | title | complexity | blast_radius | ui |
| --- | --- | --- | --- | --- |
| t1 | Non-destructive + idempotent `GetOrgHotlistCaseInfo` write | complex | `external_api_dispatch` | no |
| t2 | Bound `GetOrgHotlistCases` (window + cap + corrected guard) | complex | `external_api_dispatch` | no |
| t3 | Restore the `GetOrgHotlistCases` dispatch in `queueBatch()` | simple | `scheduled_command`, `external_api_dispatch` | no |
| t4 | Default `rdn-api` limiter to 100/min for unconfigured orgs | simple | `external_api_dispatch` | no |
| t5 | Honest UI — disable the branch filter behind an `org_meta` flag | simple | — | **yes** |

`t1`, `t2`, `t5` are parallel and have fully disjoint `target_files`. `t3` and `t4` serialize
behind them. `uat_needed: true` (t5).

`t3` is deliberately last: the batch is only re-armed once the destructive-write fix **and**
the bound are both in place.

---

## Tests — pinning the exact gap

The regression shipped in PR #97 and went unnoticed for ~10 months because nothing asserted the
*composition of the batch*. Existing branch-filter tests
(`tests/Filament/Widgets/Client/ClientTableBranchFilterTest.php`) assert only type coercion and
SQL shape.

- **t3** adds the missing assertion to the file that already drives this command —
  `tests/Feature/Console/Commands/PullRdnOrgClientsTest.php` (misleading name, correct file;
  it already calls `Artisan::call(GenerateRdnCache::class)` with `Bus::fake()`). No new
  `GenerateRdnCacheTest.php`.
- **t2** adds `tests/Feature/Jobs/Rdn/GetOrgHotlistCasesTest.php` (new) covering the guard, the
  window, the status filter, the cap, and the per-org cap override.
- **t1** adds a **key-preservation** test (seed `case_number` + `vehicle_color`, assert they
  survive) and a **no-SOAP-when-already-enriched** test (`->shouldReceive('__soapCall')->never()`).

No `TEST_DB_CAVEAT` was supplied and `tests/TestCase.php` uses `RefreshDatabase` against
`zod_testing`, so DB-backed feature tests are fine. `jsonb_exists` is Postgres-native and the
test DB is PostGIS/Postgres — the guard is exercisable in tests.

---

## Sizing check

6 app files + 4 test files, across **two** concerns:

1. **The pipeline restore** (t1–t4) — `GetOrgHotlistCaseInfo.php`, `GetOrgHotlistCases.php`,
   `GenerateRdnCache.php`, `config/services.php`, `AppServiceProvider.php`.
2. **The honest UI** (t5) — `Org.php`, `ClientAnalytics.php`.

This is marginally over the ~6-file guideline. **It is not recommended to split t1–t4** —
they are one indivisible safety story, and shipping any subset is worse than shipping none
(t3 without t1 destroys data; t3 without t2 sweeps 95.5M rows).

**t5 is cleanly separable** and has zero code dependency on t1–t4. If the reviewer wants a
smaller first PR, split there: ship t5 alone (small, low risk, stops the dashboard reporting a
false business fact immediately), then t1–t4 as the pipeline PR. Both orderings are safe
because t5's flag defaults to `false`.

---

## Rollout prerequisites (ops, not code)

- **Horizon `{low}` process count.** Defaults to 1 process per supervisor
  (`config/horizon.php:214,219`). At ~500 ms per SOAP round trip that is ~2 calls/s, so the
  196k first run takes ~27 h and the 57k/day steady state takes ~8 h/day on one process. Either
  scale the supervisor for the first run or start with `RDN_CASE_INFO_MAX_PER_RUN` dialled down
  and raise it. The cap is configurable precisely so this is a dial, not a redeploy.
- **First-run soak.** Recommend deploying with `RDN_CASE_INFO_MAX_PER_RUN=50` for the first
  night, confirming key preservation and RDN error rates on real traffic, then raising to 500.
- **AC-4 (Action Towing, 2+ branches)** stays **deferred**. It cannot be verified on day one for
  any org; it becomes verifiable for a chosen org after a few nightly runs, accelerated via that
  org's `rdn_case_info_max_per_run` override.

---

## Open for review

1. **The 100/min default (t4).** Flagged driver assumption. 100 is the only value present in
   production (all 345 configured orgs), and 98.5% of orgs currently run under `Limit::none()`.
   t4 is deliberately isolated so it can be dropped without touching t1–t3; the plan's safety
   does not depend on it.
2. **Ops-set vs auto-flip for `branch_analytics_ready` (t5).** The plan makes it a deliberate
   human gate rather than having the job auto-flip it, because forward-only coverage is genuinely
   poor for the largest orgs and someone should measure before the product tells a customer their
   branch filter works. Auto-activation is a reasonable follow-up; it is **not** in this plan.
3. **`jsonb_set` vs widening the DTO (t1).** The plan recommends `jsonb_set`. The constraint the
   implementer must satisfy either way is *unknown keys survive, proven by test*.

## Queued for the post-merge wiki pass (do NOT write now)

- `okf/concepts/rdn.md` describes the case-info pipeline as operative. It has never run in
  production. Correct after approval, per CLAUDE.md's WRITE rule.
- `okf/models/hotlist_json.md` says the blob "currently holds `branch_id`". Measured: 0 of
  95.5M rows carry that key, while ~20 other keys are present. The page understates the blob's
  real, externally-written schema — which is precisely the misreading that hid the data-loss bug.
