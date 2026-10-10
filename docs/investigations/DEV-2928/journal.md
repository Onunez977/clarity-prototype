# DEV-2928 journal

## 2026-10-07 ~4:00pm CDT — Prep intake

- Live re-GET DEV-2928 (Atlassian connector): To Do · Task · Sprint 42 (active) · Application Clarity · epic DEV-1580 · assignee Dalton McCleery · relates to DEV-2442 (QA Ready) · labels none.
- First Glance comment **43357** live (3:57pm CDT): Verdict Ready · Hardness Medium · **Branch path** stacked on PR #374 head `claude/DEV-2442` (base `claude/DEV-2442`; retarget to main after #374 merges) · hard stop / forecast gates / Next present.
- `origin/claude/DEV-2442` tip `42a4310f` (2026-10-07 1:53pm CDT) matches FG collision cite.
- Drift vs dispatch card (live Jira wins):
  - Live description names the full option set `{org} --since= [--until=] [--chunk=500] [--max=] [--dry-run=N]`; card listed only `--dry-run=N`.
  - Live selection is **by activity in the window** (status transition or repo date in window, or currently Open/Hold), not `created_at`; card said "newest→oldest; skip resolved/sealed; include Open/Hold" — consistent, but the window semantics come from Jira.
  - Card's "reuse `GetOrgHotlistCaseInfo`" for the card filter is FG wording; the card filter reuses the `HotlistStatusTransition::scopeForBranch` subquery pattern (no job involvement). Noted, not a scope change.
  - Tension (non-blocking, forwarded to Planner): Jira says reuse the job "unchanged" yet normalized matching lives in the job's branch lookup. Read as: dispatch / rate-limit / uniqueness / ceiling / counters contract unchanged; branch-match lookup gains a fallback.
- Worktree (Mac, Plan run cwd): `<mac-run-dir>/platform-wt` via `git -C ~/Herd/platform worktree add -b cursor/dev-2928-rdn-backfill-branches origin/claude/DEV-2442`. Auto-upstream to `claude/DEV-2442` **unset** immediately (push safety).
- Box checkout: `/workspace/dev-2928-wt` (`--no-track`) at `42a4310f`.
- Scout: `LprAssist::buildWhereClause` filters `r.branch_id` (copied at write); `MissedOpportunity::scopeForBranch` filters `branch_id` (copied at write, UInt32 default 0). All consumers are Client Analytics (widgets, lifetime widgets, drill-down, ranked batch, sparklines). Template: `HotlistStatusTransition::scopeForBranch` read-time `hotlist FINAL` subquery with `ifNull(branch_id, 0)`.
- Gate: **CLEAN**. Next: Plan.

## 2026-10-07 ~4:02pm CDT — Draft PR

- Prep tip `4ac00200` pushed (explicit refspec) to `cursor/dev-2928-rdn-backfill-branches`; `claude/DEV-2442` remote unchanged at `42a4310f`.
- Draft PR **#510** opened, base `claude/DEV-2442`, assignee DaltonMcCleery set, living ticket-status (Prep 1/7).

## 2026-10-07 4:03–4:37pm CDT — Plan CLEAN

- Plan agent (worktree-local; trust `fs_read,fs_write,grep,glob,code,thinking`; never `--trust-all-tools`; guard smoke OK) · claude-opus-5.5 / high · exit 0 · wall **2027 s (~33m 47s)**.
- Spec `.ai/specs/DEV-2928.md` Status **READY** · `ch_touch: yes` (read path only). Self-check **CLEAN** (`harness/self-check.md`).
- Plan shape: command `app:rdn-backfill-branches` (activity-window selection reusing `sealedPredicate` + `GetOrgHotlistCases` eligibility; `{rdn-backfill}` queue + supervisor in 4 Horizon env blocks; keyset cursor in Cache; queue-depth throttle; dry run via `RDN::call` + shared static `GetOrgHotlistCaseInfo::findBranch()`); normalized fallback in that one lookup; `LprAssist` → `la.recovery_id IN (clarity.hotlist FINAL … ifNull(branch_id,0)=?)`; `MissedOpportunity` `forOrg` stash + `forBranch` subquery. Zero consumer edits. All Branches path byte-identical.
- #25 measure planned (§10 P0–P3, Cloud CH read-only, `log_comment='DEV-2928-p25'`). Not run.
- Comment sanitized (`@conductor` → "Decision (Dalton)", "crew" removed) and posted as Jira **43358** (4:38pm CDT).
- Seed needs → `path-a-seed-needs.md`. Box checkout `/workspace/dev-2928-wt/.ai/specs/` mirrored.
- Open questions 4 (none block Implement). Open gates: #25 parity in PR + Build Record; HITL with Dalton before undraft; retarget after #374 merges.
- STOP before Provision. No qa-prep. No Implement baton. Cloud CH not contacted.

