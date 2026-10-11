<!-- ticket-status -->
**Ticket:** [DEV-2928](https://insightlpr-team.atlassian.net/browse/DEV-2928)
**Phase:** HITL answered (Dalton, 11:29am CDT 2026-10-08) · **Undrafted** · Done Prep + Plan + Provision + Implement + Review rn=1–5 + Agent QA (re-run at `0b6136e7`) + Verify/Build Record (Jira 43371, tip `6a86b45f`) + HITL · Next **CI (this commit, no `[skip ci]`) → QA Ready on green** · Owner **PM**
**HITL answers (2026-10-08):** Q1 `--since` default = Jan 1 of last year (YTD + comparison year); can be re-run later with an earlier `--since` · Q2 accepted: the dry run charges the client's `rdn-api:{org}` 60s keys and may refresh `org_meta.rdn_rate_limit` · Q3 accepted: org 165 branch-filtered card ~0.6–1.1 s / ~450 MB, no cap; ticket only if it shows in ClickHouse timeout logs · Q5 before the live run: plain `EXPLAIN` (no `ANALYZE`) of the selection query or a small `--dry-run`; stop if slow · Action Towing = org 167 ("Action Towing & Recovery Of Sarasota"); the post-backfill re-measure picks the busiest branches from `clarity.hotlist` · Q4 closed earlier (`recoveries.id` = hotlist id, 100%)
**branch_id → ClickHouse (confirmed):** `GetOrgHotlistCaseInfo::writeBranchId()` L477–492 upserts `public.hotlist_json` (creates the row when missing) · backfill selects no-row hotlists (`RdnBackfillBranches.php` L339–342) and runs `handle()` inline (L147–168) · PeerDB CDC → `zod.public_hotlist_json` (`clickhouse-schema.sql` L64) → `hotlist_json_denormalized_mv` `JSONExtractInt(json,'branch_id')` (L107) → `hotlist_incremental_mv` 1-min refresh → `clarity.hotlist.branch_id` (L104). See runbook "How branch_id reaches ClickHouse".
**Build Record:** Jira **43371** (2026-10-08, 9:59am CDT), PASS at tip `6a86b45f` / reviewed `0b6136e7` (supersedes 43361)
**Jira:** First Glance **43357** · Plan **43358** · Implement **43359** · Review fixes **43360** · Build Record **43361** (superseded) · HITL act-on **43364** · Inline rewrite **43369** · Review rn=4 follow-ups **43370** · Build Record **43371**
**Direction (Dalton, 2026-10-08):** no Horizon config changes, ever · 8:21am CDT: run the backfill inline, never on `{low}` or any queue · 8:22am CDT: simplest/minimal first
**This commit:** docs only (`runbook.md`, spec mirror `DEV-2928.md`, `ticket-status.md`); no product code, test or config change since `6a86b45f`
**Review rn=5:** PASS · **99/100** (bar 85) · reviewed `0b6136e7` · **Agent QA re-run:** PASS at `0b6136e7` (210 tests / 833 assertions / 0 failed on real Docker ClickHouse) · **#25 parity:** PASS (Cloud read-only; All Branches byte-identical)
**Runbook:** in the PR body (mirror `docs/investigations/DEV-2928/runbook.md`)
**Branch path:** stacked on #374's head branch (base `claude/DEV-2442`); retarget to `main` after #374 merges. This PR never pushes to #374.
**PR:** ready for review · base `claude/DEV-2442` @ `42a4310f` · tip = this CI-trigger commit
**Assignees:** Jira Dalton McCleery · GitHub DaltonMcCleery
**Follow-up (not this PR):** PHP 8.2+ dynamic-property stash on the query builder (`MissedOpportunity` / `HotlistStatusTransition`)
**Open gates:** CI green on this commit → Jira QA Ready · retarget to `main` after #374 merges
**Hard stops:** no Horizon config changes · no queue push from the backfill · Cloud CH READ-ONLY · no production / fleet-wide backfill · smallest change
**Artifacts:** `docs/investigations/DEV-2928/` (spec mirror `DEV-2928.md`, `runbook.md`, `state.json`, `journal.md`, `provision.md`, `path-a-seed-needs.md`, `harness/`) · QA evidence on the QA box (not committed)
<!-- /ticket-status -->
