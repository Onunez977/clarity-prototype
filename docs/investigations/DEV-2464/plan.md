# DEV-2464 — implementation plan (Phase 3)

Inputs: `prep-context.json` (gate CLEAN, all 14 questions answered), `rescope-after-main-merge.md`,
`q8-recoveries-leg.md`, `q13-caching.md`, `q10-q11-oscar.md`, `perf-measurement.md`,
`okf-findings.md`, `driver-verification.md`, the three `measure-*.sql` files, plus
`okf/concepts/company-metrics-dashboard.md` and `okf/concepts/billing-events.md`.

Nothing here re-litigates a settled decision. The recoveries leg is **A1**; the card keeps
extending `DashboardWidget` and replaces its three data methods; `Cache::flexible([3600, 86400])`
stays with all four q13 parts; the cache machinery is **extracted** rather than copied; failures
**propagate**; no titlemax disclosure and no zero-state branch.

## SIZING GATE: over the threshold — report only

**10 distinct files, 4 distinct concerns.** The gate is 6 files / one concern, so this exceeds it
and the driver should take the split question to the operator. The concerns:

1. a new production-scale ClickHouse query (`t2`),
2. a refactor of already-shipped, QA-passed code (`t1` — `UniqueLprCaseClaims`),
3. the card's own data/gate/label-axis behaviour (`t3`, `t4`),
4. live production measurement and constant calibration (`t5`), plus an e2e spec (`t6`).

The re-scope genuinely did shrink the *card* work to "replace three methods". What did **not**
shrink is everything around it: the query class is new, the shared cache concern is a second file,
the Pro gate flips a shared cross-card test, and the herd-guard constants cannot be inherited.

Recommended split boundary if the operator wants one: **`t1`–`t4` in this ticket** (the shippable
unit — code plus everything CI can pin), with **`t5`** as an operator-run verification gate before
merge and **`t6`** deferred (e2e is Playwright against a live environment; CI never runs it, so it
proves nothing on the PR). A more aggressive split would land `t1` alone first as a no-behaviour-
change refactor PR, which is also the cleanest way to isolate the one node that touches shipped code.

## Ordered subtasks

| id | title | complexity | depends on | test filter |
| --- | --- | --- | --- | --- |
| t1 | Extract the held-flexible cache machinery to a shared concern | complex | — | `UniqueLprCaseClaims` |
| t2 | Add `LprRepossessionsQuery`: single-pass A1 funnel with the DB-repos leg | complex | — | `LprRepossessionsQueryTest` |
| t3 | Replace the stub's data methods, Pro gate and UTC label axis | complex | t1, t2 | `LprRepossessionsTest` |
| t4 | Add `lpr-repossessions` to the gated allowlist | simple | t3 | `DashboardCardVisibilityTest` |
| t5 | Validate against production, calibrate the guard constants | complex | t2, t3 | `LprRepossessionsTest` |
| t6 | Add the Playwright spec | simple | t3 | — |

`t1` and `t2` are parallel (disjoint files). `t4` and `t6` are parallel after `t3`. `t5` serialises
last because it edits `t2`'s and `t3`'s files.

### t1 — extract the cache concern

`heldFlexible()`, `flexibleCreatedKey()` and `MIN_TTL_SECONDS` are private to
`UniqueLprCaseClaims`; `flexibleRefreshLock()` is protected. DEV-2464 needs all of it and DEV-2463's
own docblock names DEV-2465 as a third user, so dc2's operator-approved call is extraction, not a
second copy — the Redis-cluster two-key subtlety is precisely what rots in a duplicate.

New `app/Filament/Widgets/Dashboard/Concerns/HeldFlexibleCache.php` carries the mechanism; the
**constants stay per-card** because they are calibrated per query, reached through abstract
protected accessors so a card that forgets one fails at compile time. `MIN_TTL_SECONDS` and the
`getCacheTTL()` floor stay on the card — that is policy, not machinery.

**This is the risk node.** It edits code that shipped and passed QA four days ago. It therefore gets
its own subtask and its own test run, and the proof that it is a pure refactor is that
`UniqueLprCaseClaimsTest`, `UniqueLprCaseClaimsHerdGuardTest` and `UniqueLprCaseClaimsQueryTest`
all pass **with no test edits**. `UniqueLprCaseClaimsHerdGuardTest:101-105` reads the constants off
the card by reflection, which is exactly why they must not move.

### t2 — the query class

`app/Clickhouse/Queries/LprRepossessionsQuery.php`, modelled on `UniqueLprCaseClaimsQuery`: same
static `window()` / `monthlySeries()` API, same `zodDb()` prefix, and deliberately **not** a
`ClickhouseQuery` subclass, because that base swallows `Throwable` to `[]` in production and testing
and a genuine zero is a normal reading here.

SQL body is the production-validated `measure-org-monthly.sql` with five corrections applied:

- **the `insightlpr` exclusion**, on both sides, through `orgs FINAL` — never a `titlemax` needle
  (collapsing the two is a measured −2.2% to −2.8% error);
