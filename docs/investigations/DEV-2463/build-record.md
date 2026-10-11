Insight AI — Build Record

<!-- build-record: DEV-2463 -->

**TL;DR**

Company Metrics had no way to see unique repo case claims — the funnel stage Brek tracks in the Repo Report — so this adds a "Unique LPR Case Claims" card to the `/app` dashboard grid, third slot, for Pro orgs with the Company Metrics feature. It counts distinct vehicles claimed in the selected period, deduplicating on the forwarder org, agent org and VIN together, and reproduces Brek's July 2026 figure of **11,975** exactly. A new single-pass ClickHouse query serves both the headline number and a 13-month sparkline in one scan, and the nine existing cards are untouched — the change is an insertion, not a rearrangement.

Verified by reproducing all six of Brek's target figures against production from the shipped class itself (not a retyped query), a full 1,807-test suite run with zero regressions, agent QA at 11/11, manual QA, and three review rounds ending at 92/92/92. Brek has since spot-checked the per-org numbers and confirmed they are close enough to what he tracks — a spot check, not a digit-exact reconciliation. Six findings are knowingly open and documented at their code sites, the two that matter most being that this card's ClickHouse failure returns a 500 for the whole ten-card grid — pre-existing, and shared in production with `RecoveryCounts` and `MissedOpportunities` — and that the Today / Last 7d filters use the viewer's local day while the month filters use UTC, so "Today" can briefly exceed "This Month". Concurrency — many orgs missing cache at once — remains unmeasured.

---

## Route and branch

- **Route:** feature · **Base branch:** `main` · **Branch:** `claude/DEV-2463`
- **PR:** https://github.com/insight-lpr/platform/pull/392
- **Artifacts:** `docs/investigations/DEV-2463/` (QA evidence and the pre-teardown ledger snapshot under `qa/`)

## What changed, and why each change was needed

**t1 — `app/Clickhouse/Queries/UniqueLprCaseClaimsQuery.php` (new).** The data source. Ports Backyard's `RepoReport` claims funnel to Clarity and exposes two entry points: `window()` for the Today / Last 7d scalars and `monthlySeries()` for the sparkline.

- *Why a bespoke class rather than extending `ClickhouseQuery`:* that base class's `query()` swallows `Throwable` and returns `[]` in both production and testing. A genuine zero is a normal reading for this card — measured, 14% of entitled orgs read zero on a month window — so a swallowed failure would render as an indistinguishable `0`. The card must fail loudly instead of lying.
- *Why one pass instead of three CTE references:* measured at production scale, three references cost **119,848,324** `read_rows` and 5.50 GB against **39,949,441** and 1.83 GB for the single-pass `count()` / `countIf()` / `uniqExactIf()` form. Exactly 3.0×, because `zod.public_billing_events` is `ORDER BY id` and nothing prunes, so every CTE reference re-scans the table.
- *Why `FINAL` on `orgs` and `api_keys` but not `billing_events`:* these are PeerDB `ReplacingMergeTree` mirrors. `orgs` is a join source — without `FINAL` a renamed org contributes two rows and fans its claims out ×2, corrupting the counts. `billing_events` is the 38.0M-row scan target and its rows are immutable once written, so `FINAL` there is pure cost.
- *Why explicit UTC everywhere:* the target figure is a UTC calendar month. An early attempt at hand-computed epoch bounds was silently two days off and produced 14,313 / 13,492; bounds are now derived from Carbon's UTC timestamp.

**t2 — `app/Filament/Widgets/Dashboard/UniqueLprCaseClaims.php` (new).** The card. Extends `DashboardWidget` — the operator's decision, overriding a recommendation to bypass the base class — with `getMonthlyData()` and `getModelQueryForDates()` overridden so each issues exactly **one** ClickHouse round trip instead of the base class's up-to-13-query fan-out. `getGraphLabels()`, `getHighlightedMonthsFromFilter()` and `getDescription()` are overridden to UTC so the label axis matches the data axis.

