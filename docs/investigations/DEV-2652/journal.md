<!-- ticket-status -->
**Ticket:** [DEV-2652](https://insightlpr-team.atlassian.net/browse/DEV-2652)
**Phase:** reviewed · **Route:** feature · **Mode:** normal
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · ticket provision (qa-prep provision-only, ClickHouse deferred) · implement (AC1–AC4) · review (MIN 88, reviewed_sha `bd34326d4800297ff39297fcc671f9f651c7b2b5`)
**Next:** Pippin (Agent QA)
**Open questions:** none
**Resolved questions:** Q1 claims `ts` deferred. Source: Dalton via Galadriel. Reconciliation impact: historical claims figures stay bucketed on UTC and remain aligned with Brek's Repo Report; shipping a viewer-zone claims shift would move every historical figure off that reconciliation. Claims `ts` is out of ship scope. Q2 chart labels stay on the headline clock; the numerator zip is documented as two clocks.
**Ship:** AC1–AC4. Ratio numerators use `numeratorZone()` → `ScanPlateStatsBatchQuery::safeTimezone()`. Headline and denominator stay UTC / `SERIES_ZONE`.
**Deferred:** AC5 claims `ts`. `UniqueLprCaseClaimsQuery` and `LprRepossessionsQuery` were not edited.
<!-- /ticket-status -->

# DEV-2652 journal

## Phase 0 — Intake (2026-09-22T21:11:27Z)

Canonical re-GET of DEV-2652 (Story, To Do, Medium, assignee Dalton McCleery, app Clarity `customfield_10037`, parent DEV-2409, Sprint 41).

Relates: DEV-2651 (QA Complete — paired-card merge; use the shared concern, do not re-fix the three deleted pre-2651 widgets) and DEV-2654 (out of scope — Staging Live Hit timezone).

Jira comment `41117` (Dalton McCleery, 2026-09-22T21:07:22Z) records the Fellowship scope lock: ship AC1–AC4, defer claims `ts`, keep historical claims figures on UTC so they stay reconciled to Brek's Repo Report. No Eric sign-off is in the comments.

Base branch `main` at `2f446cca`. The post-DEV-2651 surface is on that commit: `RatioAgainstUniqueScans` exists; `UniqueScansToCaseClaims`, `UniqueScansToRepo`, and `UniqueScansToLprRepossession` do not.

Stop line for this run: `phase=planned`. Ticket provision (qa-prep) is reserved in `state.json` and is not run. No product code.

## Phase 1 — Ultra-Prep (2026-09-22T21:12:30Z)

Single pass against `main` @ `2f446cca`. Best-of-N not run. The only material product question was already written on the ticket.

Prep gate recommendation: **CLEAN**, with AC5 `decision=defer`.

What the code actually is, after DEV-2651:

- The three named pre-2651 widgets are gone. Numerators are `RatioAgainstUniqueScans::fetchUniqueScansSeries()` / `windowUniqueScans()`, called with `SERIES_ZONE = 'UTC'` from `UniqueLprCaseClaims`, `LprRepossessions`, and `RecoveryCounts`.
- `ScanPlateStatsBatchQuery::handle()` / `handleSql()` already apply `safeTimezone()` and `toUtcDatetime()`, group in that zone, and filter raw UTC `scanned_at`. Both shared cache keys already contain `:tz=`.
- `handleRangeSql()` does not sanitise. The caller must pass one zone and mint bounds in that same zone.
- Claims denominators stay on `toDate(fromUnixTimestamp(be.ts), 'UTC')` in both `UniqueLprCaseClaimsQuery` and `LprRepossessionsQuery` (`db_repos` is claims `ts`, not `repo_date`).
- `RecoveryTotalsBatchQuery::sharedMonthCacheKey()` has no `:tz=` segment. `repo_date` is a ClickHouse `Date`.
- Headline UTC tests (`test_month_buckets_align_on_utc_not_the_viewers_timezone` on both claims cards, plus the label-axis test) must stay green. They pin the deferred denominator.

PHP probe on this VM: `ALL_WITH_BC` has 500 ids, not the 598 the `safeTimezone()` docblock names. `CST`, `PST`, and `+05:00` construct a `DateTimeZone` and are unlisted, so `safeTimezone()` returns `UTC`. `US/Central` is unlisted and `DateTimeZone` throws. `safeTimezone()` uses `in_array` and does not throw. Callers have to sanitise before `now(tz:)`.

Do not edit `safeTimezone()`. The docblock drift is noted, not fixed.

## Phase 2 — Question Gate (2026-09-22T21:13:30Z)

Recomputed **CLEAN**. No interview. `needs_human_interview` stays false.

The gate law says: no Eric sign-off in the comments, so prefer numerators-first, set AC5 `decision=defer`, and record the Brek Repo Report impact. That is a closed decision, not an invented Eric answer. Comment `41117` is Dalton stating the same deferral.

Claims do not have to ship this run. The gate is not BLOCKED. Nothing is queued for Gandalf.

| id | status | resolution |
| --- | --- | --- |
| Q1 | resolved | Defer claims `ts`. `UniqueLprCaseClaimsQuery` and `LprRepossessionsQuery` keep UTC `be.ts`. Impact recorded in `prep-context.json` `ac5.reconciliation_impact` and in the status block above. |
| Q2 | resolved | Labels follow the headline clock. Document the mixed quotient. Do not fake a `repo_date` shift. |

`prep-context.json` `gate` remains `CLEAN`.

## Phase 3 — Ultra-Plan (2026-09-22T21:14:30Z)

DAG in `plan.json`: `t1` `numeratorZone()` on the trait, then `t2` / `t3` / `t4` in parallel (the three cards), then `t5` docblock pointers. Sizing: 10 files, one ticket, no split.

AC5 stays deferred. Claims SQL, `repo_date`, and `RecoveryTotalsBatchQuery::sharedMonthCacheKey()` are `excluded_sites`.

Perf gate not required: same `scans_cold` shape `UniqueScanCounts` already runs per viewer zone.

Stop. Next is ticket provision / Aragorn CLI. qa-prep is reserved in `state.json` with `ledger: null`.

## Scope lock — Dalton via Galadriel (2026-09-22T21:15:00Z)

Authoritative restatement. Gate stays **CLEAN**. AC5 `decision=defer`. Ship AC1–AC4 only.

Reconciliation impact, recorded here, in `prep-context.json` `ac5.reconciliation_impact`, and in the living status above: historical claims figures stay on UTC so they remain aligned with Brek's Repo Report. A viewer-zone claims shift would move every historical figure off that reconciliation.

`UniqueLprCaseClaimsQuery.php` and `LprRepossessionsQuery.php` are not plan `target_files`. Claims timezone is not implemented in this key. The gate is not BLOCKED.

## Phase 3.5 — Provision (2026-09-22T21:21:56Z)

Provisioned via qa-prep provision-only. ClickHouse deferred. AC5 still deferred.

Isolated Postgres `zod_cursor_dev_2652_eeaf` and `zod_testing_cursor_dev_2652_eeaf` are migrated. Herd site `https://clarity-cursor_dev_2652_eeaf.test`. Ledger is on disk at `.context/qa-prep-ledger.json` and is not committed. Next: Frodo.

## Phase 4 — Implement (2026-09-22T21:34:53Z)

AC1–AC4 shipped. `numeratorZone()` delegates to `ScanPlateStatsBatchQuery::safeTimezone()`. The three ratio numerators mint bounds in that zone. Headlines, labels, deduped claims, `db_repos`, and `repo_date` stay on UTC / `SERIES_ZONE`. AC5 claims `ts` was not edited.

PHPUnit: 105 passed, 0 failed, 385 assertions (`RatioAgainstUniqueScansTest`, `UniqueLprCaseClaimsTest`, `UniqueLprCaseClaimsLabelAxisTest`, `LprRepossessionsTest`, `RecoveryCountsTest`).

Deviation: this PHP lists `US/Central` in `ALL_WITH_BC` (598 ids), so `safeTimezone()` keeps it. CST, PST, and `+05:00` still become UTC. The helper was not re-derived.

Next: Sam (Review).

## Phase 5 — Review (2026-09-22T21:50:00Z)

Sam, in-session, 3 lanes (shared `RatioAgainstUniqueScans` plus the three Company Metrics cards and timezone/cache behavior). Scores correctness 89, blast-radius 88, tests 88. MIN 88 ≥ 85. `rerun_required` false. Act-on for Frodo: NONE.

Reviewed SHA `bd34326d4800297ff39297fcc671f9f651c7b2b5`. Residuals, none of them a fix cycle: positional zip of viewer-local scan months against UTC headline months near a zone boundary (accepted, DEV-2651 follow-on); `US/Central` allowlist membership is PHP-build-dependent (prep 500 ids vs this build 598); no live ClickHouse boundary soak (Pippin). AC5 claims `ts` stays out of the diff. `safeTimezone()` was not re-derived.

`review-gate.sh` is not on this image (`insight-lpr/claude-plugins` 404). Gate binary is harness-local. Lane files and `.ai/review-manifest.json` stay uncommitted.

Next: Pippin (Agent QA).

## Phase 7 — Verify / Build Record (2026-09-23T15:00:29Z)

Merry closeout. Live QA round 4 PASS artifacts committed under `docs/investigations/DEV-2652/` (report, ac-results, round4 evidence). Build Record mirrored from Jira comment **41264**. `record.ac` folded AC1–5; `record.review.scores` arrayized to `[89, 88, 88]`. App QA containers torn down; ClickHouse deferred to Galadriel QA Ready. Phase → `done`. PR stays draft. Next: Galadriel (undraft → CI → QA Ready).
