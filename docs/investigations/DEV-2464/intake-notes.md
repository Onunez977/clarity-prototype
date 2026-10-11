# DEV-2464 — intake

**Summary:** Company Metrics — LPR Repossessions card on the case-claim → repo funnel
**App:** Clarity (platform) · **Route:** feature (Story) · **Base:** main
**Epic:** DEV-2409 (Company Metrics Dashboard — 9 cards)
**Blocked by:** DEV-2460 (foundation — QA Complete, merged to main via PR #384 / d46b5a6f)
**Blocked by:** DEV-2463 (Unique LPR Case Claims card — **still To Do, NOT built**)

## Route/base evidence
- `git merge-base --is-ancestor origin/claude/DEV-2460 origin/main` => not ancestor, but the
  DEV-2460 merge commit 855600e5 is reachable from main via d46b5a6f (PR #384, company-metrics
  epic branch). The foundation seam is verifiably on main:
  `git grep -n 'lockedStat' origin/main -- app` hits
  `app/Filament/Widgets/Dashboard/CompanyMetricsOverview.php:185`,
  `app/Filament/Widgets/Dashboard/Concerns/DashboardMetricCard.php:54`,
  `app/Filament/Widgets/Dashboard/Concerns/RendersDashboardStat.php`.
- Sibling cards already landed on main: DEV-2461 (b6df2039), DEV-2462 (8d1cadd6).

## Route CORRECTION carried on the ticket (Jira comment 39117)
The description's decided table says **Postgres `zod`**. Comment 39117 (2026-09-04) supersedes it:
the whole funnel — including the expensive `recoveriesCte()` leg — reproduces in **ClickHouse**
(`zod.public_hotlist_status_log` 388,885,025 rows / 2.31 GiB; `zod.public_hotlist` 156,096,149 /
5.30 GiB are both mirrored). Measured Jul 2026 ls-claim-off: deduped 11,975 (AC target 11,975),
DB repos 9,130 (AC target 9,116-9,117, +14 = 0.15%, live-`hotlist` drift direction predicted by
the ticket's own port rule 4). ~32s cold / 8.65s warm client wall clock vs ~87s for the Postgres
recoveries leg. Recommendation on the ticket: build on ClickHouse.

## Session-start environment work (recorded, not silent)
- Reverted boost-regen drift in the worktree (`CLAUDE.md`, `AGENTS.md`, `.junie/guidelines.md`) —
  `npm run build` rewrites these; not this ticket's change.
- Renamed the harness worktree branch `claude/dev-2464-8ffbc1` -> `claude/DEV-2464` and
  `git reset --hard origin/main` (a4c59ee7). No push of the old name ever happened.
- Moved the PRIOR ticket's QA artifacts out of `.context/` (they were DEV-2441-era, dated
  2026-09-01) to `/private/tmp/claude-501/stale-context-backup/`, and removed
  `.ai/review-manifest.json`. A stale `qa-ac-results.json` false-passes the Phase 6 AC fold-in and
  a stale review manifest false-passes the review gate.

## Known-open going into prep (from Jira comment 39129, all named "with Oscar" or "confirm")
1. titlemax-viewer disclosure — TitleMax reads 51.0% repo rate vs a 73-89% pack because their
   repos happen in their own RepoSystem. Customer-facing copy. **With Oscar.**
2. Pennant gate tier — new `FeatureEnum` case at `CLARITY_PRO` + `CLARITY_PRO_PLUS`. "Confirm the
   tier only."
3. 83% of orgs read 0 on this card (256 of 1,536 orgs appear on either side of a deduped claim in
   Jul 2026). **With Oscar.**
4. No server-side resource envelope: `readonly_user` lacks `READ ON REMOTE`, so
   `clusterAllReplicas(system.query_log)` is denied and `read_rows` / `read_bytes` / peak memory
   are UNKNOWN — only client wall clock is measured. The ticket's own comment says "Given this is
   the epic's most expensive card, get those numbers before the route is frozen."
5. **DEV-2463 is To Do.** The description says to run it first because it proves the shared claim
   CTE at low risk and settles the inherited questions. The questions were settled by
   investigation (comments 39117/39129) but the shared CTE has not been built or shipped.

## Answered on the ticket (do NOT re-litigate)
- This card's own owned question: **DB repos alone, one number** in the compact card (comment
  39129) — keeps the dashboard consistent with backyard `ClarityCommercialFeeSummary`, which calls
  `RepoReport::monthToDateDbRepos()`. Full funnel in the expanded state is additive/optional.
- `ls-claim`: **out** (1.8% of claim volume, zero in Jul 2026, already excluded by
  `clarity.recoveries_mv`, backyard's default).
- Eloquent-on-`zod` vs raw-SQL query class: **moot** on the ClickHouse route.
- Org axis `(forwarder_org_id = ? OR agent_org_id = ?)`; one `Cache::flexible([3600, 86400])` per
  resolved window (never through `getMonthlyData()`'s 13-month loop); reject `insightlpr` only,
  **never** filter `titlemax` in the counting path; UTC calendar month, inclusive `BETWEEN` on the
  integer epoch `ts`; dedup on `(forwarder_org_id, agent_org_id, vin)` per the SQL not the
  docblock prose; discriminator column is `event_type` not `type`; staging is not an extra
  predicate.
- Do NOT port `LprRepossessionCountQuery` / `LprRepossessions` from `claude/DEV-2409` — those
  implement the definition Oscar rejected outright (DEV-2409 comment 38627).
