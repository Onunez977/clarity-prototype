# DEV-2928 prep-context

**Gate:** **CLEAN**
**Re-GET:** 2026-10-07 ~4:00pm CDT · Jira To Do · Task · Sprint 42 (active) · Application Clarity · epic DEV-1580 · assignee Dalton McCleery · relates to DEV-2442 (QA Ready)
**First Glance:** comment **43357** (3:57pm CDT, fresh) — Branch path present
**Branch path:** stacked on PR #374 head `claude/DEV-2442` → base `claude/DEV-2442`; retarget to main after #374 merges (not this phase). **Never push to / edit #374 or `claude/DEV-2442`.**
**Base SHA:** `42a4310fc9b76768f51caa3dbc42099b02331704` (`origin/claude/DEV-2442` tip, matches FG)
**Branch:** `cursor/dev-2928-rdn-backfill-branches` (upstream tracking to `claude/DEV-2442` removed; pushes use explicit refspec)
**Reach #31:** passed (DEV-2801 drill) · Path A for this key: **future** (not stood)

## Intent (restated)

DEV-2442 (#374) restores forward-only RDN case-info enrichment (`GetOrgHotlistCaseInfo` writes `branch_id` into `hotlist_json`) and routes branch-filtered Client Analytics status cards to `HotlistStatusTransition` (read-time `clarity.hotlist FINAL` branch join). Existing hotlists have no `branch_id`, so branch filters show only recent data. This ticket adds: (1) an operator backfill command that enqueues the **unchanged** enrichment job for an org's RDN hotlists with activity in a window, throttled + resumable, with a no-write dry run that reports outcomes per age bucket; (2) a normalized branch-name fallback after exact match; (3) query-time current-branch filtering for **LPR Assists** and **Missed Opportunities** (today they filter on `branch_id` copied at row-write time); (4) a PR runbook.

## Live AC (Jira wins)

1. Backfill command exists with options `{org} --since= [--until=] [--chunk=500] [--max=] [--dry-run=N]`; dry-run writes nothing and prints per-bucket outcome counts (resolved / no_branch_name / no_branch_match / transport_failed) and unmatched `vendor_branch_name` values beside the org's branch names.
2. Running it enqueues `GetOrgHotlistCaseInfo` only for RDN hotlists with activity in the window (status transition or repo date in window, or currently Open/Hold), not already resolved/sealed, newest→oldest, in throttled chunks (push only while queue short; job `retryUntil` ~48h), on a dedicated queue (e.g. `rdn-backfill`, not `{low}`) + Horizon supervisor entry; resumes from a per-org cursor when re-run.
3. Branch match succeeds for names differing only by case / whitespace / punctuation; exact match still wins.
4. With a branch selected, LPR Assists and Missed Opportunities count rows whose hotlist **currently** has that branch (incl. hotlists enriched after the row was written). All Branches numbers unchanged.
5. Tests: selection window, chunking/resume, dry-run no-write, normalized matching, query-time branch filter for both cards.

## Surface scout (on `42a4310f`)

| File | Role |
| --- | --- |
| `app/Jobs/Rdn/GetOrgHotlistCaseInfo.php` | Enrichment job (unique-until-processing, outcome counters L43–56, exact branch match L351–366 `Branch::where('org_id')->where('branch_name', vendor_branch_name)`, writes `hotlist_json.json.branch_id` L442+) — reuse dispatch contract unchanged; normalized fallback is the only in-job edit candidate |
| `app/Jobs/Rdn/GetOrgHotlistCases.php` | Existing forward-only selection/dispatch (L175 `new GetOrgHotlistCaseInfo(`) — pattern for selection SQL / eligibility predicates |
| `app/Services/RDN.php` | RDN client (case info call) — dry run calls RDN directly, writes nothing |
| `app/Clickhouse/Models/HotlistStatusSource.php` | branch → transitions selector (pattern) |
| `app/Clickhouse/Models/HotlistStatusTransition.php` | `scopeForBranch` L58+: `hotlist_id IN (SELECT id FROM hotlist FINAL WHERE org_id = ? AND _peerdb_is_deleted = 0 … AND ifNull(branch_id, 0) = ?)` — template |
| `app/Clickhouse/Models/LprAssist.php` | `buildWhereClause` L30 `AND r.branch_id = {$branchId}` (copied-at-write) → current-branch subquery; `recoveries` has no `hotlist_id` column — verify `r.id` == hotlist id (hint: `RecoveriesKitUserScansReport` maps `recovery_id`→`hotlist_id`) |
| `app/Clickhouse/Models/MissedOpportunity.php` | `scopeForBranch` → `where('branch_id', …)` (copied-at-write, `UInt32 DEFAULT 0`) → `hotlist_id IN (…)` |
| Consumers (all Client Analytics) | `Client/LprAssistsWidget`, `Client/MissedOpportunitiesWidget`, `Client/Lifetime/Lifetime{LprAssists,MissedOpportunities}Widget`, `Pages/Concerns/HasStatDrillDown` (L330, L381), `Queries/Client/ClientRankedMetricsBatch` (L114, L132), `Queries/Client/BatchMonthlySparklineData` (L95, L114) |
| `config/horizon.php` | supervisors L197–290 (`{default}`,`{high}` / `{low}` / `{otto}`) — add one dedicated supervisor entry |
| `routes/console.php`, `app/Console/Commands/*` | command home (closure vs class — follow neighbors) |
| `database/schema/clickhouse-schema.sql` | `clarity.hotlist` (L24, `branch_id Nullable(Int64)`), `recoveries` (L34), `missed_opportunities` (L33), `lpr_assists` view (L89), `hotlist_incremental_mv` (L104, REFRESH EVERY 1 MINUTE) — **no edits** |
| Tests | `tests/Feature/Jobs/Rdn/GetOrgHotlistCaseInfoTest.php`, `GetOrgHotlistCasesTest.php`, `tests/Feature/Clickhouse/HotlistStatusTransitionBranchFilterTest.php` (pattern), LprAssist / MissedOpportunity / widget tests |

## OKF to read

`okf/concepts/{data-architecture,clickhouse-integration,clickhouse-query-patterns,client-analytics,lpr-assist,missed-opportunity,rdn,rdn-provider-integration,jobs-and-queues,testing-conventions}.md` · `okf/models/{branches,hotlist_json}.md` · `okf/models/clickhouse/{hotlist,recoveries,lpr_assists,missed_opportunities,hotlist_status_aggregate}.md`

## Hard stops

- Never push to / commit on / edit PR #374 or `claude/DEV-2442`.
- Cloud CH READ-ONLY (measure only). No CH writes, no MV / schema changes.
- No production backfill run; no fleet-wide run.
- Reuse `GetOrgHotlistCaseInfo` dispatch contract unchanged; no new services/layers beyond command + queue/supervisor entry (#24).
- Out of scope: alias table · `HotlistStatusAggregate` / All Branches path · RDN ingestion outage since 2026-09-02.
- Spec test map `ch_touch: yes` (#31). Path A future (stood in Provision).

## Forecast gates

1. #25 parity: All Branches byte-identical before/after; branch-filtered LPR Assists / MO match transition cards' current-branch semantics — **Cloud CH READ-ONLY prod-cardinality measure planned** (filed in PR + Build Record).
2. HITL with Dalton before undraft (query + UI change) — open gate.
3. Retarget base to main after #374 merges (not this phase).
4. #18 real fleet Docker CH for Agent QA / Verify; #27 UI evidence if visible change.

## Material open questions blocking planning

None. (Non-blocking items forwarded to the Planner as open questions: in-job normalized match vs "job unchanged" wording; `recoveries.id` ↔ hotlist id proof; cursor storage location; queue-length threshold.)

## Preflight cites

#17 · #23 · #24 · #25 · #26 · #30 · #31 · #18 · #29
