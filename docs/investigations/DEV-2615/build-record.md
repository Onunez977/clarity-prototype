Insight AI — Build Record

**TL;DR**

The Scans list on ClickHouse was reading the whole `scans_cold` table after DEV-2591 dropped the `org_id` filter. Searches got ~86× slower and customers hit 30-second timeouts. This change puts `org_id` back using each kit’s **current** Postgres owner from `Kit::getKits()`, so ClickHouse can prune on the leading sort key again.

Verified with inverted shape tests, review score 93, agent QA PASS, human QA complete, and a Cloud EXPLAIN pass (~90× fewer rows with an org predicate). Moved-kit historical parity via `zod.kit_last_scan` was **dropped** (human:Dalton 2026-09-18) — no customer complaints; defer until reported. Kits that changed orgs may hide older CH rows stamped with a former `org_id`. Full Scans-list p50 / error-159 elimination still needs a post-deploy watch. Hits pruning stays on DEV-2614. End-date-only date floors stay deferred.

---

**Route:** bug · **Base:** `deploy` (DEV-2591 lives only there) · **PR:** https://github.com/insight-lpr/platform/pull/424

### What changed (t1) and why

- **`Scan::scopeForOrgs`** — restore sort-key-leading `org_id IN (...)` prune. Needed because `kit_id` alone cannot prune `(org_id, scanned_at, kit_id, id)`.
- **`ListScans::buildClickhouseQuery`** — `forOrgs(current Kit::getKits org_ids)->forKits(...)`. Needed to ship the prune on the page path customers hit, with the smallest correct-enough org set.
- **Tests** — invert `ClickhouseScanQueryShapeTest` (org predicate required). Needed so the DEV-2591 “never filter org_id” assertion cannot regress this fix.

**Root cause:** DEV-2591 removed the org predicate after finding current-owner prune drops moved-kit history. Removing the predicate fixed correctness but scanned ~2.21B rows. This ticket restores prune for perf; historical org widening is deferred.

### Resolved questions

- base branch → `deploy` · human:Dalton 2026-09-18
- Q1 date floor when only end date set → defer · human:Dalton 2026-09-18
- Q2 use ticket-measured cardinality for `kit_last_scan` → yes · human:Dalton 2026-09-18 (used for EXPLAIN evidence; not used in shipped code after scope change)
- historical org set via `kit_last_scan` → **drop for now** · human:Dalton 2026-09-18

### What this ticket did NOT do

- Historical org prune from `zod.kit_last_scan` / moved-kit row-set parity — deferred until a customer reports missing history
- DEV-2614 Hits list `hotlist_org_id` prune / feature-name mismatch — separate ticket
- Default lower bound when only an end date is set on Scans — deferred (Q1)
- Changing `CLICKHOUSE_TIMEOUT_QUERY` / adding `max_execution_time` on `clarity_clickhouse` — confirm-only; no config change
- Main backport of DEV-2591 (#418 closed) — not opened here; deploy is the live base
- okf wiki update — post-merge only

### Tests

- `php artisan test --compact tests/Filament/Resources/ClickhouseScanQueryShapeTest.php` — shape tests (org + kit predicates)
- Agent QA on https://clarity-claude_dev_2615.test — PASS (against earlier historical build; human QA complete)
- Live moved-kit parity test removed with the historical-org scope drop

### Review

- RN1 in-session (`no_spawn`): score 93; gate pass
- Scoring findings: none
- Residual: post-deploy p50 / error 159; DEV-2614; moved-kit history if reported

### AC checklist

- AC-1 org_id prune restored on Scans CH list — SATISFIED (current Postgres kit org_ids)
- AC-2 EXPLAIN tens of millions — SATISFIED (2026-09-18 Cloud EXPLAIN ~90x with org predicate)
- AC-3 moved-kit historical parity — N/A (deferred; human:Dalton 2026-09-18)
- AC-4 prod list p50 / error 159 — N/A until post-deploy observe
- AC-5 dedicated org-set cache + forgetCachedKits — N/A (removed; uses existing getKits cache only)
- AC-6 30s timeout source — SATISFIED (`clarity_clickhouse` has no `max_execution_time`; Cloud server default 30s)
- AC-7 end-only date floor — N/A (deferred)

### QA performed

- Mode: worktree · Postgres `zod_claude_dev_2615` · Docker CH · Herd https://clarity-claude_dev_2615.test (torn down after handoff)
- Agent QA PASS; human QA completed 2026-09-18 (Dalton)
- Resource envelope: PASS on Cloud EXPLAIN (org prune); historical COUNT evidence retained in prod-cardinality.md for context only

### Production cardinality (re-measured 2026-09-18)

- EXPLAIN ESTIMATE: **2,217,023,592 → 24,542,538** rows (~90x) with org_id prune
- Kit 10044 COUNT (context): current-owner keeps 233,517 of 2,141,322 — known loss accepted under deferred historical scope

### How numbers were derived

- 2026-09-18 Cloud EXPLAIN ESTIMATE (docs/investigations/DEV-2615/prod-cardinality.md)
- Timeout: clarity_clickhouse omits max_execution_time; error maximum 30000 ms is Cloud server default

### Deviations

- `{"source":"qa-loop","edit":"drop kit_last_scan historical org set; prune with current Postgres kit org_ids only","ac_ref":"AC-1,AC-3,AC-5","ac_delta":"historical lossless prune → current-owner prune; AC-3/AC-5 N/A"}`

### Open after ship

- Watch Scans-list p50 and ClickHouse error 159 in production after merge/deploy
- Revisit historical org set if a customer reports missing scans after a kit move
- DEV-2614 for Hits