- **`uniqExact`/`uniqExactIf` over the `(forwarder_org_id, agent_org_id, vin)` triple**, never
  `uniq` (HyperLogLog, and this figure is reconciled against Brek's report);
- **a single reference to `deduped`**, i.e. one pass over `zod.public_billing_events`. The measured
  SQL references it three times and okf measures each extra reference at exactly 3.0× the
  `read_rows`. `floor_date` becomes a PHP-computed `{floor}` token; `pairs` disappears into the
  `LEFT JOIN`;
- **explicit `'UTC'` in every date function** — neither ClickHouse connection block carries a
  timezone key and the Postgres side never sets the session GUC;
- **per-table CDC discipline, asymmetric on purpose**: `FINAL` + `_peerdb_is_deleted = 0` on
  `public_hotlist` / `public_api_keys` / `public_orgs`; `_peerdb_is_deleted = 0` **without** `FINAL`
  on `public_hotlist_status_log` (raw == FINAL exactly, and `FINAL` costs 10× for nothing) and on
  `public_billing_events` — whose engine is `ReplacingMergeTree(_peerdb_version)`, **one argument**,
  so `FINAL` does **not** drop its tombstones and the predicate is mandatory and not interchangeable.

The restructure rests on two equivalences that must be stated in the docblock, because both are the
kind of thing a later reader "corrects" back:

- `max(created_at) >= X` is true iff **any** transition row satisfies it, so `uniqExactIf` over the
  join fan-out equals backyard's grouped `max()`;
- `{floor}` = UTC window start − 7 days is `<= min(claim_date) − 7`, i.e. strictly looser, so it
  cannot change the answer — only peak memory. (Backyard's floor is worth keeping in some form: it
  reads the same rows but cut peak memory 33%, 3.72 GB → 2.50 GB.)

Two guards carried over from the CTEs being removed: `agent_org_id != 0` (an unresolved agent must
count in `deduped` but never in `db_repos`), and `h.vin` left **un-normalised** while the claims-side
VIN is `upper(trimBoth(...))` — that asymmetry is deliberate in backyard's shipped SQL and "fixing"
it over-matches against real production numbers.

### t3 — the card

`getMonthlyData()` becomes one `heldFlexible` call around a 13-month UTC series (the base fans out to
13 cached queries — the multiplication the ticket forbids), returning **13 elements** because
`getData()` zips positionally against `getGraphLabels()`. `getModelQueryForDates()` becomes a single
`window()` call; the stub's `currentPeriodTotal()` override is **deleted** so the base's one-key
cache path applies, matching the sibling.

The cache key must carry the window's identity (`'monthly-series:'.$through->format('Y-m')`) or a UTC
month rollover serves a map whose newest bucket is absent and `?? 0` fabricates a zero — on this card
indistinguishable from a legitimate reading.

Also: `canViewCardData()` on the **existing** `FeatureEnum::COMPANY_METRICS` (no new enum case), the
`getCacheTTL()` floor (the base collapses to 10 s under `admin_impersonator`, *shorter* than this
query's contended p99 — a stampede, not staleness), and the three UTC label-axis overrides
`getGraphLabels()` / `getHighlightedMonthsFromFilter()` / `getDescription()` (dc3 and okf checklist
item 6 — mismatching the data and label axes is a named defect class). Query failures propagate.

### t4 — the shared visibility test

`DashboardCardVisibilityTest` keeps a deliberate allowlist of gated keys and `lpr-repossessions` is
not in it, so the moment `canViewCardData()` lands the loop fails with *"lpr-repossessions is gated,
but only [...] should be"*. One-line addition plus a comment update.

### t5 — production validation and calibration

Two things CI structurally cannot pin.

**Correctness at scale.** `t2` is a real rewrite of the shape `perf-measurement.md` validated, so it
must re-reproduce Jul 2026 `deduped` 11,975 / `db_repos` ≈9,135 platform-wide and 7,230 / ≈5,369 for
org 165. The +18 over Brek's 9,116–9,117 is live-`hotlist` drift at ~1.3–1.6/day in one direction,
reconciled across three readings — not a dedup artefact, and not something to re-open.

**The guard constants.** DEV-2463's 12/90/60 were tuned against a ~40M-row query with a 9.3 s
contended p99; A1 measures 664,270,998 `read_rows` / 15.04 GB / 2.50 GB peak — ~16×. Under-sizing
`COLD_BLOCK_SECONDS` is the documented failure mode where every waiter times out and computes anyway,
turning N tabs into N × 2.5 GB. Measure this card's own p99 **interleaved** — a sequential batch
already produced one badly misleading result on this ticket — read the envelope from the
`X-ClickHouse-Summary` response header (no `READ ON REMOTE` grant needed), and treat wall clock as
the least informative number available. It misled this ticket three times.

### t6 — Playwright

Mirrors the sibling spec, including its login trap: `/__qa/login/{id}` sets `admin_impersonator`
unconditionally and **bypasses the Pro gate**, so the non-Pro locked-card scenarios must authenticate
by OTP and must fail rather than skip if only the QA login is available.

## Test strategy — what CI can pin, and what it cannot

**CI can pin (PHPUnit):** every port *rule* — the full-triple dedup, `insightlpr` out / `titlemax`
in, `event_type` as discriminator with `ls-claim` out, inclusive UTC `BETWEEN` on the integer `ts`,
the org OR-axis, the 7-day boundary at exactly `claim_date − 7` and one day earlier, a NULL agent
counting in `deduped` but never in `db_repos`, the CDC discipline (a soft-deleted or duplicate-version
hotlist row must not inflate; a tombstoned `billing_events` row must be excluded even though `FINAL`
would keep it), UTC month bucketing, and a dead-port connection raising instead of returning 0. On
the card: the card key, a registered non-gray colour, the Pro gate across all four tiers plus the
impersonator bypass, `lockedStat()` executing zero queries, **exactly one round trip per seam across
all four filter states**, 13-element series with missing buckets as 0, UTC-not-viewer bucket
alignment, and propagation instead of a fabricated 0.

Two mechanical constraints on how those are written. The ClickHouse driver **never fires
`DB::listen`**, so the round-trip assertions use counting subclasses over the `fetchMonthlySeries()` /
`fetchWindowTotal()` / `runWindowQuery()` seams — a listener-based assertion would silently prove
nothing. And `RequiresClickhouse` `markTestSkipped`s the whole ClickHouse suite unless
`CLICKHOUSE_DRIVER=clickhouse`, against the **literal** `clarity`/`zod` databases: a CH suite that
looks suspiciously fast and unanimously green has executed nothing. Both infra blockers (b1 schema
dump, b2 truncation list) are already fixed on main — verified: `zod.public_billing_events` at
`database/schema/clickhouse-schema.sql:52` and all four `zod.public_*` tables in the truncation list.

**CI cannot pin, and needs a live read-only check (t5):** the production number against Brek's Repo
Report, the server-side envelope, and the contended p99 the guard constants are sized against. Full
suite, if run: `php -d memory_limit=2G vendor/bin/phpunit` — `php artisan test` OOMs during
*discovery* at 128 MB and still exits 0, reporting a clean pass on a suite that never ran. Expect ~8
pre-existing failures; verify a suspected regression against the base commit before believing it.

## Residual risks

1. **The single-pass restructure is unvalidated at scale until t5.** It is the one place this plan
   departs from SQL that reproduced the target exactly. If it diverges, the fallback is the measured
   3-reference shape at 3× the `billing_events` read — correct but expensive. Do not ship on the
   restructure without t5.
2. **Editing `UniqueLprCaseClaims` (t1).** Mitigated by keeping it a pure refactor with no test
   edits, but the blast radius is a card that is already live for 29 entitled orgs.
3. **Billing adjacency.** This number sits on the same `(forwarder OR agent)` axis that backyard's
   `ClarityCommercialFeeSummary` bills from, so a definitional bug eventually surfaces as a billing
   disagreement. Read-only here, but it raises the cost of getting the definition wrong — which is
   the whole reason A1 was chosen over the 5.9× cheaper variant B. **No node carries a
   `blast_radius` tag**: none of the ten files matches the sensitive globs (`database/migrations/**`,
   billing jobs/resources, Shield/Policies/auth config, `Clickhouse*Purge|Cleanup`, `PruneScans`).
   Flagged in prose instead so the driver can escalate if it disagrees with that reading.
4. **Concurrency is still unmeasured.** The org filter does not reduce the scan at all (664M rows
   whether scoped to one org or platform-wide, because every table is `ORDER BY id`), so N orgs
   cache-missing is N × 15 GB. dc1 proposes a batch shape (`arrayJoin` over both org sides, grouped
   by org and month, cached as a map — what `ClientRankedMetricsBatch` / `BatchMonthlySparklineData`
   already do) that turns N × 15 GB into 1 × 15 GB. **Deliberately not planned here**: it changes
   the caching object from a per-org `Cache::flexible` key to a shared map, contradicting the
   ticket's explicit per-window caching mandate, and with 29 entitled orgs the per-org shape is
   affordable. It should be recorded as the known next optimisation, not smuggled in.
5. **`getStats()` has no per-card `try`/`catch`.** A propagated exception 500s all sixteen cards.
   That is the accepted precedent (swallowing is worse when zero is a normal reading), but it is a
   shared-fate design and worth restating in the Build Record.
6. **Two calendars, inherited.** The month axis is UTC while `today`/`last_7d` stay on the viewer's
   local day, so toggling the filter can make the headline *fall* near a month boundary. Documented,
   deliberate, and now mirrored on a second card.

## Queued for after merge/QA (not now — CLAUDE.md WRITE rule)

- `okf/concepts/company-metrics-dashboard.md`'s `cards()` table lists 10 cards; main has 16.
- Per-table `FINAL` cost numbers for `zod.public_*` (the wiki carries the rule, not the measurements).
- No okf page documents `CompanyMetricsOverview` or the `DashboardWidget` card system at all.
- The `cards()` docblock's grid-alignment claim ("re-aligns at 12") is unverified at 16 cards.