**t2 — registration.** `CompanyMetricsOverview` gains the card at `cards()` index 2 (mockup row 1, col 3) and `DashboardWidget` gains one colour arm. The `CompanyMetricsOverview` docblock counts were corrected (7→6 gaps, 9→10 cards) because that docblock declares itself the source of truth for the next sibling's slot arithmetic.

**t0 — shared infrastructure carry.** `database/schema/clickhouse-schema.sql` and `tests/Concerns/RequiresClickhouse.php` take DEV-2464's exact hunks from commit `47612ab1` (adds the `zod.public_billing_events` table and four `zod.public_*` truncations), byte-identical and lane-verified. Without them no test can see the table this card reads.

## One decided constraint was superseded — read this if the ticket confuses you

The ticket description lists **Route: Postgres `zod`** in its "Decided already — do NOT re-litigate" table, reasoning that porting Backyard's SQL against the transactional database avoids mirror-semantics bugs and "sidesteps the missing `zod.public_billing_events` ClickHouse schema entry."

**This card ships on ClickHouse instead, and adds that schema entry.** That is a deliberate supersession, not an oversight: **comment 39116 recommends ClickHouse** and post-dates the description. All three independent prep passes converged on it, and sibling DEV-2464 — held specifically so this card ships first — is built the same way and hands over the shared-infra fixes that make the table visible to tests.

Why it is the better route here, on the evidence gathered since: this card reads only `zod.public_billing_events`, `zod.public_api_keys` and `zod.public_orgs`, never the 136M-row `hotlist` or the 389M-row `hotlist_status_log`, so the expensive joins the Postgres route was protecting against are not in play. The ticket also recorded production Postgres cost as an **"accepted unmeasured risk"** because no live `EXPLAIN` was reachable — whereas the ClickHouse route is now measured end to end (39.9M `read_rows`, 1.83 GB, ~240 MB peak, 0.3–9.3 s, and per-org cost proven identical to platform-wide across 22 interleaved runs). The route that could be measured was preferred over the route that could not.

The one thing the description got right that this route had to honour anyway: the mirror-semantics risk is real, which is why `FINAL` is applied to the two join sources and deliberately not to the scan target, and why the tombstone limitation is recorded as open finding 5.

## Resolved questions

| Question | Answer | Source |
| --- | --- | --- |
| Which funnel stage should the card show? | **`deduped`** — 11,975 | human:Dalton McCleery 2026-09-08 |
| Extend `DashboardWidget` or bypass it? | **Extend**, with `getMonthlyData()` overridden — overrode the driver's bypass recommendation | human:Dalton McCleery 2026-09-08 |
| What should a zero-claim org see? | **Show `0`, no special treatment** — decided after measuring the real denominator: **14%** of *entitled* orgs read zero on a month window (4 of 29), not the ticket's stated 83% | human:Dalton McCleery 2026-09-08 |
| Carry DEV-2464's shared-infra fixes? | **Yes**, the exact diff | human:Dalton McCleery 2026-09-08 |
| Include `ls-claim` events? | **No** — out of scope | code |
| Does the titlemax disclosure apply? | **N/A to this card** | code |
| New Pennant flag or reuse? | **Reuse `COMPANY_METRICS`** — comment 39129 is stale | code |
| Eloquent or raw SQL? | **Moot**, but do *not* extend `ClickhouseQuery` (see above) | code |

## Test results

| Command | Result |
| --- | --- |
| `php artisan test --filter=UniqueLprCaseClaimsQueryTest` | **12 passed**, 0 failed, 23 assertions |
| `PHPRC=<memory_limit=3G> php vendor/bin/phpunit --no-coverage` (full suite) | **1,790 passed**, 11 failed, 7,328 assertions, 4 skipped |
| CI `Pint & Tests` (8.4, shards 1–3) | **all three pass** on `5064d230` (6m21s / 6m3s / 4m27s) |
| `vendor/bin/pint --dirty` | passed |

