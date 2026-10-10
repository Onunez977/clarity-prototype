<!-- ticket-status -->
**Ticket:** [DEV-2654](https://insightlpr-team.atlassian.net/browse/DEV-2654)
**Phase:** Implement tip-fix
**Done:** intake · Ultra-Prep · Question Gate (CLEAN / Option A after the shared cache-helper work merged) · Ultra-Plan · ticket provision (fleet Docker CH cite) · Implement · prior card-level timezone tip-fix
**Next:** Review
**Open questions:** none
**Ship:** Staging Live Hit rollup for every date arm, including `this_month`. Class-neutral `rememberedRange` (`-v1`, `:tz=`). Staging Live Hits headline reads `totals[0]`. Recovery Leader asserts `Y-m-d`, reports a throwable, and does not cache the failure. `recovery_scans` audit cites the view-without-FINAL Cloud measure. That gap is not implemented.
**Provision:** fleet Docker cite. Site `http://127.0.0.1:18082` (`clarity-qa-app-dev-2654`). Postgres `127.0.0.1:15434` `zod_dev_2654` + `zod_testing_dev_2654`. ClickHouse strategy `docker`, container `clarity-qa-clickhouse-dev-2654`, HTTP `:18124`, keyed `clarity_dev_2654` + `clarity_testing_dev_2654` and zod twins `zod_dev_2654` + `zod_testing_dev_2654`. `billing_claim_events`, `billing_claim_events_v`, and `billing_claim_events_mv` proven on the keyed Docker database. `cloud_ch_writes` false. Mac Herd refused. Cloud ClickHouse not touched. Soft-OK vs the RecoveryCounts batching neighbor (left untouched).
**Jira assignee:** DaltonMcCleery (unchanged). Workflow status is QA Ready, already past To Do, so it was not transitioned.
<!-- /ticket-status -->

2026-09-22 18:30 CDT · intake · intake complete: route=feature app=clarity base=main gate=BLOCKED

## Intake

Re-GET DEV-2654 at 2026-09-22 (cloud `150e0ba0-1d8e-4ac4-82da-ee78a512c3cc`). Live fields match the brief:

- Story · Medium · To Do · Sprint 41 · Application Clarity · assignee Dalton McCleery. Not reassigned. Workflow status not transitioned.
- Parent: the Company Metrics epic (Epic, In Code Review).
- Relates the ratio and claims timezone work (In Progress), the shared cache-helper work (To Do), the view-without-FINAL gap (To Do).
- Description still owns Part 1 (Staging Live Hit → rollup), Part 1b (TZ moved here from the ratio and claims timezone work), Part 2 (`recovery_scans` audit), AC1–AC7.
- No comments on the issue.

Branch `claude/DEV-2654` cut from `origin/main` `f52b5d85`. No remote branch of that name existed. Not stacked on the ratio and claims timezone claim.

Stop rule for this run: Question Gate. No Ultra-Plan, no qa-prep provision, no product code.

## Ultra-Prep (fast pass, read-only)

Code on main confirms the ticket's premise and corrects several line numbers. Current lines are in `prep-context.json` → `grounding.line_drift`.

**Part 1 — filter and rollup.** `StagingLiveHitLeader::leaderboardForSelectedRange()` (L311-331) sends `today` / `last_7d` / `last_month` through one `StagingLiveHitLeaderQuery::query()` window. `this_month` (the default) returns the last bucket of a filter-independent 13-month `handleMonthly()` spread (`GROUP BY month, kit_id` … `LIMIT 10 BY month`, query L91-113). There is no `GROUP BY kit_id WITH ROLLUP` and no key `0` org total. The reference rollup is `ScanPlateStatsBatchQuery::handleRangeSql()` (L270-278) plus `handleRange()` writing `totals[0]`. `UniqueScansLeaderboard::resolveRangeAndTimezone()` (L350-366) and `fetchRangeRollup()` (L390-400) are the per-arm zone and shared-key reads to copy. Ticket line numbers for those two methods are stale (it cites L363-378 and L403-413).

**Card vs leaderboard today is two scans, not one.** `StagingLiveHits` gets its number from `SharedHitStatsData` → `HitPlateStatsBatchQuery` (four hit-type `uniqExactIf` columns, org total only, no kit dimension). `StagingLiveHitLeader` both reads and fills those shared keys for its sparkline (`resolveSharedMonths` / `resolveSharedCurrentMonth`), and runs a separate top-10 query for the ranking. AC2's "one scan, rollup key 0" is the Unique Scans contract, which this pair does not have yet. The leader is an extra writer of the four hit-card prefixes; `SharedHitStatsData` still warms those keys itself (`resolveCurrentUniqueMonth`). A reshape must not assume the leader is the only writer, and must not drop the fill in a way that changes the four hit cards' headlines.

**Part 1b — timezone.** `StagingLiveHitLeaderQuery` interpolates `start`/`end` after `assertDatetime()` only (L52-57, L97-98) and buckets `toStartOfMonth(hit_created_at)` in UTC. The widget mints naive viewer-local strings (`leaderboardForSelectedRange` via `resolveCardRange()`, `resolveSharedCurrentMonth` L693-694, the monthly batch L637-638). `ScanPlateStatsBatchQuery::toUtcDatetime()` (L90-97) and `safeTimezone()` (L149-154) are the copy source. `safeTimezone()` falls back to UTC and does not throw. `assertDatetime()` does throw, but only on a non-`Y-m-d H:i:s` string.

**Must-not-regress, already tested.** `uniqExactIf(ocr, hit_type = 'S')`, `kit_org_id` (not `hotlist_org_id`), and `kit_id` keying are in `StagingLiveHitLeaderQuery`'s docblock and pinned by `tests/Feature/Clickhouse/StagingLiveHitLeaderQueryTest.php`. `hits_cold` sort key leads with `hotlist_org_id`, then `toYYYYMM(hit_created_at)`, `hit_created_at`, `id` — `kit_org_id` does not prune. That is why the ticket forbids switching the scope to `hotlist_org_id` (`forOrg()` drops Staging Live). `FINAL` does not collapse duplicate hit ids when `hit_created_at` changed, which is why a bare `count()` is forbidden.

**AC5 — helpers do not exist.** Hand-rolled cache is real: `LEADER_CACHE_PREFIX` (L50), `leaderCacheKey()` (L447), `dashboardCacheTTL()` (L425-434) re-implementing the year / 10 min / 10 s impersonation ladder because this card extends `ChartWidget` and bypasses `ClickhouseQuery::query()` on the monthly path. The shared cache-helper work (To Do) describes extracting class-neutral shared keys, the TTL ladder, and the impersonation collapse. What exists today is scattered: `ScanPlateStatsBatchQuery::sharedRangeCacheKey` / `sharedMonthCacheKey`, `LprRepossessionsQuery` and `RecoveryTotalsBatchQuery` shared keys, `ClickhouseQuery::buildCacheKey` (param concat, not a hash), `DashboardWidget::getCacheTTL`, `HeldFlexibleCache`. None of those is a helper `StagingLiveHitLeader` can switch to without either waiting for the shared cache-helper work or inlining the extraction here.

**Part 2 — audit seeds, not a verdict.** `RecoveryLeaderQuery` (56 lines) reads `clarity.recovery_scans FINAL` and `clarity.recoveries FINAL` with `_peerdb_is_deleted = 0`, filters `repo_date`, and computes `round(sum(kit_count/total_count), 2)` from `count()` of junction rows. It does not apply a 90-day `scanned_at` predicate; the wiki says that window is hardcoded in the Cloud scoring job that writes the junction, not in this query. `clarity.lpr_assists` is `SELECT DISTINCT recovery_id FROM clarity.recovery_scans WHERE _peerdb_is_deleted = 0` with no `FINAL` (the view-without-FINAL gap). Committed schema: `recovery_scans` `ORDER BY (recovery_id, scan_id)`, engine `ReplacingMergeTree(_peerdb_version)` with no is-deleted argument. The view-without-FINAL ticket text says `ORDER BY (recovery_id, hit_id)` — the audit must use the schema dump and confirm Cloud DDL, not the ticket sentence. Result map is keyed by kit **name** (`handle()` L51), which Staging Live explicitly forbids for its own card. Recorded as an audit seed, not a required code change.

**AC7 defects are present in code, unresolved.** Dates are `addslashes()`'d and compared to `repo_date` (L11-13, L31-32). `RecoveryLeader::getDescription()` passes viewer-zone `toDateString()` (L293-330). `queryClickhouse()` (L373-381) calls `RecoveryLeaderQuery::query(..., cache: true)`, and `ClickhouseQuery::query()` catches `Throwable` and returns `[]` when `app.env` is `production` or `testing` (PG-029). Each is "resolve or accept with a reason". This prep does not pick.

**Gating drift (not this ticket's change).** `StagingLiveHitLeader` has no `canViewCardData()` override. `tests/Filament/Widgets/Dashboard/StagingLiveHitLeaderTest.php` asserts entitlement is irrelevant and comments that `FeatureEnum::COMPANY_METRICS` is gone (`app/Enums/FeatureEnum.php` has no such case). `okf/concepts/company-metrics-dashboard.md` still lists this card in the Pro-gated five. Code wins. Wiki edit stays post-approval.

## Question Gate

BLOCKED. One material question, unanswered, parked before this run:

- AC5 vs the shared cache-helper work: **A** wait for the shared cache-helper work, **B** defer AC5 and ship AC1–4 plus the audit (AC6) and the Recovery Leader resolve-or-accept (AC7), **C** inline the shared helpers in this ticket.

Not chosen. No other gate question was opened. Plan, provision, and product code stay stopped.

2026-09-22 20:46 CDT · question-gate · answered A

Dalton (2026-09-22) chose **A**: wait for the shared cache-helper work to land, then resume DEV-2654 including AC5.

- `prep-context.json` Q1 `answered=true`, `answer="A"`, `answer_source="Dalton 2026-09-22"`.
- Gate stays `BLOCKED`. This schema has no `WAITING` value. Reason: waiting on the shared cache-helper work to be Done before AC5/plan.
- `state.json` records `gate_answer=A`, `waiting_on=the shared cache-helper work`. Phase stays `prepped`. Not planned. Not provisioned.
- Open questions: none. AC5 remains in scope after the shared cache-helper work is Done. Helpers are not invented here.

No Ultra-Plan. No provision. No product code. Stop.

2026-09-24 11:55 CDT · plan · Ultra-Prep refresh, gate CLEAN, Ultra-Plan

Re-GET DEV-2654 at 2026-09-24. Live Jira: Story, Medium, To Do, Sprint 41, assignee Dalton McCleery (not changed), parent, the Company Metrics epic, still In Code Review. AC text unchanged.

Drift vs the 2026-09-22 prep:

- The ratio and claims timezone work is QA Complete (was In Progress).
- The shared cache-helper work is QA Complete and merged as `d8e70005` (PR #450). Was To Do. That is the gate clearing, not a scope change.
- Comment 41604 is on the issue. The 2026-09-22 prep recorded no comments.
- Description still cites `dashboardCacheTTL()`, which the shared cache-helper work deleted. Current map is `grounding.line_drift`.
- The view-without-FINAL gap is still To Do. Its `ORDER BY (recovery_id, hit_id)` sentence still disagrees with `database/schema/clickhouse-schema.sql:37` `(recovery_id, scan_id)`.
- The RecoveryCounts batching neighbor re-GET is QA Ready. Soft-OK. Different files (`RecoveryCounts`, `RecoveryTotalsBatchQuery`).

Branch `claude/DEV-2654` rebased onto `origin/main` `d8e70005`. `git merge-base --is-ancestor d8e70005 HEAD` holds. Helpers present on that base:

- `app/Filament/Widgets/Dashboard/Concerns/ResolvesDashboardCacheTtl.php:28` `baseDashboardCacheTTL`
- `app/Clickhouse/Queries/ScanPlateStatsBatchQuery.php:476` `rememberedRange`
- `app/Clickhouse/Queries/ScanPlateStatsBatchQuery.php:603` `sharedClosedMonthTtl`
- `StagingLiveHitLeader.php:49` already uses the trait

`waiting_on` cleared. Q1 answer A is satisfied. Gate **CLEAN**. No new material question. AC7 resolve-or-accept is a plan decision, not a second gate item.

Ultra-Plan written: `plan.json`, `plan.md`. Phase `plan`. Not provisioned. No product PHP.

Next: Provision (waits fleet ledger).

2026-09-24 11:38 CDT · provision · fleet cite recorded

Provision-only. Cite is the fleet ledger at `docs/investigations/DEV-2654/qa-prep-ledger.json` (handoff copy `.context/qa-prep-ledger.json`, untracked). Ledger timestamp `2026-09-24T11:31:19-05:00`. This is not a Mac Herd site.

- Site `http://127.0.0.1:18082`, container `clarity-qa-app-dev-2654`. Login probe passed on the ledger.
- Postgres `127.0.0.1:15434`, databases `zod_dev_2654` and `zod_testing_dev_2654`.
- ClickHouse strategy `docker`, container `clarity-qa-clickhouse-dev-2654`, HTTP `:18124`. Keyed databases `clarity_dev_2654`, `clarity_testing_dev_2654`, `zod_dev_2654`, `zod_testing_dev_2654`.
- `billing_claim_events`, `billing_claim_events_v`, and `billing_claim_events_mv` proven on Docker `clarity_dev_2654` (table_count 31). `cloud_ch_writes` false. `cloud_touched` false. `mac_herd_refused` true.
- Soft-OK vs the RecoveryCounts batching neighbor. That neighbor stays live and untouched. Its ports are not this key's env.
- `recovery_scans` stays a cite of the view-without-FINAL gap. Not implemented here.

`state.json` phase `provisioned`, gate `CLEAN`, next `implement`. No product PHP. No Cloud ClickHouse writes.

Next: Implement (Staging Live Hit UniqueScans rollup + date filter; Part 1b TZ; AC5 cache from the shared cache-helper work; recovery_scans audit; RecoveryLeader TZ/Throwable — per `plan.json`).

2026-09-24 11:59 CDT · implement · t1–t5 shipped

Product PHP for plan subtasks t1–t5. Phase `implemented`. Next is Review.

- **t1** `StagingLiveHitLeaderQuery` rolls up with `GROUP BY kit_id WITH ROLLUP` and `GROUPING(kit_id) AS is_rollup`. Bounds go through `ScanPlateStatsBatchQuery::toUtcDatetimeFor`. `sharedRangeCacheKey` is class-neutral (`staging-live-hits:range-v1`, `:tz=`). `rememberedRange` uses `baseDashboardCacheTTL(0)`.
- **t2** `StagingLiveHitLeader` reads that rollup on every date arm, including `this_month`. `LEADER_CACHE_PREFIX`, `leaderCacheKey`, and the monthly ranking cache are gone. The org-total sparkline still uses the shared hit-stats keys.
- **t3** `StagingLiveHits::getCardTotal()` reads `totals[0]` from the same `rememberedRange`. `SharedHitStatsData` and the other hit cards are unchanged.
- **t4** `RecoveryLeaderQuery` asserts `Y-m-d` instead of `addslashes`. `RecoveryLeader::queryClickhouse()` calls `RecoveryLeaderQuery::handle()` inside `cache()->remember`, `report()`s a throwable, and returns `[]` without caching the failure. Graph labels use `timezone ?? 'UTC'`. `ClickhouseQuery` is unchanged.
- **t5** `docs/investigations/DEV-2654/recovery-scans-audit.md`. Fleet twin was empty (`recovery_scans` 0 / FINAL 0, `lpr_assists` missing). The view-without-FINAL cite uses that Cloud measure. That gap is not implemented.

Not edited: `ScanPlateStatsBatchQuery`, `ResolvesDashboardCacheTtl`, `SharedHitStatsData`, `ClickhouseQuery`, `RecoveryCounts`, `RecoveryTotalsBatchQuery`.

PHPUnit (worktree `.env.testing`, ClickHouse driver is not `clickhouse`): 111 passed, 0 failed, 9 skipped, 485 assertions, 22.65s. Skips are the `RequiresClickhouse` data tests. SQL and cache-key guards ran in `StagingLiveHitLeaderQueryContractTest`. No Cloud ClickHouse writes.

Next: Review.

## Review rn=1 — 2026-09-24

PASS min=88 (correctness 92 / conventions 88 / blast-radius 93) on tip `31220a4`. Act-on Implement: none. Soft notes only (stale OKF ::query() prose deferred; pgsql RequiresClickhouse skips are not a real-CH done-signal). Soft OK vs the RecoveryCounts batching neighbor holds. Next: Agent QA on fleet Docker CH :18124.

## Verify / Build Record (2026-09-24 12:11 CDT)

- Agent QA PASS real Docker CH: 122/586/0 skip @ :18124; Pint 11; Live NOT_COVERED soft.
- AC walk AC-1..7 + PREFLIGHT-18 SATISFIED; LIVE NOT_COVERED.
- Thin conventions #16 PASS (Sam 88; Pint; OKF soft OK).
- Build Record Jira **41650**. Gates: BUILD_RECORD_GATE pass id=41650; REWORK_GATE pass (investigation-only since reviewed_sha).
- PR #436 ticket-status → Phase Verify/BR done · Next Undraft/CI/QA Ready. Stays DRAFT. Jira status left In Progress.
- phase=done. Soft OK vs the RecoveryCounts batching neighbor. No undraft / QA Ready / merge / Status Board.

## CI tip-fix — 2026-09-24

Pint shard 2 failed `DashboardCardMetricsTest` (`StagingLiveHits::getCardTotal()` got 0, expected 222). DEV-2654 reads `rememberedRange()` totals[0] for that headline. The metrics test still seeded `sharedUniqueRangeStats` for all four widgets.

LiveHits, HistoricalHits, and StagingHistoricalHits keep the 222/555 memo asserts. StagingLiveHits now seeds the rollup cache the way `StagingLiveHitsTest::seedRangeRollup` does, and still seeds `sharedRangeStats` with 555 for `getTotalHitCount()`. `StagingLiveHits::getCardTotal()` and `SharedHitStatsData` are unchanged.

Phase stays Undraft / CI HOLD. No QA Ready.

## Implement tip-fix — 2026-09-24 (D+F)

Jeff FREEZE failure on the open PR. Tip-fix only. Phase stays implemented. Jira workflow status left QA Ready. No VOID, no re-FREEZE, no Cloud ClickHouse writes, platform#462 untouched.

**F.** `RecoveryLeader::queryClickhouse()` no longer uses `cache()->remember`. It always calls `RecoveryLeaderQuery::handle()`. Success is `cache()->put` with `baseDashboardCacheTTL()`. A throwable is `report()`ed, the success key is `cache()->forget`, and the ranking is `[]`. A failure is not stored under the success key, so a warm Happy Alpha entry cannot survive a later ClickHouse error or resurrect after Happy Beta succeeds.

**D.** Invalid `users.dti` (`NotAReal/Zone`) is passed through `ScanPlateStatsBatchQuery::safeTimezone()` before every `now(tz:)` / Carbon zone use on `SharedHitStatsData`, `StagingLiveHits` (including day arms and `resolveCardRange`), `StagingLiveHitLeader`, and `RecoveryLeader` graph labels and date arms. Unlisted zones render as UTC counts.

PHPUnit: RecoveryLeader + StagingLiveHits + StagingLiveHitLeader **49 passed / 347 assertions**. Sibling hit cards + card SQL fidelity **72 passed / 255 assertions**. Pint dirty PASS.

## Review tip-fix — 2026-09-24 (D+F rn=2)

Review PASS on product tip `f43af4901eb9d14ea82314c30f8544e5e34fe546` (PR #436). Gate min **90** (correctness 93 / conventions 90 / blast-radius 92). Act-on Implement: **NONE**. `rerun_required=false`.

Jeff FREEZE FAIL (comments 41737 / 41743) checked on the tip-fix only:

* **F proved:** `RecoveryLeader::queryClickhouse` always calls `RecoveryLeaderQuery::handle` (no `cache()->remember`); success `cache()->put` + `baseDashboardCacheTTL`; Throwable `report` + `cache()->forget` + `[]`. Warm Alpha forgotten; later Beta shows. Tests cover both.
* **D proved:** `ScanPlateStatsBatchQuery::safeTimezone` before Carbon/`now(tz:)` on `SharedHitStatsData`, `StagingLiveHits` (day arms + `resolveCardRange`), `StagingLiveHitLeader`, and `RecoveryLeader`. Invalid `users.dti` (`NotAReal/Zone`) renders UTC counts without Unavailable.

Soft: Recovery Leader success put is write-through on the query path; `StagingLiveHits` still has an unrelated range-total `cache()->remember` (not Jeff F); #462 untouched; local Pint N/A (no php on review box) — CI remains the merge signal after undraft path.

Phase → reviewed. Next → Agent QA (real Docker CH) then Verify. Jeff Fix→VOID→re-FREEZE→GO stays his after Fellowship clear. Hold DEV-2659 until 2654 merges (per Implement baton).


## Verify / Build Record tip-fix D+F — 2026-09-24 ~15:09 CDT

Tip-fix re-Verify after Jeff FREEZE FAIL (41737/41743). Prior BR **41650** on `31220a49` cited; new BR **41779** on product tip `f43af490`.

- Agent QA tip-fix PASS: Pint 12; PHPUnit **127/631/0 skip**; `CLICKHOUSE_DRIVER=clickhouse` @ `:18124`; `cloud_touched=false`; Live NOT_COVERED soft.
- AC-1…AC-7 + PREFLIGHT-18 SATISFIED (PREFLIGHT-18 evidence refreshed to 127/631). Thin conventions #16 PASS (conventions 90; Pint 12; OKF soft).
- Review rn=2 intact: 93/90/92 · min 90 · reviewed_sha `f43af490` · rerun_required=false · act_on NONE.
- PR #436 already OPEN (not draft). Docs tip `8f7121c` (`[skip ci]`).
- Gates: BUILD_RECORD_GATE pass id=41779. **REWORK_GATE FAIL** — post-review merge `b9f2e047` (main → branch) between `f43af490` and `8f7121c` yields 24 non-investigation files in three-dot. **phase=done withheld** (not faked).
- Fleet env left UP. Hold DEV-2659. No QA Ready / merge / Status Board from Verify.

Next: merge-lane hygiene — clear rework-gate (Review ack of merge-only or equivalent) → CI on product tip → Jeff Fix→VOID→re-FREEZE→GO.
## Review rn=3 — light merge-only ack — 2026-09-24 15:12 CDT

Galadriel dispatch: rework-gate fail-closed because merge `b9f2e047` (main → `claude/DEV-2654`) sat between product tip `f43af490` and docs tip. Not a D+F product defect.

Light Review (not re-Implement):
- Three-dot since product tip includes ~24 non-investigation files — all from the main merge (zones, CH Scan routing, HasRetryQuery, Kit, kamal preview, `.deploy/*.asc`, build assets) plus later Verify docs. Dashboard D+F files **unchanged**.
- F and D still proved on tip (always-handle put/forget; safeTimezone before Carbon).
- `.deploy/authorization_main.txt.asc` / `commands_main.txt.asc` are merge-from-main (already on main parent), not tip-fix authorship.
- Scores **92 / 90 / 90** · min **90** · Act-on **NONE** · `rerun_required=false`.
- `reviewed_sha` bumped to PR tip `0059db59762ffc4cc85d319bab28a21acf7aad51` so REWORK_GATE can clear (HEAD-covered / docs-only delta after this stamp).

Next: Galadriel (CI trigger + Merry phase=done) → Jeff re-FREEZE→GO. Hold DEV-2659. Leave #462 alone.

## Verify closeout — phase=done — 2026-09-24 ~15:13 CDT

Galadriel cleared rework-gate blocker via Review rn=3 light merge-only ack (`reviewed_sha=0059db597`, scores 92/90/90, act_on NONE). Product tip `f43af490` intact. BR **41779** unchanged.

Gates:
- BUILD_RECORD_GATE: pass id=41779
- REWORK_GATE: pass (only investigation artifacts since review)

`phase=done`. waiting_on cleared. Next = CI (PM) → Jeff Fix→VOID→re-FREEZE→GO → merge-lane. PR #436 OPEN. Env UP. Hold DEV-2659. No QA Ready / merge / Status Board from Verify.

## Implement tip-fix — 2026-09-24 (Company Metrics monthly timezone)

Comment 41810: Playwright D FAIL (6/7). Cases A B C E F G PASS. Company Metrics returned a Filament 500, `InvalidTimeZoneException: Unknown or bad timezone (NotAReal/Zone)`, when `users.dti` is `NotAReal/Zone`.

Throw site was `DashboardWidget::getMonthlyData()` calling `now(tz:)` on the raw `auth()->user()->timezone`. That runs from `getCardTotal()` → `toStat()` inside `CompanyMetricsOverview`. The grid never mounted, so Staging Live and Recovery cards never showed. The earlier card-level `safeTimezone()` calls do not cover this base monthly path. Widget suites that render already-sanitised subclasses missed it.

Product tip `f82250c368b585fcccd2bd7361ce445a9c4aa956`. `BuildsDateFilterChartSeries::viewerTimezone()` delegates to `ScanPlateStatsBatchQuery::safeTimezone()`. `DashboardWidget` monthly labels, `getMonthlyData()`, `headlineWindow()`, and the last-month TTL arm use that zone. Sibling overview Carbon uses of the raw viewer zone now do too: `MissedOpportunities::between()`, `UniqueScanCounts` day-arm `getRangeTotal()` / `between()` / card SQL timezone, `UniqueScansLeaderboard` today and last-7-days, and the date parsers on `UniqueLprCaseClaims` and `LprRepossessions`. `RecoveryLeader` dropped its private copy of the same helper so it uses the trait method. Unlisted zones, including `NotAReal/Zone`, `CST`, `PST`, and `+05:00`, fall back to UTC. `America/Chicago` stays identity. Cache-key strings that only embed the raw zone, and paths that already sanitise or already catch, were left as they were. Staging Live Hit rollup behavior is unchanged. platform#462 untouched. No Cloud ClickHouse writes.

PHPUnit (`php artisan test --compact` on `DashboardWidgetTest`, `CompanyMetricsOverviewTest`, `UniqueScansLeaderboardTest`, `UniqueLprCaseClaimsWindowSeamTest`, `MissedOpportunitiesTest`, `UniqueScanCountsTest`, `LprRepossessionsTest`, `RecoveryCountsTest`, `RecoveryLeaderTest`, `UniqueLprCaseClaimsTest`): **172 passed / 706 assertions / 0 failed** (29.39s). New coverage renders the base monthly path and the overview `getStats()` probe with `NotAReal/Zone` and asserts a September UTC window at `2026-09-01 02:00:00` UTC (still August in `America/Chicago`). Pint dirty PASS.

Phase = implemented. Next = Review. Jira status left QA Ready. PR #436 stays open.
## Review rn=4 — tip-fix Company Metrics monthly timezone — 2026-09-24 17:53 CDT

Sam review executor (adversarial + review-gate MIN≥85 with conventions lane).

Product tip reviewed: `f82250c368b585fcccd2bd7361ce445a9c4aa956`. Docs tip `4c54a56b899d56d19550ca8c33b4966d4b701acc` light-acked as docs-only after tip (not required as product). PR #436 OPEN. #462 left alone (e2e draft).

Jeff D 41810 **proved**:
- `BuildsDateFilterChartSeries::viewerTimezone()` → `ScanPlateStatsBatchQuery::safeTimezone()` before any `now(tz:)`.
- `DashboardWidget::getMonthlyData` + graph labels / highlight / headlineWindow / last-month TTL use that zone.
- Sibling overview paths: MissedOpportunities `between()`, UniqueScanCounts day arms, UniqueScansLeaderboard today/last_7d, UniqueLprCaseClaims + LprRepossessions date parsing.
- RecoveryLeader private helper removed; uses trait.
- Tests: base monthly + overview `getStats` with `NotAReal/Zone` → September UTC window; no `InvalidTimeZoneException`.
- Staging Live Hit rollup **unchanged** (`git diff f43af490…f82250c` empty for Staging*/SharedHit*). Prior F/D intact.
- Remaining raw `auth()->user()->timezone` on Company Metrics paths are cache-key string embeds only (or RecoveryCounts soft-OK / out-of-cards Admin widget).

OKF cite (not invented): `okf/concepts/company-metrics-dashboard.md` (`cards()` registry, DashboardWidget/getStats); `safeTimezone` allowlist documented on Scout CH queries. Wiki does not yet name `viewerTimezone` (soft lag).

Scores **93 / 91 / 90** · min **90** · threshold **85** · Act-on **NONE**.
`REVIEW_GATE: pass min=90 threshold=85 lanes=3 rn=4 scores=93/91/90 conventions=91 sources=in-session-review:3 source=lane-min`

`reviewed_sha` = product tip `f82250c…`. phase=reviewed. Next = **Pippin**. Soft ≤5 listed in lane files. Blocking FAIL: none. Do not git add `.ai/`.

## Verify / Build Record tip-fix Jeff D — 2026-09-24 18:03 CDT

Tip-fix re-Verify after Jeff FREEZE FAIL **41810** (invalid viewer TZ on Company Metrics monthly). Prior BR **41650** + **41779** cited; new BR **41815** on product tip `f82250c`.

- Agent QA tip-fix PASS: Pint 23; PHPUnit **172/795/0 skip**; `CLICKHOUSE_DRIVER=clickhouse` @ `:18124`; `cloud_touched=false`; Live NOT_COVERED soft.
- AC-1…AC-7 + PREFLIGHT-18 SATISFIED (PREFLIGHT-18 evidence refreshed to 172/795; AC-3 extended to monthly overview path). Thin conventions #16 PASS (conventions 91; Pint 23; OKF soft).
- Review rn=4 intact: 93/91/90 · min 90 · reviewed_sha `f82250c` · rerun_required=false · act_on NONE.
- PR #436 already OPEN (not draft). Docs tip `1248e0f` (`[skip ci]`).
- Gates: BUILD_RECORD_GATE pass id=41815. REWORK_GATE pass (investigation-only since reviewed_sha).
- `phase=done`. Fleet env left UP. Hold DEV-2659. No QA Ready / merge / Status Board from Verify.

Next: CI green → Jeff re-FREEZE→GO → merge-lane (PM).
