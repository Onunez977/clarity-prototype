<!-- ticket-status -->
**Ticket:** [DEV-2736](https://insightlpr-team.atlassian.net/browse/DEV-2736)
**Phase:** tip-fix
**Gate:** CLEAN
**Next:** Review
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · Provision · Implement
**Open:** none
**Fleet:** :18084 / :15436 / :18127
**Q1:** B Scans chrome. Copy UniqueScansLeaderboard getOptions and makeDataset.
**Q2:** Dataset Distinct Recoveries. Subtitle Top 10 kits by recoveries. Axis Distinct recoveries.
**Q3:** A. Keep the sum of monthly top-10 maps. No org-total cache. Preflight 25 stays closed.
**First Glance:** 41855
**Sprint:** 41
**Branch path:** main→main
**Assignee:** DaltonMcCleery
**Jira assignee:** Dalton McCleery (unchanged)
**Preflight 25:** does not fire
**Cloud ClickHouse:** no reads, no writes
<!-- /ticket-status -->

# DEV-2736 journal

## Intake (2026-09-25T15:24:00Z)

Live Jira re-GET. Story, To Do, Sprint 41, Clarity, assignee Dalton McCleery. One comment, 41855, First Glance, verdict Ready, hardness Med, branch path main into main. Relates DEV-2654, and that link's status is QA Complete.

`origin/main` is `b999329615174f5e3c6bfe7f77e5c592f01c021a`.

Stop line for this run is `phase=plan`. No product PHP. No provision.

## Ultra-Prep (2026-09-25T15:29:05Z)

Read `okf/index.md`, `okf/concepts/data-architecture.md`, `okf/concepts/recovery.md`, `okf/concepts/lpr-assist.md`, and `okf/concepts/company-metrics-dashboard.md`. ClickHouse stays read-only. Wiki edits are queued, not made. A requested presentation cite has not arrived.

The Company Metrics card is `RecoveryLeader`. `getData` builds one stacked dataset per kit across 13 month labels. `RecoveryLeaderQuery::handleSql` already returns the top 10 fractional kit credits for one `repo_date` window, from `clarity.recovery_scans FINAL` and `clarity.recoveries FINAL`. The DEV-2654 audit calls that read accurate-as-is.

`StagingLiveHitLeader::getData` and `UniqueScansLeaderboard::getData` each return one dataset whose labels are kit names. Scans options have a top-10 subtitle and no stacked scales. Staging options still set `stacked: true`.

No Blade or JS file builds the chart. `theme.css` states that chart rules are omitted on purpose.

The kit-user ranking is `LeaderboardTableWidget` on `RecoveryKitLeaderboard`. It is a different page.

## Question gate (2026-09-25T15:29:05Z)

First pass said CLEAN. Revised to BLOCKED after the OKF cite. See the section below.

Preflight 25 does not fire because the plan leaves `handleSql` in place and plots the map the headline already reads.

Preflight 18 waits for provision. This phase invents no DSN.

## Ultra-Plan (2026-09-25T15:29:05Z)

`plan.md` and `plan.json`. First draft named a subtitle, an axis title, a dataset label, and a leader-bar color. Withdrawn after the cite below.

## OKF cite (2026-09-25T15:35:00Z)

Cite is OKF-only. Series, top-N, and naming are unproven. Scans and Staging rollup parity is unproven. The only proven stay-constraint is fractional kit credit on `clarity.recovery_scans FINAL` (`okf/concepts/lpr-assist.md`). Changing that math would contradict the wiki. Card keys in `okf/concepts/company-metrics-dashboard.md` are a registry. They do not define the chart.

Same-turn steer. Continue from the DEV-2654 code pattern. Ticket constraints are the ticket's. The wiki locks the fractional FINAL read only. A product naming or series rule that is not in that code parks the gate.

## Question gate revised (2026-09-25T15:35:00Z)

BLOCKED. Q1, Q2, and Q3 are in `question-gate.md`.

`StagingLiveHitLeader::getData` and `UniqueScansLeaderboard::getData` both return one dataset with kit-name labels. That list shape is in the code. The dataset label strings differ (`Staging Live Hits`, `Distinct Plates`). `getOptions` differs (Staging still stacks, Scans has a subtitle and a max-bar color). `chartSeries` on both siblings reads a shared org-total cache. `RecoveryLeaderQuery` has no org-total row.

Preflight 25 does not fire for the ranking path. `handleSql` stays. No Cloud ClickHouse read or write in this phase. If the Q3 answer adds a second query, reopen preflight 25 for that path before implement.

Next is the answers. Provision waits.

## Question gate unparked (2026-09-25T15:40:00Z)

Dalton answered the three questions. Gate is CLEAN. Phase stays plan. Next is Provision, which waits for the fleet ledger. This commit writes no product PHP and invents no DSN.

Q1 is B. `RecoveryLeader` copies `UniqueScansLeaderboard` `getOptions` and `makeDataset`, including mode `nearest`, a subtitle, an axis title, and the max-bar color.

Q2 sets the dataset label to `Distinct Recoveries`, the subtitle to `Top 10 kits by recoveries`, and the axis title to `Distinct recoveries`. New chrome strings use Distinct. The heading and the tooltip stay as written.

Q3 is A. `BuildsDateFilterChartSeries::chartSeries` keeps summing the monthly top-10 maps. No recovery org-total cache. Preflight 25 stays closed because that answer adds no second query.

No Cloud ClickHouse read or write.

Playbook notes for this run. The change is larger than a one-line edit, and the operator asked for the plan, so the plan is the deliverable. Code reads settled the shape, so no prototype was built. Exploration stayed in this session because the three widget classes were named. The operator named `docs/investigations/DEV-2736/` as the artifact set, so the generic program skeleton and `check-plan.mjs` do not apply.

## 2026-09-25 ~10:49 CT — dual-plan #23

Claude draft found plan bug: getData must not use leaderboardForSelectedRange (collapsed). Adopted rankingForSelectedRange extract. Compare on disk. Gate stays CLEAN. Next Provision (ledger).

## Provision (2026-09-25T15:52:00Z)

Fleet ledger for DEV-2736 is `docs/investigations/DEV-2736/qa-prep-ledger.json`. Status is live. `mac_herd_refused` is true. `cloud_touched` is false. Passwords stay `password_ref` paths. This commit does not stand an environment and does not write product PHP.

Site `http://127.0.0.1:18084`. Container `clarity-qa-app-dev-2736`. `GET /app/login` returned 200.

Postgres `127.0.0.1:15436`. Dev database `zod_dev_2736`. Testing database `zod_testing_dev_2736`.

Docker ClickHouse `127.0.0.1:18127`. Database `clarity_dev_2736`. `recovery_scans` is present. `table_count` is 31. `cloud_write_attempted` is false.

Refused Mac Herd. Refused cross-key ports. DEV-2738 is `:18085` / `:15437` / `:18128`. Torn DEV-2659 is `:18083` / `:15435` / `:18126`. SCHEMA-DRIFT is `:18125`. Torn DEV-2654 is `:18124`.

Phase is provisioned. Gate stays CLEAN. Next is Implement.

## Implement (2026-09-25T16:00:18Z)

Product tip is `dd61b327e3b15691e254b28cb9dd1367fb320fd3`. Provision head was `93d7791e1cf4e62512037d2d538fcd97363ef7f6`. The diff against that head is the two product files below. `okf/concepts/lpr-assist.md` still locks fractional kit credit on `clarity.recovery_scans FINAL` via `RecoveryLeaderQuery`. That query is unchanged.

`rankingForSelectedRange()` is the name-to-float map before `summarizeMonth`. `leaderboardForSelectedRange()` returns `summarizeMonth` of that map, so the collapsed headline stays leader and runner-up. `getData` builds one dataset, `Distinct Recoveries`, from the ranking. Labels are kit names in query order. `this_month` still uses the last `getMonthlyData` bucket. `generateKitColors` is gone. `getGraphLabels` stays. `chartSeries` stays `BuildsDateFilterChartSeries`. No ranking memo.

Chrome copies `UniqueScansLeaderboard` `getOptions` and `makeDataset`. Subtitle `Top 10 kits by recoveries`. Axis `Distinct recoveries`. Mode `nearest`. Max bar `rgba(34, 197, 94, 0.6)` / `rgba(34, 197, 94, 1)`. Field zinc oklch pair. Heading `LPR Recovery Leaderboard` and the tooltip stay as written. New chrome strings do not say Unique.

`vendor/bin/pint --dirty --format agent` passed. `php artisan test --compact --filter=RecoveryLeaderTest` passed 24 tests, 138 assertions, 0 failed, 7.53s. The suite stubs `RecoveryLeaderQuery`. Local Postgres `zod_testing` on `127.0.0.1:5432`. No Cloud ClickHouse read or write. Fleet ports were not used. Ledger cite stays `:18084` / `:15436` / `:18127`. `cloud_touched` is false.

Phase is implemented. Gate stays CLEAN. Next is Review. Draft PR #468 stays draft. Assignee DaltonMcCleery.

## Phase — Review rn=1 (2026-09-25 CDT)

Adversarial Review on product tip `dd61b327e3b15691e254b28cb9dd1367fb320fd3`. Branch tip `1930a8c42699ae63c351f87da153f9886714c6f4` (docs after product). Draft PR #468.

Ship: RecoveryLeader expanded chart is one selected-window series (kit labels, fractional values) with Scans-matching chrome and Distinct strings. Collapsed headline unchanged via rankingForSelectedRange → summarizeMonth. RecoveryLeaderQuery untouched. Q1–Q3 matched. No Unique in new chrome.

Scores: correctness **94** / conventions **92** / blast-radius **95**. min=92 threshold=85. Gate PASS. Act-on Implement: **NONE**. `reviewed_sha` = branch tip. Next: **Pippin** Agent QA (fleet `:18127`).

Soft: getOptions intent-copy not byte-identical; Pint claimed by Implement; live CH prove waits Agent QA.

Blocking FAIL: none. Artifacts under `.ai/` (not committed).


## 2026-09-25 12:38 CDT — Verify / Build Record done

- Agent QA PASS on real Docker CH (`CLICKHOUSE_DRIVER=clickhouse` @ `:18127`, `cloud_touched=false`). Soft: stubs dominate asserts; process driver proven clickhouse.
- Build Record posted as Jira comment **41869**.
- #18 PASS · #25 N/A (query stay / `RecoveryLeaderQuery` 0-line vs main).
- Review rn=1 scores 94/92/95 · `rerun_required=false` · no post-review product rework.
- `phase=done` · Next Undraft / CI / QA Ready. PR #468 left **DRAFT**.

## Tip-fix (2026-09-25T19:50:31Z)

QA comment 41882, Case B. Select a non-this_month date toggle, then expand LPR Recovery Leaderboard. The collapsed stat followed the page filter. The newly mounted chart stayed This Month. Case G (toggle while the chart is already open) already worked through `refresh-dashboard`.

`RecoveryLeader::mount()` read only `$this->filters['date_toggles']` and fell through to `this_month`. The page toggle is also nested at `date.date_toggles`, and Filament injects the page filters as `pageFilters`. Mount now resolves flat or nested `date_toggles` on widget filters and on `pageFilters`, and it keeps a date the expanded card already injected. `Dashboard::resolvePageDateToggle()` uses the same two shapes for the expanded widget data, the card-query date, and `refresh-dashboard`.

`RecoveryLeaderQuery` is unchanged. No Cloud ClickHouse read or write. Fleet was not re-stood.

`vendor/bin/pint --dirty --format agent` passed. `php artisan test --compact --filter=RecoveryLeaderTest` passed 29 tests, 164 assertions, 0 failed, 7.28s. `RecoveryLeaderQuery` is stubbed. Local Postgres `zod_testing` on `127.0.0.1:5432`. Dashboard date tests: `test_expanded_recovery_leader_receives_the_page_date_toggle` and `test_card_query_uses_a_nested_page_date_toggle`, 2 passed, 7 assertions, 3.44s.

Phase is tip-fix. Next is Review. Code tip is `b4bd97fb9a487bd97e6847ee691d76c5438ca333`. PR #468 stays open. Draft state was already undrafted and was left alone. Assignee DaltonMcCleery.

## Review rn=2 tip-fix (2026-09-25T19:55:59Z)

Jeff FAIL 41882 Case B — select-then-expand date sync. Product tip `b4bd97fb9a487bd97e6847ee691d76c5438ca333`. Branch tip `89a3c0f2344f914d94a38fdb6537330d7733badd`.

Gate PASS min 93 (correctness 94 / conventions 93 / blast-radius 95). Act-on Implement: NONE.

Mount resolves flat or nested date_toggles on widget filters and pageFilters, and keeps an injected expand date. Dashboard resolvePageDateToggle feeds expand inject, card-query date, and refresh-dashboard. RecoveryLeaderQuery stay. Sibling leaderboard mounts not broadened (cite DEV-2736 only).

Soft: sibling mounts still flat without inject; Pint claimed; query stubbed; prior Agent QA on rn=1 tip.

Next: Undraft / CI / Jeff re-FREEZE.