CI shard 1 initially **failed** on `1e8f89ec` with a class-not-found in one of the new tests; that is Deviation 3 below, and all three shards pass on `5064d230`.

**The 11 local full-suite failures are pre-existing, not regressions**, and proving that took care: the same suites are **non-deterministic on `main` itself** when the local ClickHouse container is under memory pressure — `HasStatDrillDownTest` gave 4, 4 and 7 failures on the *same* commit at 73.9% container memory. After `docker restart` (down to 36.1%), `main` and this branch read **identically**. Verification was therefore two-step: run in isolation, then re-run on a detached `origin/main` after a container restart.

39 tests were added across five files. Every one was mutation-tested; **nine mutants were killed**, and four of those were guards already present in the code that no existing test noticed the removal of.

**Test integrity note:** `.env.testing` shipped with `CLICKHOUSE_DRIVER=pgsql`, which silently **skips** every ClickHouse test while reporting green. It was flipped to `clickhouse` and the 12 query tests were re-verified as real passes by their per-test durations — otherwise this record would be citing tests that never ran.

## Deviations

1. **`.env.testing` driver flip** (above) — required for any of this card's tests to actually execute.
2. **QA-loop environment fix.** A bootstrap re-keyed `.env` after agent QA had passed, pointing Postgres at a thin 4-org database and ClickHouse at a database that does not exist. Restored to the QA-validated configuration; environment only, no application code.
3. **CI test fix (test-only, one file).** CI shard 1/3 failed with `Class Tests\Filament\Widgets\Dashboard\StubbingUniqueLprCaseClaims not found`. `UniqueLprCaseClaimsLabelAxisTest` borrowed a stub declared in `UniqueLprCaseClaimsTest`, which resolves locally (one process loads every file) but dies on whichever CI shard lacks that sibling. It now declares its own `SeriesStubbingLabelAxisWidget`, named distinctly so both files can still coexist in one process. A repo-wide sweep found no other instance. **Total application-code drift since the 92/92/92 review: zero** — the only change is this one test file.
4. **Boost-doc regeneration reverted twice.** `npm run build` re-injects the `<laravel-boost-guidelines>` block into `CLAUDE.md`/`AGENTS.md`/`.junie`, which `php scripts/okf-lint.php` fails on. Reverted; lint passes.

## Review

Three independent adversarial lanes, three rounds. **94 / 88 / 94 → 92 / 92 / 92**; the gate takes the minimum, so 88 failed and 92 passes. `rework-gate.sh` passes.

**Fixed during review** (each confirmed before fixing): the UTC data axis versus viewer-timezone label axis, which for ~5–6 hours at every UTC month start labelled all 13 bars one month off their data; a cache key with no window identity, which at a month rollover produced a fabricated zero indistinguishable from a real one — the exact collision AC-2 forbids; a mutation-proven test gap where removing **both** the dedup and the success filter passed all 12 tests; a cold-path herd lock, since `Cache::flexible`'s total-miss branch is unguarded and a `cache:clear` admitted one 40M-row scan per entitled org per tab; and a 60-second TTL floor for the `today`/`last_7d` seam, which had been outside the caching bound the envelope claimed.

**Round 2's own fixes were withdrawn, not patched.** All three lanes found them net-harmful: a failure marker checked *before* the cache read turned one blip into 60 seconds of dashboard-wide 500s after recovery, and a 5-second lock would never have bound against a measured 9.3-second p99.

**Rejected as false:** a lane claimed the card newly breaks an untouched test under `CLICKHOUSE_DRIVER=pgsql`. Driver-verified — it fails identically on `origin/main` at the same `ScanCount.php:51`. Not a regression.

**Open and accepted**, each documented at its code site with the measurement behind it:

