# DEV-2928 runbook — `app:rdn-backfill-branches` (post-deploy, run by hand)

Nothing here runs from this PR. No production or fleet-wide run is part of this work.

## 1. Preflight
- `services.rdn.case_info_enabled` is on (`RDN_CASE_INFO_ENABLED`). The command refuses to run otherwise.
- The command reads its candidate hotlists from ClickHouse: `zod.public_hotlist` and `zod.public_hotlist_meta` (both `FINAL`), on the app's `public_clickhouse` connection. The app's ClickHouse user needs SELECT on both tables. Missing access or a ClickHouse outage stops the command with a ClickHouse error before any RDN call, write or cursor save; there is no Postgres fallback.
- ClickHouse server timezone is UTC. Run this on the `public_clickhouse` connection before the EXPLAINs in step 2 and before any dry run or live run:
  ```sql
  SELECT timezone()
  ```
  It must return `UTC`. Stop if it returns anything else: the window bounds (`updated_at`, `repo_date`) and the resume cursor's `created_at` are compared as UTC, so another server timezone shifts the window.
- Baseline before starting:
  - Datadog `rdn_case_info.{resolved, miss_no_branch_match, miss_no_branch_name, transport_failed, sealed}` rates
  - `clarity.hotlist_incremental_mv` refresh time (Cloud CH, read-only):
    ```sql
    SELECT view, status, last_success_time, last_refresh_time, next_refresh_time, exception
    FROM system.view_refreshes
    WHERE database = 'clarity' AND view = 'hotlist_incremental_mv'
    ```

## 2. Plain EXPLAIN first (before any dry run or live run)
Run both plain `EXPLAIN`s (no `ANALYZE`) below for the org and window, in this order. Do not go on to the dry run until both are clean.

Substitute `Y-m-d` dates only for `<since>` / `<until>`; that is the only input the command accepts. Use the same `--since` / `--until` dates you will pass to the command. If you will run without `--until` (the command then uses the current UTC time), put tomorrow's UTC date for `<until>`; the plan is the same. `<until minus 1 day>` is the day before `<until>`.

**(a) The ClickHouse candidate query** (read-only, `zod` database). The command runs this once per run and reads every candidate id for the org, newest first. Identifier quoting is left out here, and the tables are written with their `zod.` prefix (the app connects to `zod`); otherwise it is the SQL `candidates()` compiles to. A resume adds `and (created_at, id) < (toDateTime64('<cursor created_at>', 6), <cursor id>)` before the last `and _peerdb_is_deleted = 0`:
```sql
EXPLAIN
select id, created_at from zod.public_hotlist FINAL where org_id = <org-id> and integration_id = 1 and remote_order_id is not null and status in (0, 1, 2, 3, 4, 5, 90) and (status in (1, 2) or (updated_at >= '<since> 00:00:00' and updated_at < '<until> 00:00:00') or id IN (SELECT hotlist_id FROM zod.public_hotlist_meta FINAL WHERE _peerdb_is_deleted = 0 AND repo_date >= '<since>' AND repo_date <= '<until minus 1 day>')) and _peerdb_is_deleted = 0 order by created_at desc, id desc
```
Expected: a full read of both tables (the sort key is `id`, so there is no org prune). Measured on Oct 9 for orgs 167 and 24715: about 3.7 s and 24 GB read per run. Stop if the `EXPLAIN` errors, or if you have no access to either table.

**(b) The Postgres chunk query.** The command no longer selects on Postgres. For each page of at most `--chunk` ids it re-checks the ids by primary key. Run it with three real hotlist ids of the org in place of the id list (the real list is at most `--chunk` ids, so a short list shows the same plan). The `hotlist_json` predicate is `GetOrgHotlistCaseInfo::sealedPredicate()`, and its `>=` bound is `GetOrgHotlistCaseInfo::maxLookupAttempts()` (config-derived; 3 as shipped). The dry run runs the same query without the `order by`:
```sql
EXPLAIN
select * from "hotlist" where "hotlist"."org_id" = <org-id> and "hotlist"."org_id" is not null and "hotlist"."id" in (<id-1>, <id-2>, <id-3>) and "hotlist"."integration_id" = 1 and "hotlist"."remote_order_id" is not null and "hotlist"."status" in (0, 1, 2, 3, 4, 5, 90) and not exists (select * from "public"."hotlist_json" where "hotlist"."id" = "public"."hotlist_json"."hotlist_id" and ((hotlist_json."json" ->> 'branch_id') IS NOT NULL OR (jsonb_typeof(hotlist_json."json" -> 'branch_lookup_attempts') = 'number' AND (hotlist_json."json" ->> 'branch_lookup_attempts')::numeric >= <maxLookupAttempts()>))) order by "hotlist"."created_at" desc, "hotlist"."id" desc;
```
Expected: lookups by the `hotlist` primary key, with no Seq Scan on `hotlist` and no cross-org scan. Stop otherwise.

