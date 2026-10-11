<!-- ticket-status -->
**Ticket:** [DEV-2555](https://insightlpr-team.atlassian.net/browse/DEV-2555)
**Phase:** implemented · **Route:** feature · **Mode:** normal
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · implement · review · agent-qa (PASS) · Build Record (comment 41334) · undraft · QA FAIL Off GO · tip fix (fleet alias allowlist on safeTimezone) · review-2 FAIL MIN 58 / reviewed_sha 2e39673b16c604fec5a18efcb55efbde34895170 · fix (canonical fallback when the alias is unconstructable) · review-3 PASS MIN 90 / reviewed_sha fcd59300304b838e7dad1d225feafd39e4d15ad7 · sibling-cite scrub (new docblock lines only) · journal scrub · rebase onto main 25870f756 (0 behind) · Playwright FAIL on tip 229b984 (viewer timezone 500 + Company Metrics 500) · filter viewerTimezone + per-card unavailable stat
**Next:** Review on tip `9213a42a256a8cbd80af6bd72e47ef62fa7756ae`. Soft OK vs SCHEMA-GATE. Sprint 41 Path A. Jira workflow not transitioned.
**Open questions:** none
**Jeff FAIL:** RecoveryCountsTest 27 pass / 1 fail. `test_ratio_numerator_records_the_sanitised_zone_and_to_stat_does_not_throw` expected `US/Central`, got `UTC`. PHP 8.4.25 drops the `US/Central` BC alias. Prior tip `bf648a284`.
**Review-2:** correctness 64, conventions 90, blast-radius 58. Identity-keep throws on this PHP (`handleSql` / `now(tz:)`). `rerun_required=true`. Jira left at QA Ready. PR #439 undrafted.
**Review-3:** correctness 93, conventions 91, blast-radius 90. MIN 90. `rerun_required=false`. Act-on Frodo: NONE. `reviewed_sha` `fcd59300304b838e7dad1d225feafd39e4d15ad7`.
**Ship:** Unconstructable BC aliases remap to canonical IANA. Constructable aliases stay themselves. CST/PST/+05:00/garbage stay UTC. Dashboard filters now call that helper. A thrown Company Metrics card renders `--` / Unavailable. `cloud_ch_writes=false`. Next is Review.
**Assignee:** DaltonMcCleery (already on PR #439).
<!-- /ticket-status -->

# DEV-2555 journal

## Phase 0 — Intake (2026-09-23T18:04:00Z)

Live re-GET of DEV-2555 (id 31015). Story, To Do, Medium, assignee Dalton McCleery, parent story, Sprint 41 (active). Relates: the recovery-totals query landing (QA Complete), the prior Total Repossessions batch landing (QA Complete).

Comments: first glance is 41178 (updated in place 2026-09-23 14:02 ET, verdict Ready). 41327 is an obsolete duplicate of that glance. Assignee not changed.

Base: `origin/main` @ `4833e815` (Company Metrics viewer-timezone ratio numerators, #434). Branch `cursor/dev-2555-e924`.

Stop line for this run: phase `plan`. No product code. No qa-prep.

Drift vs the attached snapshot: the snapshot cites first-glance comment 41327. Live Jira marks 41327 obsolete and keeps the glance on 41178. Status, assignee, sprint, and the two relates links match.

## Phase 1 — Ultra-Prep (2026-09-23T18:05:40Z)

Single pass. Best-of-N not run.

The Jira body describes a card that still issues thirteen Eloquent queries and a live `UniqueScansToRepo`. On this SHA that card already calls `RecoveryTotalsBatchQuery::handle()` once and writes `sharedMonthCacheKey()` as an int. `UniqueScansToRepo.php` is gone. The query class docblock still describes the old follow-up.

The month test reads the shared key, so un-sharing it fails the test. The window test does not read `sharedWindowCacheKey`, and both doubles share one class basename, so it stays green if that write is removed. Same vacuous shape as the earlier window-key correction's first cross-card test.

That correction's re-share restored the window key as an int. The Jira body still cites the withdrawn un-share.

## Phase 2 — Question Gate (2026-09-23T18:06:00Z)

**CLEAN.** Q1, Q2, and Q3 are answered by the tree. No blocked question for Dalton.

## Phase 3 — Ultra-Plan (2026-09-23T18:06:20Z)

`plan.json` and `plan.md`. Two files later: docblocks, and the window int pin. `behavior_change` is false.

Next is Aragorn provision. Ledger stays null.

## Phase 3.5 — Provision (2026-09-23T18:16:49Z)

Provisioned via qa-prep provision-only, CH deferred.

Isolated worktree `.claude/worktrees/DEV-2555` on `cursor/dev-2555-e924`. Bootstrap created and migrated `zod_cursor_dev_2555_e924` and `zod_testing_cursor_dev_2555_e924`. Herd site `https://clarity-cursor_dev_2555_e924.test`. ClickHouse container not started; `CLICKHOUSE_HOST` neutralized to `127.0.0.1` in `.env` and `.env.testing`. Tiger `simplified_geom` patched, then baseline `db:seed` re-ran. Ledger on disk: `.context/qa-prep-ledger.json` (`provision_mode: provision-only`, `clickhouse.strategy: deferred`). Stopped before scope, seed, and plan.

Next is Frodo (Implement). Ship plan remains the docblock plus the window-key test. GitHub assignee DaltonMcCleery failed earlier with 403 — leave that for Galadriel.

## Phase 4 — Implement (2026-09-23T18:25:04Z)

Sprint 41, Path A. Plan gate CLEAN. t1 and t2 only. `RecoveryCounts.php` was not edited. SQL, key format strings, and `-v1` were not changed. `git diff -G '^[^/* ]' -- app/Clickhouse/Queries/RecoveryTotalsBatchQuery.php` is empty.

t1. `RecoveryTotalsBatchQuery` docblocks now say what main does: `RecoveryCounts` calls `handle()` for the series and `handleRange()` for Today / Last 7 Days; both cache writes are bare ints under the class-neutral keys. `getModelQueryForDates()` returns 0 and is not the live path. Eloquent `Recovery::count()` parity stays in `RecoveryTotalsBatchQueryTest`. UniqueScansToRepo was deleted in the prior Total Repossessions batch landing; the ratio denominator is `getMonthlyData()` on `RecoveryCounts`. The month key must not grow `:tz=` or change `-v1`. The window key stays shared, includes the raw viewer timezone because the window is viewer-local, and `DashboardWidget::currentPeriodTotal()`'s class-prefixed `remember` is an outer layer, not a reason to un-share (earlier window-key correction, re-share). Sentences that said the card still uses Eloquent, and that pointing it at the key is a future one-liner, are gone.

t2. `test_past_months_are_cached_per_month_and_not_refetched` keeps `assertSame(9, ...)` and adds `assertIsInt` on that shared month value; `reposCalls === 1` stays. `test_the_window_headline_is_cached_and_not_requeried` reads `cache()->get(RecoveryTotalsBatchQuery::sharedWindowCacheKey(...))` after the first `getCardTotal()` for that org, date type `today`, the end date `resolveCardRange()` feeds the key, and the acting user's timezone. The read is int `40`. The helper output contains neither `RecoveryCounts` nor `WindowStubbingRecoveryCounts`. No second widget class. A missing shared write returns null from `cache()->get` and fails the assertion even though the second `WindowStubbingRecoveryCounts` still hits the outer `buildWidgetCacheKey` remember.

OKF. `okf/concepts/company-metrics-dashboard.md` already records that landing's deletion of UniqueScansToRepo, `RecoveryCounts` entering through `RecoveryTotalsBatchQuery::handle` (failure propagates), the `class_basename` cache-key trap (why this window test must read the shared key rather than a second same-class instance), and that `repo_date` has no time-of-day so the month axis cannot follow the viewer clock. No sentence still claims `RecoveryCounts` issues thirteen Eloquent queries. Wiki left alone.

Tests, worktree `.env.testing`:

- `php artisan test --compact tests/Filament/Widgets/Dashboard/RecoveryCountsTest.php` — 28 passed, 95 assertions, 6.56s.
- `php artisan test --compact tests/Feature/Clickhouse/RecoveryTotalsBatchQueryTest.php` — 9 skipped, 0 assertions. `CLICKHOUSE_DRIVER=pgsql`, so `RequiresClickhouse` skips before any `TRUNCATE`. Host is `127.0.0.1`. Cloud and production ClickHouse were not written.

Next is Sam. PR #439 stays draft. Assignee DaltonMcCleery was already set.

## Phase 5 — Review (2026-09-23T18:35:00Z)

Sprint 41, Path A. RN=1, three lanes, in-session Sam. Scores correctness 93, conventions 92, tests 91. MIN 91 ≥ 85. `rerun_required` false. Act-on for Frodo: NONE. `behavior_change` false. Reviewed SHA `04babea2de2e98e0b61c7bc3d84dfa785227c7ef`.

`review-gate.sh` is not on this image. Harness-local gate (`/tmp/harness-review-gate.sh`) parsed the three `Score:` lines and `.ai/review-manifest.json` and printed `REVIEW_GATE: pass min=91 lanes=3 rn=1 sources=in-session-sam:3` (exit 0). Lane files stay on disk under `.ai/` and are not committed.

Pint: `vendor/bin/pint --test` on `RecoveryTotalsBatchQuery.php` and `RecoveryCountsTest.php` passed (exit 0).

Residuals, not a fix cycle: ClickHouse `RecoveryTotalsBatchQueryTest` still skipped (9) in this worktree. OKF `okf/concepts/company-metrics-dashboard.md` already current; wiki not edited.

Next is Pippin (Agent QA). PR #439 stays draft.

## Phase 6 — Agent QA (2026-09-23T18:40:00Z)

Pippin. Sprint 41 Path A. HEAD `05491bd`. behavior_change=false.

- Pint --test on ship PHP: passed.
- RecoveryCountsTest: 28 passed (95 assertions), 5.35s.
- RecoveryTotalsBatchQueryTest: 9 skipped (CH deferred). No Cloud CH writes.
- OKF company-metrics-dashboard.md read; AC1–6 SATISFIED.
- Live browser not run (no UI delta).
- Artifacts under `.context/` (not committed).

Next: Merry (Verify / Build Record).


## Phase 7 — Verify / Build Record (2026-09-23T19:39:00Z)

Merry closeout. Sprint 41 Path A. Verified Frodo ship (docblocks + window int pin only; non-comment diff vs main empty) against live Jira AC mapped via Path A / plan Q1–Q3. Pippin agent-QA PASS @ `05491bd` (28/95, Pint pass, CH suite skipped deferred). Thin conventions #16 pass (Sam conventions 92, Pint, OKF company-metrics cite).

AC1–6 SATISFIED folded into `record.ac`. Build Record posted as Jira comment **41334**; mirror `build-record.md`. `record.review.scores` arrayized to `[93, 92, 91]` for build-record-gate. Top-level `comment_ids.build_record=41334`.

Teardown: no Fellowship QA Docker containers for this key (Herd worktree + CH deferred). Mac Herd left up. Cloud CH never written. `qa.torn_down=false` with N/A note.

PR stays **DRAFT**. Next: Galadriel (undraft → CI → QA Ready).

## Jeff FAIL bounce — tip fix (2026-09-23)

Jeff Clarity FAIL Off GO on `RecoveryCountsTest`: 27 pass / 1 fail. `test_ratio_numerator_records_the_sanitised_zone_and_to_stat_does_not_throw` expected `US/Central`, got `UTC`.

Cause: PHP 8.4.25's timezone database omits the `US/Central` BC alias. `ScanPlateStatsBatchQuery::safeTimezone()` trusted `timezone_identifiers_list(ALL_WITH_BC)` and fell back to UTC. Local Mac PHP 8.4.23 still lists `US/Central` (598 ids). Jeff does not. The contract tests require the legacy aliases to be kept as themselves.

Tip before this fix: `bf648a284b2584bf1aa3dce8d73d8fd753b4ac66`.

Fix: an explicit allowlist on `ScanPlateStatsBatchQuery::safeTimezone()` returns `US/Central`, `US/Eastern`, `US/Pacific`, and `Asia/Calcutta` as themselves even when PHP's `ALL_WITH_BC` list omits them. `CST`, `PST`, `+05:00`, and garbage still become UTC and the method still does not throw. `US/Central` is not remapped to `America/Chicago`. The same allowlist is mirrored on the private `KitScanPathsQuery::safeTimezone()`. `KitRangePathsQuery` is untouched. Docblock notes that PHP 8.4.25+ may drop BC aliases and that the allowlist preserves the identity-keep contract: those four aliases stay themselves when this PHP can still construct them. RecoveryCountsTest, UniqueLprCaseClaimsTest, and LprRepossessionsTest comments that said "listed on this PHP" now describe the allowlist. Expected `US/Central` → `US/Central` is unchanged.

Jira workflow was not flipped; left at QA Ready. No Cloud ClickHouse writes. PR #439 stays undrafted and assigned to DaltonMcCleery. This commit does not carry `[skip ci]`.

Local proof (PHP 8.4.23, worktree `.env.testing`, no Cloud ClickHouse writes):

- `php artisan test --compact tests/Filament/Widgets/Dashboard/RecoveryCountsTest.php` — 28 passed, 95 assertions.
- `php artisan test --compact tests/Unit/Clickhouse/ScanPlateStatsBatchQueryContractTest.php` — 10 passed, 36 assertions.
- Pint on the touched PHP files passed.

`state.phase=implemented`. Next: Sam (review delta) then Galadriel → Jeff VOID/re-FREEZE.

## Phase review-2 — Jeff tip re-review (2026-09-23)

Sprint 41, Path A. Soft OK vs SCHEMA-GATE. RN=2, three lanes, in-session Sam, conventions lane present (preflight #16). Reviewed SHA `2e39673b16c604fec5a18efcb55efbde34895170` (parent `bf648a284b2584bf1aa3dce8d73d8fd753b4ac66`, +113/−31, 9 files). `KitRangePathsQuery` is not in the tip. Allowlist is strict identity-keep. Contract test still asserts each legacy alias returns itself. Pint `--test` on the touched PHP passed (exit 0).

Scores: correctness 64, conventions 90, blast-radius 58. MIN 58 < 85. `rerun_required=true`. Phase stays `implemented`. Act-on is Frodo, not Galadriel.

This image is PHP 8.4.25 (built Aug 28 2026), Timezone Database internal, Olson 0.system. `ALL_WITH_BC` count is 500. `US/Central`, `US/Eastern`, `US/Pacific`, and `Asia/Calcutta` are absent, and `new DateTimeZone` throws for each. `safeTimezone('US/Central')` returns `US/Central`. `now(tz: 'US/Central')` and `handleSql()` → `Carbon::parse` throw `Carbon\Exceptions\InvalidTimeZoneException` before ClickHouse. ClickHouse itself accepts `US/Central`. `CompanyMetricsOverview::getStats()` has no per-card try (`okf/concepts/company-metrics-dashboard.md`). CST/PST/+05:00/garbage still return UTC. Frodo's PHP 8.4.23 suite does not show this throw.

`review-gate.sh` is not on this image. Harness-local gate failed the MIN. Lane files stay under `.ai/` and are not committed. Jira stays QA Ready. PR #439 stays undrafted.

## Review-2 FAIL fix (2026-09-23)

Act-on from Sam review-2 (correctness 64, blast-radius 58) on product SHA `2e39673b`. Identity-keep returned `US/Central` even when `new DateTimeZone` throws, so `handleSql()` → `toUtcDatetime()` and `now(tz:)` threw `Carbon\Exceptions\InvalidTimeZoneException` before a zone was stored. `CompanyMetricsOverview::getStats()` has no per-card try.

`ScanPlateStatsBatchQuery::safeTimezone()` still has the four-alias map. When `new DateTimeZone($alias)` succeeds, it returns the alias. On `DateInvalidTimeZoneException` (and `Exception` for older PHP) it returns the canonical id: `US/Central` → `America/Chicago`, `US/Eastern` → `America/New_York`, `US/Pacific` → `America/Los_Angeles`, `Asia/Calcutta` → `Asia/Kolkata`. Never UTC for those four. Never throws. `timezone_open` is not used. `CST`, `PST`, `+05:00`, and garbage stay UTC. The same method body is mirrored on private `KitScanPathsQuery::safeTimezone()`. `KitRangePathsQuery` is untouched.

Contract and widget expectations use `expectedLegacyTimezone()`: alias when this PHP can construct it, otherwise the canonical id. `new DateTimeZone(safeTimezone($alias))` must not throw. `handleSql(['timezone' => 'US/Central', ...])` must get past Carbon. `RatioAgainstUniqueScansTest` had the same hardcoded `US/Central` → `US/Central` provider, so it follows the same oracle.

`state.phase=implemented`. Jira left at QA Ready. Assignee stays DaltonMcCleery. No Cloud ClickHouse writes. This commit has no `[skip ci]`. PR #439 stays undrafted. Next is Sam review-3. Do not hand Galadriel.

Local proof (Mac worktree PHP 8.4.23, `.env.testing`, no Cloud ClickHouse writes):

- `US/Central` constructs, `safeTimezone` returns `US/Central`. `CST` returns `UTC`.
- `php artisan test --compact tests/Filament/Widgets/Dashboard/RecoveryCountsTest.php` — 28 passed, 95 assertions, 5.46s.
- `php artisan test --compact tests/Unit/Clickhouse/ScanPlateStatsBatchQueryContractTest.php` — 11 passed, 42 assertions, 3.16s.
- The other touched providers (`UniqueLprCaseClaimsTest`, `LprRepossessionsTest`, `RatioAgainstUniqueScansTest`) were in the same run: 112 passed, 415 assertions.
- Pint `--dirty` passed.

Docker. Official `php:8.4.25-cli` does not reproduce Jeff: internal tzdb 2026.3, `ALL_WITH_BC` 598, `new DateTimeZone('US/Central')` succeeds, `safeTimezone` returns `US/Central`.

Jeff's shape is system tzdata (`Olson 0.system`) without the legacy `US/*` links. `debian:trixie-slim` `php-cli` is PHP 8.4.24, Olson `0.system`, `ALL_WITH_BC` 488, and `/usr/share/zoneinfo/US/Central` is absent. On that image, with this worktree mounted:

- `US/Central`, `US/Eastern`, `US/Pacific`, `Asia/Calcutta` throw `DateInvalidTimeZoneException`.
- `ScanPlateStatsBatchQuery::safeTimezone` returns `America/Chicago`, `America/New_York`, `America/Los_Angeles`, `Asia/Kolkata`. Each of those constructs.
- `CST` and `+05:00` return `UTC`.
- `KitScanPathsQuery::safeTimezone('US/Central')` returns `America/Chicago`.
- `KitRangePathsQuery::safeTimezone('US/Central')` returns `UTC`.
- `Carbon::parse('2026-08-01 00:00:00', safeTimezone('US/Central'))` → `2026-08-01 05:00:00` UTC. No `InvalidTimeZoneException`.

Jeff's binary was 8.4.25 with 500 ids. This probe is 8.4.24 with 488 ids. Same failure mode: system tzdata, the alias does not construct, the canonical id does.

## Phase review-3 — constructability fix (2026-09-23)

Sprint 41, Path A. Soft OK vs SCHEMA-GATE. RN=3, three lanes, in-session Sam, conventions lane present (preflight #16). Reviewed SHA `fcd59300304b838e7dad1d225feafd39e4d15ad7`. Parent `737d929f` is the review-2 record. `KitRangePathsQuery` is not in the tip.

Scores: correctness 93, conventions 91, blast-radius 90. MIN 90 ≥ 85. `rerun_required=false`. Act-on for Frodo: NONE. Next is Galadriel (Jeff VOID, re-FREEZE, fresh GO). Jira stays QA Ready. PR #439 stays undrafted.

This image is PHP 8.4.25 (built Aug 28 2026), Olson `0.system`, `ALL_WITH_BC` 500. The four aliases throw `DateInvalidTimeZoneException`. `safeTimezone` returns `America/Chicago`, `America/New_York`, `America/Los_Angeles`, `Asia/Kolkata`. Each constructs. `CST` / `PST` / `+05:00` return `UTC`. `handleSql` embeds `America/Chicago`. `now(tz:)` does not throw. `KitRangePathsQuery` still returns `UTC`. `cloud_ch_writes=false`.

`review-gate.sh` is not on this image (exit 127). Harness-local gate printed `REVIEW_GATE: pass min=90 lanes=3 rn=3 sources=in-session-sam:3` (exit 0). Lane files stay under `.ai/` and are not committed. Pint `--test` on the touched PHP passed (exit 0).

Review-2 FAIL history stays on `record.review_rn2` (`reviewed_sha` `2e39673b`, MIN 58). Bounce history stays on `bounce`.

## Jeff HOLD scrub — new sibling cites (2026-09-23)

Comment-only. New docblock lines on `ScanPlateStatsBatchQuery::safeTimezone()` / `LEGACY_FLEET_TIMEZONE_ALIASES` and the mirrored `KitScanPathsQuery` lines no longer name the sibling ticket. The new contract-test note is paraphrased the same way. Historical cites already on main stay, including the two main-era sentences in `ScanPlateStatsBatchQuery`. Older journal entries stay as written. Product PHP is unchanged. `KitRangePathsQuery` untouched. No rebase.

`state.phase` stays `reviewed`. Jira stays QA Ready. Next: Galadriel (verify scrub → ask Jeff re-FREEZE if HOLD #1 clear; #2 0-behind still deferred). Soft OK vs SCHEMA-GATE. Sprint 41 Path A.

## Fix cycle — journal scrub and rebase (2026-09-24)

HOLD 41372 declined re-FREEZE until three items were done. This cycle does those three. Jira workflow was not transitioned.

1. The added journal sentence in the tip-fix section named a sibling ticket inside the identity-keep phrase. It now describes identity-keep only: those four aliases stay themselves when this PHP can still construct them. Added lines name DEV-2555 only. Main-era product cites already on main stay, including the historical sentences in `ScanPlateStatsBatchQuery` and the contract test. Older journal sections stay as written.
2. Rebased onto `origin/main` `25870f75661f07a27a69124a414e557d4c11fff0` (SCHEMA-GATE DEV-2653 schema sync). `git rev-list --left-right --count origin/main...HEAD` is 0 behind. No path overlap with main, so no conflicts. SCHEMA-GATE / DEV-2653 Path A was not edited. Product PHP blobs for `ScanPlateStatsBatchQuery`, `KitScanPathsQuery`, and `KitRangePathsQuery` match the pre-rebase tip. `KitRangePathsQuery` matches `origin/main`. The constructability remap from `fcd59300304b838e7dad1d225feafd39e4d15ad7` is intact (rebased commit `6a20dd417abed92bdc4bbc8ef8e4ad7f92de7dc9`): for `US/Central`, `US/Eastern`, `US/Pacific`, and `Asia/Calcutta`, `safeTimezone()` returns the alias when `new DateTimeZone($alias)` succeeds and the canonical IANA id when it throws. Never UTC for those four. Never throws. Mirrored on `KitScanPathsQuery` only.
3. This freeze tip commit message has no `[skip ci]`, so CI runs on this SHA.

`state.phase` stays `reviewed`. Assignee stays DaltonMcCleery. No Cloud ClickHouse writes. PR #439 stays open. Next: verify (scrub + 0-behind + freeze tip) → re-FREEZE ask. Soft OK vs SCHEMA-GATE. Sprint 41 Path A.

## Playwright FAIL — viewer zone and Company Metrics (2026-09-24)

Parent tip `229b984fa393b476879a20c2652de9ef23637aaa`. PHPUnit P1–P8 were already green. The browser run still 500'd the dashboard.

M/N. Viewer zones `US/Central`, `US/Eastern`, and `US/Pacific` threw `Carbon\Exceptions\InvalidTimeZoneException` in `DateTogglesFilter` while `Dashboard::filtersForm()` built the end picker's `maxDate(now(tz: auth()->user()->timezone))`. That is before `ScanPlateStatsBatchQuery::safeTimezone()` runs, so Company Metrics never rendered. `timezone()`, the end default `now(tz:)`, and `setTimezone` in `resolveStartDate` / `resolveEndDate` used the same raw `users.dti` value.

P. A garbage zone took the same filter path and 500'd the same way.

I. ClickHouse down with a cold cache: `CompanyMetricsOverview::getStats()` had no per-card try, so one `UniqueScanCounts` failure (connection refused, Code 210) 500'd the whole grid.

Fix. `DateTogglesFilter::viewerTimezone()` is `ScanPlateStatsBatchQuery::safeTimezone((string) (auth()->user()->timezone ?? 'UTC'))`. Every PHP, Carbon, and Filament timezone feed on the filter uses it. `safeTimezone()` itself is unchanged: a constructable fleet alias stays itself, an unconstructable one becomes the canonical IANA id, and garbage stays UTC. `KitRangePathsQuery` was not edited. `KitScanPathsQuery` was not edited.

`getStats()` still routes a withheld card to `lockedStat()` with no query. A thrown `toStat()` is `report()`ed and that card becomes a gray stat whose value is `--` and whose description is `Unavailable`. The loop continues. No new cache layer.

`okf/concepts/company-metrics-dashboard.md` still says `getStats()` has no per-card try. That sentence is now stale. Wiki left for the post-approval pass.

Tests, worktree `.env.testing` (`CLICKHOUSE_DRIVER=pgsql`, host `127.0.0.1`). No Cloud ClickHouse writes. The degrade test does not open ClickHouse; it throws from a stub card. The dashboard renders use the pgsql stand-in.

- `php artisan test --compact tests/Filament/Tables/Filters/DateTogglesFilterTest.php` — 10 passed, 40 assertions, 4.15s. Zones: `US/Central`, `US/Eastern`, `US/Pacific`, `Asia/Calcutta`, `Not/A_Real_Zone`. `viewerTimezone()` matches `safeTimezone()`, `new DateTimeZone` succeeds, the end picker timezone matches, `resolveStartDate` / `resolveEndDate` keep that zone, and `Livewire::test(Dashboard::class)` is OK.
- `php artisan test --compact tests/Filament/Widgets/Dashboard/CompanyMetricsOverviewTest.php` — 1 passed, 9 assertions, 2.17s. A Code 210 throw becomes `--` / `Unavailable` / gray, the sibling card still renders `48`, and the exception is reported.
- `php artisan test --compact tests/Unit/Clickhouse/ScanPlateStatsBatchQueryContractTest.php` — 11 passed, 42 assertions, 3.66s. `safeTimezone()` semantics unchanged.

`state.phase=implemented`. Product tip `9213a42a256a8cbd80af6bd72e47ef62fa7756ae`. Jira workflow was not transitioned. Assignee stays DaltonMcCleery. PR #439 stays undrafted. The product commit has no `[skip ci]`. Next is Review.

## Review rn=4 — Jeff Off GO tip (2026-09-24)

Product tip `9213a42a256a8cbd80af6bd72e47ef62fa7756ae`. Branch tip `e55353589432d5a1caee9ce1c0b091d28f915bf6` records that SHA.

Playwright FAIL classes M/N/P (viewer timezone 500 on DateTogglesFilter form build) and I (one Card throw 500s Company Metrics grid) addressed:

- `DateTogglesFilter::viewerTimezone()` → `ScanPlateStatsBatchQuery::safeTimezone`; timezone / now(tz:) / maxDate / resolveStart|EndDate use it. `safeTimezone` body unchanged. KitRangePathsQuery / KitScanPathsQuery untouched.
- `CompanyMetricsOverview::getStats()` try/catch around `toStat()` → `--` / Unavailable / gray; siblings continue; exception reported. No new cache.

Three lanes: correctness 92, conventions 88, blast-radius 91. MIN 88 ≥ 85. `rerun_required=false`. Act-on Implement: NONE.

OKF company-metrics-dashboard.md still says no per-card try (stale; deferred post-approval).

Next: Agent QA on tip (real fleet Docker CH for CH-touching paths). Soft OK vs SCHEMA-GATE. Jira left at QA Ready. PR stays undrafted.