1. **One card's ClickHouse failure 500s the whole ten-card grid.** `CompanyMetricsOverview::getStats()` has no per-card try/catch, and this card propagates by design (swallowing would render an indistinguishable `0`). Pre-existing: `RecoveryCounts` and `MissedOpportunities` propagate identically in production, both being Eloquent on `ClickhouseModel` with no catch anywhere. **Corrected after posting —** I originally wrote that the four hit cards propagate identically too, and that the QA grid 500 confirmed production behaviour. Both were wrong. `ClickhouseQuery::query()` and `SharedHitStatsData` are gated on `in_array(config('app.env'), ['production','testing'])`: they degrade **inside** that gate and rethrow **outside** it. So in production the propagating set is **three of ten** cards, and the hit-card family already degrades via `report()` plus a 60-second marker — it is the one place the pattern is done properly. A house-wide fix is queued, deliberately not attempted here after the round-2 attempt regressed.
2. **Mixed calendars.** `today`/`last_7d` are viewer-local days; `this_month`/`last_month` are UTC months, so "Today" can exceed "This Month". Both alternatives were measured and are worse.
3. **Impersonation freshness.** `Cache::flexible([3600, 86400])` bypasses `DashboardWidget::getCacheTTL()`'s impersonation collapse, which okf PG-009 documents at 3s/10s. Siblings bypass it too, but at 5–15 min rather than 1–24 h. A claim in my own docblock that impersonators still get the freshest sibling value within a minute was **false** — a lane proved most siblings collapse to 10s, 6× fresher — and was rewritten rather than the code changed.
4. **Cold-path herd on the three pre-existing `cache()->flexible` sites** (`Org::getHotlistClients` is worse, at 156M rows).
5. **Tombstone limitation.** A soft-deleted internal org would drop out of the `orgs` CTE under `FINAL`, the `LEFT JOIN` would yield `NULL`, and its claims would silently re-enter the count. Unreachable today: the needle matches 4 orgs, all live. Settling this required resolving a contradiction between two docblocks in this repo that neither lane could resolve read-only — **`FINAL` does drop tombstones** (3 tombstoned orgs exist in production; 0 visible under `FINAL`), so `HitPlateStatsBatchQuery`'s docblock is right and my own round-2 comment was wrong.
6. **Fail-open platform-wide default.** `?? 0` means "no tenant" reads as "all tenants". Currently unreachable — `buildWidgetCacheKey(string, int $orgId)` type-errors on a null org first — but that safety lives in a different class's type hint, not this one. The guard was then found to be **deletable with the whole suite green**, so it now sits behind a `flexibleRefreshLock()` seam a test actually pins.

## Acceptance criteria

| AC | Status | Evidence |
| --- | --- | --- |
| AC-1 — reproduces Brek's funnel stage (15,128 / 14,288 / 11,975 for Jul 2026) | **SATISFIED** | All six target figures reproduced exactly against production, from SQL rendered out of the shipped class by reflection. Agent QA B2 returned 7 / 6 / 5 against the seeded local world. |
| AC-2 — exactly one cached ClickHouse query per resolved window | **N/A** | Not verifiable from a browser — agent QA cannot count cache-guarded executions from the DOM. Pinned instead by `UniqueLprCaseClaimsHerdGuardTest` (6 tests, including the torn two-key cache state) and `UniqueLprCaseClaimsWindowSeamTest`. |
| AC-3 — respects Today / Last 7d / This Month / Last Month | **SATISFIED** | All four render a number with no error: 0 / 0 / 5 / 0. Expanded detail reads `Sep '26: 5 \| Aug '26: 0` with the base class's `Current Month:` literal absent. |
| AC-4 — non-Pro orgs do not see the value | **SATISFIED** | Entitled path verified end-to-end in a browser. The locked path was **not** browser-verified — this database has exactly one entitled org — and is covered by six gating-matrix unit tests plus `test_locked_stat_renders_the_placeholder_and_executes_no_query`. |
| AC-5 — sparkline consistent with existing card styling | **SATISFIED** | Renders `fi-color-success`, stroke `oklch(0.792 0.209 151.711)` — green, not the locked grey. This replaced `'purple'`, an unregistered Filament colour that would have rendered untinted with no error at all. Visual family judgment signed off in manual QA. |
| AC-6 — existing metrics unaffected | **SATISFIED** | `cards()` returns 10 with the new card at index 2; DOM order confirms insertion only. No `Server Error`/`ErrorException` text, empty console, all requests 200/204. Zero regressions across four full-suite runs. |
| AC-7 — the five open questions answered in the Build Record | **SATISFIED** | This comment — see Resolved questions above. |