## 2026-10-07 4:46pm CDT — Path A LIVE (fleet twin)

- Twin stood for DEV-2928: site `:18094` · PG `:15446` (`zod_dev_2928` / `zod_testing_dev_2928`) · CH `:18137` dedicated container, real `clarity` + `zod` DBs. Gateway `172.21.0.1`.
- CH schema `clickhouse-schema.sql` @ `2fd4c3e9` loaded as-is, 86/86 objects. Seeded from `path-a-seed-needs.md` (orgs 92801–92804, hotlists 9280001–9280018). Prove passed; DEV-2450 twin untouched.
- Cloud read-only measure attempt via the ClickHouse connector was refused ("Service does not allow MCP calls"); not routed around. #25 measure still needs a sanctioned path.

## 2026-10-07 4:48pm CDT — Provision Done

- Cited LIVE ledger: prove_passed=true · live · cloud_touched=false · mac_herd_refused=true. Checkout copy `/workspace/dev-2928-wt/.context/qa-prep-ledger.json` (`provision_mode: fleet_path_a_cli`, gitignored).
- Box smoke CLEAN (no Mac Herd; Cloud not contacted): login **200** · PG SELECT 1 / public **139** / platform **41** / seed hotlist 17, branches 6 · CH SELECT 1 / clarity objects **39** / `hotlist.branch_id` Nullable(Int64) / `lpr_assists` View / seeded hotlist 18 (FINAL 17), recoveries 8, recovery_scans 9, missed_opportunities 9 · `.env.testing` CLICKHOUSE_* → `172.21.0.1:18137` `clarity`/`zod`, driver clickhouse (#31).
- Wrote `provision.md` / `provision.json` / `provision-cite-slim.json`. state → phase=provisioned, gate CLEAN, next Implement. Tip unchanged `2fd4c3e9`.
- Implement caveats recorded: CH tests truncate `clarity` → reseed with `seed-work/ch-seed-dev-2928.sql`; run phpunit from the box (PHP 8.4 vs CI 8.3); twin CH 25.8 vs CI 26.2.
- PR #510 ticket-status → Provisioned (2/7) · Next Implement. Draft stays draft. No Implement handoff from this step.

## 2026-10-07 4:50–5:05pm CDT — Implement

- Implement agent not runnable on the box (harness CLI absent); Mac worktree is one docs commit behind and off the Path A twin. Implemented directly in `/workspace/dev-2928-wt` from the checked spec.
- Port check: `:18137` answers `hostName() = fc6ff762895b` = `clarity-qa-clickhouse-dev-2928` (DEV-2928 ledger). DEV-2442 ledger shows its twin torn 3:58pm CDT and the ports freed; not shared.
- Code `b6acb392`: command `app:rdn-backfill-branches`, `{rdn-backfill}` supervisor (4 env blocks), `GetOrgHotlistCaseInfo::findBranch()` + `normalizeBranchName()`, LPR/MO current-branch subquery, `clarity.recovery_scans` in truncate list (CREATE TABLE at schema L38).
- Existing test `a_branch_name_no_row_matches…` used `DALLAS BRANCH` vs `Dallas Branch`; with the normalized fallback that now resolves, so the fixture name changed to an unrelated branch.
- Soft #18 on real CH: spec suite 206 passed / 4 skipped / 0 failed (848 assertions). Broad regression 972 passed / 5 failed; the 5 fail identically on the base without this diff (logging/timeout tests + a timezone alias on box PHP 8.4).
- Pint repo-wide clean; PHPStan clean on changed app files. CH twin reseeded (hotlist 18 / FINAL 17, recoveries 8, recovery_scans 9, MO 9).
- Not done here: #25 Cloud read-only measure (no sanctioned path yet), Jira workflow transition, undraft.

## 2026-10-07 5:39–5:50pm CDT — Review rn=1 Act-on (one fix cycle)

- Reviewed sha `c2020a1c` (CHANGES REQUESTED, conventions 84 < 85, 0 blockers). Code fixes in `d49299e9`.
- Item 1: Q2 answered — PM-accepted 2026-10-07; human confirm at HITL. RDN client refreshing `org_meta.properties.rdn_rate_limit` during a dry run is client metadata, not branch data. Recorded in `RdnBackfillBranches::classify()` docblock, runbook §2, PR body.
- Item 3: `test_dry_run_writes_nothing_and_reports_per_bucket` now pre-seeds `hotlist_json` `{"branch_lookup_attempts": 1}` on the UNKNOWN sample and asserts the raw `json` is byte-equal after the run; for all five samples `UniqueLock::acquire(new GetOrgHotlistCaseInfo(...))` returns true, then is released.
- Item 4: `[skip ci]` kept on every commit (PM decision; CI-trigger commit comes at undraft).
- Item 5: `cursorKey()` uses full UTC `Y-m-d\THis\Z` for since/until + new test `test_cursor_key_distinguishes_windows_by_time_of_day`; `--max` help/runbook say it counts walked hotlists (incl. suppressed/failed). Follow-up (not this PR): PHP 8.2+ dynamic-property stash (`missedOpportunityOrgId` on the query builder, same pattern in `HotlistStatusTransition`).
- Item 2: `EXPLAIN (ANALYZE, BUFFERS)` on Path A dev PG `172.21.0.1:15446` `zod_dev_2928`, org 92801, window `--since=2025-01-01` → now. SQL is `toRawSql()` of the real `eligible()` builder (via Reflection) plus (A) the backfill keyset clause + order + `limit(500)` (cursor = newest eligible row) and (B) the dry-run `<30d` bucket + `inRandomOrder()->limit(10)`.
  - Both: `Index Scan using integration_status_updated_idx` (`integration_id = 1`) with org/status/window filtered row by row, then a `Sort` of the whole filtered org set (A: created_at DESC, id DESC; B: random()), `hotlist_json_pkey` anti-join. Status-log and hotlist_meta EXISTS run as hashed SubPlans that **seq scan** `hotlist_status_log` / `hotlist_meta` over the window (not org-scoped). A: 4 rows, 0.146 ms, 21 shared hits. B: 1 row, 0.100 ms, 8 shared hits.
  - The twin holds 34 hotlists (15 for org 92801), so the planner's choices here say little about production. `hotlist_org_id_status_created_at`, `idx_hotlist_status_log_hotlist_id` and `hotlist_meta_repo_date_index` exist but were **not** picked on this data. **OPEN (before undraft):** both plans sort the whole eligible org set per chunk/bucket, and the window subplans scan all orgs' status_log/meta rows; needs a prod-size plan (read-only) before running on a large org. No query redesign in this cycle.

```
eligible_count=5 org_hotlists=15 cursor=2026-09-17 14:00:00,9280002
=== A keyset chunk
-- SQL
select * from "hotlist" where "hotlist"."org_id" = 92801 and "hotlist"."org_id" is not null and "hotlist"."integration_id" = 1 and "hotlist"."remote_order_id" is not null and "hotlist"."status" in (0, 1, 2, 3, 4, 5, 90) and not exists (select * from "public"."hotlist_json" where "hotlist"."id" = "public"."hotlist_json"."hotlist_id" and ((hotlist_json."json" ->> 'branch_id') IS NOT NULL OR (jsonb_typeof(hotlist_json."json" -> 'branch_lookup_attempts') = 'number' AND (hotlist_json."json" ->> 'branch_lookup_attempts')::numeric >= 3))) and ("hotlist"."status" in (1, 2) or exists (select 1 from "public"."hotlist_status_log" where "hotlist_status_log"."hotlist_id" = "hotlist"."id" and "hotlist_status_log"."created_at" >= '2025-01-01 00:00:00' and "hotlist_status_log"."created_at" < '2026-10-07 22:40:18') or exists (select * from "hotlist_meta" where "hotlist"."id" = "hotlist_meta"."hotlist_id" and "repo_date" >= '2025-01-01' and "repo_date" <= '2026-10-07')) and (hotlist.created_at, hotlist.id) < ('2026-09-17 14:00:00', 9280002) order by "hotlist"."created_at" desc, "hotlist"."id" desc limit 500
-- PLAN
Limit  (cost=18.85..18.86 rows=1 width=475) (actual time=0.066..0.068 rows=4 loops=1)
  Buffers: shared hit=21
  ->  Sort  (cost=18.85..18.86 rows=1 width=475) (actual time=0.066..0.067 rows=4 loops=1)
        Sort Key: hotlist.created_at DESC, hotlist.id DESC
        Sort Method: quicksort  Memory: 25kB
        Buffers: shared hit=21
        ->  Nested Loop Anti Join  (cost=0.29..18.84 rows=1 width=475) (actual time=0.019..0.054 rows=4 loops=1)
              Buffers: shared hit=21
              ->  Index Scan using integration_status_updated_idx on hotlist  (cost=0.14..10.65 rows=1 width=475) (actual time=0.011..0.032 rows=10 loops=1)
                    Index Cond: (integration_id = 1)
                    Filter: ((org_id IS NOT NULL) AND (remote_order_id IS NOT NULL) AND (org_id = 92801) AND (ROW(created_at, id) < ROW('2026-09-17 14:00:00'::timestamp without time zone, 9280002)) AND (status = ANY ('{0,1,2,3,4,5,90}'::integer[])) AND ((status = ANY ('{1,2}'::integer[])) OR (hashed SubPlan 2) OR (hashed SubPlan 4)))
                    Rows Removed by Filter: 15
                    Buffers: shared hit=3
                    SubPlan 2
                      ->  Seq Scan on hotlist_status_log  (cost=0.00..1.25 rows=1 width=8) (actual time=0.003..0.006 rows=29 loops=1)
                            Filter: ((created_at >= '2025-01-01 00:00:00'::timestamp without time zone) AND (created_at < '2026-10-07 22:40:18'::timestamp without time zone))
                            Buffers: shared hit=1
                    SubPlan 4
                      ->  Seq Scan on hotlist_meta  (cost=0.00..1.15 rows=1 width=8) (never executed)
                            Filter: ((repo_date >= '2025-01-01'::date) AND (repo_date <= '2026-10-07'::date))
              ->  Index Scan using hotlist_json_pkey on hotlist_json  (cost=0.15..8.19 rows=1 width=8) (actual time=0.002..0.002 rows=1 loops=10)
                    Index Cond: (hotlist_id = hotlist.id)
                    Filter: (((json ->> 'branch_id'::text) IS NOT NULL) OR ((jsonb_typeof((json -> 'branch_lookup_attempts'::text)) = 'number'::text) AND (((json ->> 'branch_lookup_attempts'::text))::numeric >= '3'::numeric)))
                    Rows Removed by Filter: 0
                    Buffers: shared hit=18
Planning:
  Buffers: shared hit=9
Planning Time: 0.285 ms
Execution Time: 0.146 ms
=== B dry-run <30d bucket
-- SQL
select * from "hotlist" where "hotlist"."org_id" = 92801 and "hotlist"."org_id" is not null and "hotlist"."integration_id" = 1 and "hotlist"."remote_order_id" is not null and "hotlist"."status" in (0, 1, 2, 3, 4, 5, 90) and not exists (select * from "public"."hotlist_json" where "hotlist"."id" = "public"."hotlist_json"."hotlist_id" and ((hotlist_json."json" ->> 'branch_id') IS NOT NULL OR (jsonb_typeof(hotlist_json."json" -> 'branch_lookup_attempts') = 'number' AND (hotlist_json."json" ->> 'branch_lookup_attempts')::numeric >= 3))) and ("hotlist"."status" in (1, 2) or exists (select 1 from "public"."hotlist_status_log" where "hotlist_status_log"."hotlist_id" = "hotlist"."id" and "hotlist_status_log"."created_at" >= '2025-01-01 00:00:00' and "hotlist_status_log"."created_at" < '2026-10-07 22:40:18') or exists (select * from "hotlist_meta" where "hotlist"."id" = "hotlist_meta"."hotlist_id" and "repo_date" >= '2025-01-01' and "repo_date" <= '2026-10-07')) and "hotlist"."created_at" >= '2026-09-07 22:40:18' order by RANDOM() limit 10
-- PLAN
Limit  (cost=18.85..18.86 rows=1 width=483) (actual time=0.056..0.058 rows=1 loops=1)
  Buffers: shared hit=8
  ->  Sort  (cost=18.85..18.86 rows=1 width=483) (actual time=0.056..0.057 rows=1 loops=1)
        Sort Key: (random())
        Sort Method: quicksort  Memory: 25kB
        Buffers: shared hit=8
        ->  Nested Loop Anti Join  (cost=0.29..18.84 rows=1 width=483) (actual time=0.041..0.045 rows=1 loops=1)
              Buffers: shared hit=5
              ->  Index Scan using integration_status_updated_idx on hotlist  (cost=0.14..10.64 rows=1 width=475) (actual time=0.006..0.010 rows=2 loops=1)
                    Index Cond: (integration_id = 1)
                    Filter: ((org_id IS NOT NULL) AND (remote_order_id IS NOT NULL) AND (created_at >= '2026-09-07 22:40:18'::timestamp without time zone) AND (org_id = 92801) AND (status = ANY ('{0,1,2,3,4,5,90}'::integer[])) AND ((status = ANY ('{1,2}'::integer[])) OR (hashed SubPlan 2) OR (hashed SubPlan 4)))
                    Rows Removed by Filter: 23
                    Buffers: shared hit=2
                    SubPlan 2
                      ->  Seq Scan on hotlist_status_log  (cost=0.00..1.25 rows=1 width=8) (never executed)
                            Filter: ((created_at >= '2025-01-01 00:00:00'::timestamp without time zone) AND (created_at < '2026-10-07 22:40:18'::timestamp without time zone))
                    SubPlan 4
                      ->  Seq Scan on hotlist_meta  (cost=0.00..1.15 rows=1 width=8) (never executed)
                            Filter: ((repo_date >= '2025-01-01'::date) AND (repo_date <= '2026-10-07'::date))
              ->  Index Scan using hotlist_json_pkey on hotlist_json  (cost=0.15..8.19 rows=1 width=8) (actual time=0.002..0.002 rows=0 loops=2)
                    Index Cond: (hotlist_id = hotlist.id)
                    Filter: (((json ->> 'branch_id'::text) IS NOT NULL) OR ((jsonb_typeof((json -> 'branch_lookup_attempts'::text)) = 'number'::text) AND (((json ->> 'branch_lookup_attempts'::text))::numeric >= '3'::numeric)))
                    Buffers: shared hit=3
Planning:
  Buffers: shared hit=14
Planning Time: 0.268 ms
Execution Time: 0.100 ms
```
- Proof: `RdnBackfillBranchesTest` 11 passed / 66 assertions. Spec §5 suite on real CH (`:18137`, `CLICKHOUSE_DRIVER=clickhouse`, PG `:15446` `zod_testing_dev_2928`): 207 passed / 4 skipped (pre-existing) / 0 failed, 857 assertions, 65.5s. Pint repo-wide clean. CH reseeded 5:41pm CDT: hotlist 18 (FINAL 17), recoveries 8, recovery_scans 9, missed_opportunities 9, transitions 12.

## 2026-10-08 7:47–7:55am CDT — HITL act-on (no Horizon config)

- Ruling (Dalton HITL): no Horizon config changes, ever.
- Ran on the Mac mini worktree (fast-forwarded to `9de69021`). Implement agent under the harness (worktree-local agent, writes limited by a guard to the command, its test and the runbook; trusted tools fs_read/fs_write/grep/glob/code/thinking only; exit 0, ~35s). The harness reverted `config/horizon.php` and `tests/Feature/Horizon/HorizonConfigTest.php` to base `42a4310f` with git, updated the spec mirror and self-check, committed `1d41491b` and pushed from the Mac.
- Change: `RdnBackfillBranches::QUEUE = '{low}'` (the job's own queue); the drain throttle stays (wait while `Queue::size('{low}') >= --chunk`), so the backfill never floods `{low}`, which also carries transactional mail. Test renamed to `test_dispatches_newest_first_on_low_queue`. Runbook, docblocks, `--chunk` help and spec mirror no longer mention a dedicated queue, supervisor or env var.
- Proof: `git diff --stat 42a4310f HEAD -- config/` empty; `git diff 42a4310f HEAD | grep -E '^\+.*\benv\('` no matches; no `.env*` / `config/` file in `git diff --name-only 42a4310f HEAD`; `git grep HORIZON_SUPERVISOR_RDN_BACKFILL_PROCESSES` 0 hits.
- Tests from the box (Mac has no `vendor/` / `.env.testing` and no Path A reach): spec suite 206 passed / 4 skipped / 0 failed, 833 assertions, 58.6s; `RdnBackfillBranchesTest` 11 / 66. Pint clean. CH twin reseeded 7:51am CDT (hotlist 18 / FINAL 17, recoveries 8, recovery_scans 9, MO 9, transitions 12).
- Open: Jira AC-2 text still says dedicated queue + supervisor (superseded by the ruling; not edited here). Next: Review rn=3.

## 2026-10-08 8:23–8:40am CDT — inline rewrite (no queue push)

- Direction (Dalton GO 8:21am CDT via PM): run the backfill inline, never on `{low}` or any queue; simplest/minimal first.
- Mac worktree was already clean at `b907fbe3` (no leftovers from the stopped attempt, so no stash). Implement agent under the harness on the Mac (worktree-local agent, write guard limited to the command, its test and the runbook; trusted tools fs_read/fs_write/grep/glob/code/thinking only; exit 0, ~3.5 min). Harness checked the diff against the fences, then added `freezeTime()` to the limiter test and corrected the runbook hard-stop line (a running command keeps its boot-time config). Spec mirror edited by the harness.
- Change (`9928ac09`): for each eligible hotlist the command admits one call on `rdn-api:{org}` (`Org::rdnRateLimit()`, sleeps while full), then calls `GetOrgHotlistCaseInfo::handle()` in-process with one RDN client built like the dry run's. The job file is untouched. A per-row throw is logged (`rdn.backfill_branches.failed`) and counted; the run continues. Removed the drain throttle, `QUEUE`, `POLL_SECONDS`, the unique-lock dispatch and the dispatched/suppressed counters. Resume = the existing per-window cursor.
- Left out on purpose (minimal): the unique lock, a throttle-exception backoff, new options/checkpointing, any job extraction.
- Proof (box, Path A): `RdnBackfillBranchesTest` 11 / 64; spec suite 206 passed / 4 skipped / 0 failed, 831 assertions, 62.2s; `GetOrgHotlistCaseInfoTest` 47 passed; Pint clean; `config/` diff vs base empty; no new `env(`; command grep for `onQueue|dispatch\(|Queue::` → nothing. CH reseeded 8:33am CDT (hotlist 18 / FINAL 17, recoveries 8, recovery_scans 9, MO 9, transitions 12).
- Jira: AC-2 already says inline (no queue dispatch), so it was not edited. Implement comment 43369. Next: Review rn=4.

## 2026-10-08 9:05–9:15am CDT — Review rn=4 act-on (limiter bucket + docs)

- Review rn=4: PASS 90/100 at `3de5e5f7`, 0 blockers / 2 should-fix. Ruling (a): no unique lock on the inline run is acceptable.
- Should-fix 1 (`139a7a09`): the inline gate now waits on and hits the job's own `rdn-case-info` bucket instead of the shared `rdn-api:{org}` key. Per row: build the job, `$limit = RateLimiter::limiter('rdn-case-info')($job)`, key `md5('rdn-case-info'.$limit->key)`, `tooManyAttempts(key, $limit->maxAttempts)`, `hit(key, $limit->decaySeconds)`, then `$job->handle()`. Same formula as `Illuminate\Queue\Middleware\RateLimited` (vendor L78, laravel/framework v12.66.0). Tests: the writes test asserts 4 hits on the case-info key and 0 on `rdn-api:{org}`; the wait test is renamed `test_waits_on_the_jobs_rdn_case_info_limiter` and asserts the case-info key. No new test, class, config or env.
- Should-fix 2 (same commit, by the harness): runbook §3/§4 (plus "every run that ends normally logs"), spec mirror, harness self-check and PR body now describe the inline loop, the `rdn-case-info` bucket and no unique lock; the off-peak caveat is gone.
- Implement agent ran on the Mac worktree with the trust-scope fix (worktree card: writes untrusted and limited to the worktree, harness dirs denied; trusted read/search tools only; pre-launch check passed), exit 0 in ~49s. Fence probe: one write to `<wt>/<harness-config-dir>/` (let through by the write guard on purpose) was refused by the card's denied paths ("arguments supplied are forbidden: <wt>/<harness-config-dir>/**"); no file created.
- Proof (box, Path A): spec suite 206 passed / 4 skipped / 0 failed, 833 assertions; `RdnBackfillBranchesTest` 11 / 66; `GetOrgHotlistCaseInfoTest` 47 / 147; Pint clean; `config/` diff vs base empty; no new `env(`; no `onQueue|dispatch\(|Queue::` in the command. CH reseeded ~9:10am CDT (18 / 17 / 8 / 9 / 9 / 12).
- Jira comment 43370. Next: Review rn=5 (small delta).