## 3. Dry run (one org: Action Towing = org 167, "Action Towing & Recovery Of Sarasota")
Only after the timezone check in step 1 and both EXPLAINs above are clean:
```
php artisan app:rdn-backfill-branches 167 --since=2025-01-01 --dry-run=30
```
- A bare `--dry-run` is the same as `--dry-run=30`: up to 30 read-only RDN lookups that share the org's RDN rate limiter, and no writes. It never runs the live path. This holds from the DEV-2936 hotfix on; on v3.10.3 a bare `--dry-run` ran live, so pass `--dry-run=N` with `=` to be safe on any build.
- `--since` / `--until` accept only `Y-m-d` (UTC midnight); anything else is rejected.
- Calls RDN for up to 30 eligible hotlists split across `<30d` / `30-180d` / `>180d` (by `created_at`), classifies each exactly like `GetOrgHotlistCaseInfo`, and prints per-bucket `resolved / miss_no_branch_name / miss_no_branch_match / transport_failed`, then the unmatched `vendor_branch_name` values with counts, then the org's branch names (console only).
- The candidates come from ClickHouse and are re-checked on Postgres (org, RDN, case id, analytics status, not resolved or sealed). A sampled hotlist that is already resolved or sealed drops out and is not replaced, so a bucket can show fewer samples than its share. The `sampled` column is the real count. Before a first live run almost nothing is sealed, so the difference should be small.
- Writes nothing: no `hotlist_json` write or attempt bump, no `rdn_case_info.*` counter, no cursor. Q2 accepted (HITL 2026-10-08): the dry run goes through the normal RDN client, so each sample call is charged to the org's `rdn-api:{org}` 60-second limiter keys (the same budget as the branch/client/agent/zip syncs and the web client list), and the client may refresh `org_meta.properties.rdn_rate_limit` when RDN reports a different limit. That is client metadata, not branch data.
- If `miss_no_branch_match` dominates, compare the unmatched names with the branch list (alias table is a separate ticket). If `transport_failed` or `miss_no_branch_name` dominates, stop and check credentials / RDN availability.

## 4. Backfill one org
Production runs are manual (Dalton only), in this order (Q5, HITL 2026-10-08):
1. The ClickHouse timezone check in step 1 (`SELECT timezone()` returns `UTC`). Stop if it does not.
2. The two plain `EXPLAIN`s in step 2. Stop if either is not clean.
3. The dry run in step 3 (a bare `--dry-run` is the same as `--dry-run=30`: read-only, no writes), or `--dry-run=N` with a small N (e.g. `--dry-run=6`), which runs the same selection.
4. The live run, with a small `--max`:

```
php artisan app:rdn-backfill-branches <org-id> --since=2025-01-01 --chunk=500 --max=2000
```
- `--since` default (Q1, HITL 2026-10-08): Jan 1 of last year, i.e. YTD + its comparison year (today: `--since=2025-01-01`). Lifetime cards (2020-01-01) under-count anything older than `--since`; the command can be re-run later with an earlier `--since` (a new window gets its own cursor, and rows already resolved or sealed are skipped).
- Selection: ClickHouse nominates the org's RDN hotlists (remote order id set, analytics statuses) with activity in `[--since, --until)`: an update to the hotlist row (`updated_at`) in the window, a repo date in the window, or currently Open/Hold. Newest first. Postgres re-checks each chunk's ids before any RDN call (org, RDN, case id, analytics status, not yet resolved or sealed) and skips the rows that no longer qualify. It never reads `hotlist_status_log`.
- CDC lag: the ClickHouse list comes from the CDC replica, which runs seconds to a few minutes behind Postgres (on Oct 9: about 11 s at p50, 19 s at p90, 21 s at p99). A hotlist created or updated in the last few minutes before the run, or at any time during it, may be missing from that run; a later run with the same window picks it up. A hotlist whose activity changed within the lag may get one extra lookup. Lag never writes to the wrong row, because every write keys on the Postgres row.
- Once per run: every run (dry run, live run or resume) reads the ClickHouse list once, at the start, about 3.7 s and 24 GB as measured on Oct 9. It is a snapshot; the run walks it in chunks and does not re-read it. A ClickHouse error stops the run before any RDN call, write or cursor save.
- Runs `GetOrgHotlistCaseInfo`'s lookup inline for each selected hotlist: the same RDN call, Branch match, `hotlist_json` write, attempt ceiling and outcome counters as the scheduled job. One call at a time under the job's own `rdn-case-info` limiter bucket, the same per-org bucket (the org's RDN rate limit per minute) the scheduled case-info jobs use; the command blocks when it is full. Other RDN calls (branch/client/agent/zip syncs, the web client list) use a different bucket and are not slowed by the backfill. Inline calls take no unique lock, so a scheduled lookup of the same hotlist can overlap and call RDN twice; that is harmless (same write, the attempt ceiling still applies). Nothing goes on any queue, and no Horizon config or env var is needed or added.
- A row that throws is logged (`rdn.backfill_branches.failed`), counted as failed, and the run continues.
- Resume: the cursor is saved after each chunk that Postgres returned rows for (cache key per org + window). The key carries the `--since`/`--until` dates, so each window keeps its own cursor. Re-run the same command (same `--since`/`--until`) to resume: the ClickHouse query then starts after the cursor (`created_at` with microseconds, and `id`). If the process dies, at most one chunk is re-walked: rows already resolved drop out, and a re-walked miss may spend one more attempt. The run is complete only when the ClickHouse list is used up, never because one chunk came back empty from Postgres. `--max` counts hotlists walked. Raise `--max` only after step 5 looks healthy.
- Every run that ends normally logs `rdn.backfill_branches.run` (org, window, walked, processed, failed, cursor, complete).

## 5. Watch during the run
- `rdn_case_info.*` outcome rates.
- `hotlist_incremental_mv` refresh time stays well under its 1-minute cadence, with no `exception`.
- The backfill and the scheduled case-info jobs share the org's `rdn-case-info` bucket, so combined case-info calls stay within the org's RDN limit; scheduled lookups for the org may wait while a run is going.

## 6. Verify
- Client Analytics for the org with a branch selected: LPR Assists and Missed Opportunities move toward current-branch counts. All Branches totals do not change.
- Post-backfill re-measure (Cloud CH, read-only): pick the busiest branches from `clarity.hotlist` once the backfill lands (e.g. `SELECT branch_id, count() FROM clarity.hotlist FINAL WHERE org_id = 167 AND _peerdb_is_deleted = 0 AND ifNull(branch_id, 0) != 0 GROUP BY branch_id ORDER BY count() DESC LIMIT 3`), then re-run the branch-filtered old vs new comparison for those branches.
- Cost (Q3, HITL 2026-10-08): org 165's branch-filtered card query at about 0.6–1.1 s / ~450 MB once branches exist is accepted. No cap. Open a ticket only if it shows up in ClickHouse timeout logs.

## 7. Stop
- Ctrl-C any time (the cursor is saved per chunk).
- Hard stop: Ctrl-C the command. `RDN_CASE_INFO_ENABLED=false` makes the command refuse to start (a running command keeps the config it booted with, so stop it with Ctrl-C).

## How branch_id reaches ClickHouse
- Write (Postgres): `GetOrgHotlistCaseInfo::writeBranchId()` (`app/Jobs/Rdn/GetOrgHotlistCaseInfo.php` L499–514, from #374) upserts `public.hotlist_json`: `insert … values (?, jsonb_set('{}', '{branch_id}', <id>)) on conflict (hotlist_id) do update set "json" = jsonb_set(coalesce("json", '{}'), '{branch_id}', <id>), updated_at = now`. A hotlist with no `hotlist_json` row gets one; an existing blob keeps its other keys. `branch_id` is `Branch.branch_id` (cast integer, `app/Models/Branch.php` L68), so it lands as a JSON number. Called on resolve at L417–422.
- Backfill: `app:rdn-backfill-branches` takes its candidates from ClickHouse (`candidates()`, `app/Console/Commands/RdnBackfillBranches.php` L388–412) and re-checks them on Postgres for hotlists with no resolved/sealed `hotlist_json` row, including ones with no row at all (`whereDoesntHave('hotlistJson', sealed)` in `eligible()`, L421–432, the clause at L428–431). It runs the job's `handle()` inline for each (L167–198). `RdnBackfillBranchesTest::test_writes_results_inline_and_continues_past_a_failing_hotlist` (L423–453) creates hotlists with no `hotlist_json` row and asserts the row is created with `branch_id` 101 (L438).
- Sync: PeerDB CDC (Postgres logical replication, not a timestamp poll) mirrors `public.hotlist_json` to `zod.public_hotlist_json` (`database/schema/clickhouse-schema.sql` L64; `okf/concepts/clickhouse-integration.md` L93–95, L158). Every insert/update replicates with a fresh `_peerdb_synced_at` / `_peerdb_version`; `updated_at` is touched anyway. No app-side sync.
- ClickHouse: `zod.hotlist_json_denormalized_mv` extracts `JSONExtractInt(json, 'branch_id')` on insert (`clickhouse-schema.sql` L107). `clarity.hotlist_incremental_mv` (refresh every 1 minute, L104) picks up hotlist ids whose `hotlist_json_denormalized._peerdb_synced_at` is past its watermark and writes `hj.branch_id` into `clarity.hotlist.branch_id` with a higher `_version`.
- Org 167 has no `hotlist_json` rows after Dec 2024, so in the default window nearly every hotlist is a no-row case and the backfill creates its row.