## QA performed

**Mode and environment.** Isolated worktree; Postgres branch database `zod_claude_dev_2463_419347` (336 orgs, 862 scans, 68 kits); **real local ClickHouse in Docker**, not the pgsql bridge, using the literal `clarity`/`zod` databases on port 18123 — required, because `RequiresClickhouse` truncates fully-qualified literals and roughly ten query classes hardcode the prefix. Herd site `clarity-claude_dev_2463_419347.test`; dev login on; queue workers not running (nothing here needs one).

**Provisioning.** Bootstrap skipped (already provisioned), migrations already applied, Docker already running, no frontend build needed. A **production ClickHouse Cloud host was found wired into `.env`** during prep and neutralized to `127.0.0.1`; the PostGIS `tiger.state` patch was applied and the baseline reseeded (scans 0 → 862).

**Agent QA: 11/11 items, 0 red, 0 failed** — two browser lanes under `agent-browser` plus a backend lane. The card renders **5** at index 2 with a green sparkline; all four date filters correct; `cards()` = 10; `php artisan about` and `route:list` both exit 0; `monthlySeries()` returns 1 bucket and the widget zero-fills the other 12. Three screenshots captured. The full agent-QA report, machine-readable AC results, resource envelope, screenshots and both QA plans were committed under `docs/investigations/DEV-2463/qa/` while this Build Record was still pending sign-off, and have since been **removed from the branch** — this comment is the durable record, so carrying ~400 KB of screenshots and duplicate plans in the application repo bought nothing and made the PR unreviewable. The one artifact kept is `docs/investigations/DEV-2463/qa/ch-seed.sql`, the ClickHouse seed, because it reproduces the QA world.

**One error-level log line appeared, and it was not this card.** `HitStatsBatchQuery` — owned by `SharedHitStatsData`, the four hit cards — threw ClickHouse error 241, `(total) memory limit exceeded … current RSS: 5.21 GiB`, and the whole ten-card grid returned a 500. Verified four ways rather than taken on trust: the trace names only that file, `UniqueLprCaseClaimsQuery` appears **zero** times in the log, neither file is in this diff, and a reload immediately re-rendered 5. Local container memory pressure. **Corrected after posting:** I first called this live confirmation of open finding 1's production blast radius. It is not — QA ran under `APP_ENV=local`, where `SharedHitStatsData` takes its dev-only `throw $e` branch; in production that card family would have reported the error, set a 60-second marker, and degraded without a 500. It is a faithful demonstration of the *local* failure mode only. See open finding 1.

**Human QA** ran against the rendered guide (10 steps, one-click persona login, three screenshots, agent findings folded in) and completed with no defects raised.

**What changed because of QA:** the environment fix in Deviations 2 — no application code changed as a result of QA.

**ClickHouse seed** (persisted at `docs/investigations/DEV-2463/qa/ch-seed.sql`) inserted 9 synthetic `case-claim` billing events with matching api_keys and orgs. Read-only verification, run through the booted application:

```
window(null, 2026-09-01..2026-10-01) -> {all_claims: 7, successful: 6, deduped: 5}
window(2,    2026-09-01..2026-10-01) -> {all_claims: 7, successful: 6, deduped: 5}
monthlySeries(2, ...)                -> 1 bucket: 2026-09-01 => 7/6/5
CompanyMetricsOverview::cards()      -> 10 entries, unique-lpr-case-claims at index 2
GET /app                             -> 302 (correct redirect for an unauthenticated request)
```

