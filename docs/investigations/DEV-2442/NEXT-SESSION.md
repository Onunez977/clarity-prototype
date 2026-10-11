# DEV-2442 — next-session handoff (2026-10-07)

Resume DEV-2442 (Client Analytics — branch/location filter returns no data) with `/ticket DEV-2442 --resume`.
Work in the platform repo on branch `claude/DEV-2442` (draft PR https://github.com/insight-lpr/platform/pull/374).
HEAD is 3d10c536b; the branch is ~66 commits behind `origin/main`. state.json says phase=implemented,
review_number=11, rerun_required=true. Read docs/investigations/DEV-2442/{BUILD-RECORD.md §3, §3a, §3b,
journal.md tail, derivation-log.json last ~10 entries} before you act. They are the source of truth;
this file is a summary.

## Where things stand

- The PR already restores the RDN branch-enrichment pipeline (GetOrgHotlistCases → GetOrgHotlistCaseInfo
  writes hotlist_json.branch_id): non-destructive jsonb_set write, RDN-only candidates, kill switch
  RDN_CASE_INFO_ENABLED, `app:rdn <org> --force` canary, derived timeouts, dispatch spread.
  130 tests / 366 assertions on the touched surface.
- Prod today: branch_id is still unset on every row, because the PR isn't deployed. In clarity.hotlist
  it is **NULL on most rows and 0 on some**, so every branch predicate must be NULL-safe.
- The headline cards were blocked because clarity.hotlist_status_aggregate freezes branch_id at
  transition time (~92% of Open transitions are written at INSERT, before enrichment). That table is
  OFF-LIMITS: no ALTER, no rebuild.
- **Unblocked by DEV-2892 (done):** chlocal now ships `clarity.hotlist_status_transitions`
  (id, org_id, hotlist_id, created_at, old_status, new_status, _peerdb_synced_at, inserted_at).
  It is a ReplacingMergeTree ORDER BY (org_id, created_at, id), PARTITION BY toYYYYMM(created_at),
  fed by an MV that refreshes every 15 minutes. It holds Clarity orgs only, every transition
  (including the INSERT ones), and is backfilled back to 2022-11.
  Measured exact: org 14 (Brad Webb, the ticket's reference org) is identical to the aggregate for
  Assignments, Accepted and Repossessions. For July, 2,270 of 2,273 orgs are identical; the other 3
  are the largest orgs, off by ≤0.42% from the aggregate's own uniq estimation error. Every month in
  2025-01..2026-10 is within ±1% fleet-wide.
  Its schema is NOT yet in platform's database/schema/clickhouse-schema.sql.

## Decisions already made (do not re-litigate)

1. Use the new table ONLY when a branch is selected. Unfiltered cards keep reading the aggregate
   and must not move for any client.
2. Branch-filtered numbers must be EXACT. The first_*_at columns were rejected (org 14 Accepted
   +8..+26%/mo).
3. Branch comes from a read-time join: `hotlist_id IN (SELECT id FROM clarity.hotlist FINAL
   WHERE org_id=? AND _peerdb_is_deleted=0 AND created_at < window_end AND branch_id = ?)`.
4. Merge main, never rebase. checkpoint.sh forbids force-push. Commit everything through
   checkpoint.sh EXCEPT a main-merge: if main touched .env.example or .env.kamal.example, checkpoint
   can never commit that merge, and on PII-fail it unstages and DROPS MERGE_HEAD. Back up
   .git/worktrees/<wt>/MERGE_{HEAD,MSG,MODE} first. Dalton authorized a one-off plain
   `git commit` + normal push for that case on 2026-09-30; ask him again before repeating it.

## Remaining work, in order

1. Merge origin/main (see 4 above). Resolve conflicts keep-both-sides where they're unrelated.
2. Fix the 7 open round-11 review findings (listed in the PR body under "Open findings"):
   - crc32 phase applied as a whole-series rotation, which delays the newest row ~12h; the test
     helper sorts offsets and hides it
   - no re-run dedup on `app:rdn` (duplicate SOAP calls, attempts 0→2)
   - `app:rdn --force` with no org forces the whole fleet past the kill switch
   - unguarded each() with tries=1 loses the org's day with no summary line
   - failed_jobs never pruned
   - `Branch::where()->first()` has no ORDER BY and branch_name isn't unique
   - newest-first + cap starves yesterday's misses for orgs over the cap
   Keep scope minimal: Dalton cuts reviewer-creep, so fix the root of each finding only.
3. Mirror `clarity.hotlist_status_transitions` (+ _mv if CI needs it) into
   database/schema/clickhouse-schema.sql (copy the prod DDL via SHOW CREATE, read-only).
4. Add a ClickHouse model/query for the transitions table with the same scopes as
   app/Clickhouse/Models/HotlistStatusAggregate.php: forOrg, between, forClient, forBranch,
   openAssignments, repossessions, accepted, closedCohort, declined, acceptedOpen.
   - Count with uniqExact(hotlist_id).
   - `between` must match the aggregate's status_hour semantics: bucket created_at with
     toStartOfHour, compare in UTC.
   - Client/lienholder filters come from clarity.hotlist too.
5. Switch the source only when a branch is set, in every aggregate consumer that takes a branch
   filter. Grep `HotlistStatusAggregate` in app/: TotalAssignments, TotalClientAssignments,
   TotalAccepted, TotalRepossessions, AverageRecoveryRate, ThirtyDayRecoveryRate, the Lifetime*
   widgets, AssignmentBreakdownChart, HistoricalCoverageBreakdownChart, ClientRankedMetricsBatch,
   BatchMonthlySparklineData, Filament/Pages/Concerns/HasStatDrillDown.
   Confirm which ones actually receive the branch filter before editing.
6. Perf check (read-only, prod CH via the .env readonly_user). Measured for a one-month window:
   org 165 (largest) costs 508 MB / ~0.55 s per card, almost all of it the clarity.hotlist FINAL
   subquery; org 14 costs 52 MB / 69 ms. Measure a 12-month window for org 165 with several cards.
   If it's too slow, compute the page's metrics in one query rather than adding caching first.
   Interleave benchmark runs; never run them sequentially.
7. Tests (TDD, RequiresClickhouse; anchor fixtures near now()). Then `composer format`, then the
   filtered suite. Never --parallel; use .env.testing.
8. Re-review via rigor review.sh. Diff against `origin/main`, NOT state.diff_base e03d4e6a0, which
   predates the merges. Escalate to 3 lanes (counting/aggregation + scale_relevant). Gate 90.
9. QA: run the REAL pipeline. Last time QA seeded branch_id directly and missed the blocker.
   Provision fresh. state.qa.ledger points at a .context/ ledger from an OLD worktree that was never
   torn down, so verify and clean any stale zod_*/zod_testing_* DBs by hand before provisioning.
10. Close out: update BUILD-RECORD.md + Jira Build Record (comment 39014, edit in place) + status
    mirror (comment 38637) + PR body. Remove the "headline cards not fixed" warnings. Pass both
    gates, tear down, done.

## Known context that affects QA (not this ticket's to fix)

Since 2026-09-01 18:59:50 UTC, insight_app's RDN push consumer (sqs/rdn_batch.py) has processed no
batches; integration 1 is stuck at batch_id 156982286. ~1,450 push-only RDN orgs get almost no new
hotlists (−98.6%), while the ~340 orgs with an RDN API key are normal. This shrinks the enrichment
candidate pool (enrichment is RDN-only) and depresses Sept/Oct numbers in BOTH sources. Pick QA and
perf reference orgs from the API-key set (org 14 is fine), and don't mistake the drop for a
regression. Dalton is handling it separately with Eric. Do NOT open tickets without asking.

## Working rules

- ELI5 chat register.
- Raw rows never go into the repo or Jira; record query shapes + aggregates in derivation-log.json.
- Keep comments/docblocks sparse.
- Never edit okf/ until merge.
- Ask Dalton before any Jira ticket creation, force-push, or bypass of checkpoint.sh.