**Teardown.** Four Postgres databases dropped (the ledger recorded only two — a later bootstrap had created an unsuffixed pair a ledger-driven teardown would have orphaned), both Herd sites unlinked, `.context/` destroyed along with the read-only ClickHouse Cloud credential it held. **ClickHouse was deliberately left standing:** `down.sh` drops `$CH_DB_CLARITY`/`$CH_DB_ZOD`, which for this worktree resolve to the literal `clarity` and `zod` — shared with local dev and every other platform worktree — so the sanctioned path would have issued `DROP DATABASE clarity` and destroyed other tickets' data.

## How the numbers were derived

All production figures are read-only, aggregates and server-side counters only; no raw rows are in the repo or this comment. Server-side resource figures come from the **`X-ClickHouse-Summary` response header**, which needs no `READ ON REMOTE` grant — DEV-2464 had recorded this envelope as unobtainable.

| Figure | How |
| --- | --- |
| 15,128 / 14,288 / 11,975 (Jul 2026 platform-wide) | SQL rendered **out of the shipped class by reflection** and run against production. All six target figures — including 9,340 / 9,262 / 7,230 for the largest forwarder — matched exactly. Re-running prep's hand-written SQL would have proved only the design. |
| The 32-row internal-org delta | Without the `insightlpr` exclusion, July reads 15,160; with it, 15,128. `successful` and `deduped` are unchanged, confirming those 32 claims are all failures. |
| 39.9M `read_rows`, 1.83 GB, 392–489 MB peak, 0.3–9.3 s | `X-ClickHouse-Summary`, two sessions. An earlier "no fat tail" claim from a quiet-window 0.31 s median was **wrong and is corrected**: a contended session gave a 3.6 s median and a 9.3 s max on the same query, same org, same window, with `read_rows` identical to the row. That 9.3 s is why `COLD_BLOCK_SECONDS` is 12 and not the 5 a round-2 fix proposed. |
| The 13-month sparkline span is free | `read_rows` byte-identical at 1, 3 **and** 13 months, because `ORDER BY id` means nothing prunes. DEV-2464 chose 3 months; here that buys nothing, so this card uses 13 and matches its eight neighbours. |
| 29 entitled orgs (and the 14% zero-read rate) | Two independent evaluators over all 4,118 orgs carrying an entitlement key: the **shipped** `Org::isProEnabled(COMPANY_METRICS)`, run against production `org_meta` hydrated in memory with exact JSON type fidelity, versus the SQL predicate. Both return 29, and an id-level diff shows **0 over-count and 0 under-count**. This closed a real gap — the figure had previously rested on a hand-reimplemented predicate verified only by reading the code. |
| Per-org cost equals platform-wide cost | 11 orgs × 2 **interleaved** rounds: `read_rows` was **40,046,830 on all 22 runs, min == max, 0.00% spread**, including for orgs whose answer is 0. Fleet cold sweep is therefore arithmetic: 29 × 40.05M ≈ **1.16B rows (~53 GB)**, ~240 MB peak ClickHouse memory per query. |
| Example org figures Brek spot-checked | Relentless Recovery (org 1143) 429 / 419 / **383**; Reliable Recovery Services Inc. (IL) (org 469) 640 / 606 / **555**, plus 13-month trends — posted for review and confirmed "close enough to what he's tracking". Neither org is currently entitled to see the card; both resolve to `basic`. |

**Still unmeasured: concurrency.** Every timing is one query at a time. How N orgs cache-missing simultaneously behaves is unknown — the same open item DEV-2464 carried — and measuring it means a deliberate load test against production ClickHouse Cloud.

## Post-merge queue

An `okf` page for the Company Metrics card family and one for `billing_events` — no page exists for either, which all three review lanes confirmed — plus the impersonation-TTL divergence note. Held deliberately: `CLAUDE.md` forbids writing to the wiki while work is in flight.
