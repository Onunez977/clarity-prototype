# DEV-2465 — Plan (Phase 3, single-arbiter)

**Ticket:** Company Metrics — Unique Scans to Case Claims card
**Repo:** `platform` · **Branch:** `claude/DEV-2465` · **Base:** `origin/main` e469cf2d
**Source of truth for this plan:** `docs/investigations/DEV-2465/prep-context.json` (confidence 88, gate CLEAN)

Replace the DEV-2409 zero-query stub `UniqueScansToCaseClaims` — already registered at
`CompanyMetricsOverview::cards()['unique-scans-to-case-claims']`, grid row 2 col 3 — with a real
card rendering a **percentage**: the share of case-claimed vehicles the fleet's cameras had already
seen before the claim. First non-integer card on the grid.

---

## Settled decisions carried in (do not reopen)

| Q | Decision |
| --- | --- |
| **Q1** | **Option C.** Numerator *and* denominator both counted on the `(forwarder_org_id, agent_org_id, vin)` triple. Numerator = `uniqExact` of triples whose claimed order has ≥1 `clarity.hits_cold` row with `scanned_at` < that claim's own epoch `ts`. Denominator = `UniqueLprCaseClaimsQuery`'s existing `deduped` stage, unchanged. Jul 2026 fleet-wide **11,573 / 11,975 = 96.6%**. Zero orgs above 100%. |
| **Q2** | **Unbounded lookback.** Any pre-claim sighting counts however old. Explicit caveat on the PR. |
| **Q3** | **Render `0.0%`** on a legitimately zero denominator. Not N/A, not hidden. Precedent `HotlistCount.php:209-211`. |
| **Q4** | **Extend `UniqueLprCaseClaimsQuery` additively** to expose `hotlist_id`, so numerator and denominator come from ONE pass. `window()`/`monthlySeries()` keep `{all_claims, successful, deduped}` **byte-for-byte**; DEV-2463's shipped tests pass **unmodified**. |
| **Q5** | Settled by `EXPLAIN` in **t1**. `hotlist_org_id` is `hits_cold`'s leading sort-key column and the only real prune axis. |
| **Q6** | Answered. Option C cannot exceed 100%. |

---

## Sizing gate — TRIPPED, and the recommendation is NOT to split

Nine files, which exceeds the six-file threshold. Stating it explicitly as required, with the
honest recommendation:

- **Only two are app code**: `app/Clickhouse/Queries/UniqueLprCaseClaimsQuery.php` and
  `app/Filament/Widgets/Dashboard/UniqueScansToCaseClaims.php`.
- The other seven: four 1:1 pinning tests, a 2-line + 1-line test-allowlist edit, one Playwright
  spec, three additive e2e helper constants, and one measurement artifact.
- **In-family precedent:** DEV-2463 (`#392`, `012030f3`) shipped this exact card shape across
  **47 files**. DEV-2467 (`#393`) and DEV-2409 (`#394`) were comparable.
- **Every further split ships something broken.** Drop t5 → a Pro-only metric is visible to
  non-Pro orgs and AC-6 fails. Drop t2 → the card has nothing to call. Drop t4 → an additive
  query change with zero callers.

**Only clean severance point: t6** (`tests/e2e/unique-scans-to-case-claims.spec.ts` +
`tests/e2e/helpers/company-metrics.ts`). Playwright, additive-only, outside the PHPUnit CI lane,
and verifies nothing at the value level that t4/t5 do not. Severing it lands the ticket at seven
files. **Recommended to keep in-ticket** — DEV-2463, DEV-2461 and DEV-2467 each shipped their spec
in-ticket, and a deferred spec has historically not been written.

---

## Preconditions (block the DAG, not part of it)

| ID | What | Why it matters |
| --- | --- | --- |
| **P1** | Re-key the CH database names to the **literal** `clarity` / `zod` before any measurement or CH test. | `.env` has `CLICKHOUSE_DATABASE_CLARITY`/`_ZOD` = `zod_claude_dev_2465_f91033`, a per-worktree name that does not exist on Cloud, so `clarityDb()`/`zodDb()` resolve to a nonexistent database. The names also derive from the **pre-rename** branch `claude/dev-2465-f91033` while the run branch is `claude/DEV-2465`; Phase 3.5 will re-key them again — re-verify dev-login after. |
| **P2** | `.env.testing` must set `CLICKHOUSE_DRIVER=clickhouse` against literal `clarity`/`zod`. | It is currently `pgsql`. `RequiresClickhouse` (`tests/Concerns/RequiresClickhouse.php:45-48`) `markTestSkipped()`s unless the driver is `clickhouse`, so **every CH test reports GREEN having executed nothing**. This has produced a false pass in this repo before. |
| **P3** | Verify rendered card values in a **real browser**, never curl/grep. | `APP_ENV=local` → `deferLoading` is on → an HTTP check returns 200 with an empty skeleton. |
| **P4** | Seed QA fixtures **last**, after the final suite run. | `RequiresClickhouse` truncates `clarity.hits_cold`, `zod.public_billing_events`, `zod.public_api_keys`, `zod.public_orgs` (`:21,39-41`) — a test run wipes the seed. |

---

## The DAG

```
t1  measure + Q5/perf gate  (docs artifact only)
 └─ t2  extend UniqueLprCaseClaimsQuery  [BLAST: qa_complete_shared_query]
     ├─ t3  DEV-2463 no-regression gate  (0 files)      ─┐ parallel
     └─ t4  card data seams  [ui]                       ─┘
         └─ t5  Pro gate + both allowlists  [BLAST: company_metrics_entitlement] [ui]
             └─ t6  Playwright spec + helper constants  [ui]
```

| id | title | deps | ‖ | complexity | blast radius | test filter |
| --- | --- | --- | --- | --- | --- | --- |
| t1 | Reproduce option C read-only against prod; settle Q5 + perf gate | — | ✓ | complex | — | `null` |
| t2 | Extend `UniqueLprCaseClaimsQuery` additively (TDD: query test first) | t1 | ✗ | complex | `qa_complete_shared_query` | `UniqueScansToCaseClaimsQueryTest` |
| t3 | DEV-2463 no-regression gate (five suites, unmodified) | t2 | ✓ | simple | — | `UniqueLprCaseClaims` |
| t4 | Card data seams: float-safe rate, UTC rate series, `%` description | t2 | ✓ | complex | — | `UniqueScansToCaseClaims` |
| t5 | COMPANY_METRICS Pro gate + both hardcoded allowlists | t4 | ✗ | complex | `company_metrics_entitlement` | `DashboardCardVisibilityTest\|UniqueScansToCaseClaimsTest` |
| t6 | Playwright spec + e2e helper constants | t5 | ✓ | simple | — | `null` (Playwright) |

`uat_needed: true` — t4, t5 and t6 all carry `ui_impact`.

**Serialization rationale.** t5 cannot run parallel with t4: both edit
`UniqueScansToCaseClaims.php` and `UniqueScansToCaseClaimsTest.php`. The Pro gate is nonetheless
its **own** node so the entitlement hard-stop gates only three small files rather than the card's
whole data rewiring. t3's file set is empty, so it is disjoint from everything and runs concurrently
with t4.

---

## Non-negotiable constraints, mapped to nodes

1. **The LEFT JOIN default-fill trap → t2.** `join_use_nulls` defaults to 0, so a LEFT JOIN miss
   fills non-Nullable `scanned_at` with `1970-01-01`, **not NULL** — earlier than every claim `ts`.
   The naive `first_scan < claim_ts` therefore counts every hitless order as pre-claim, inflating
   96.6% → **99.7%**, silently. Measured: naive 11,819 vs correct 11,573/11,449, the difference
   being exactly the 370 orders with no hit. Guard: an explicit `s.hotlist_id != 0` term ANDed into
   the `uniqExactIf` condition. **t1 records the naive result as a deliberate control; t2 pins it
   with a test that seeds an order with zero hits.**
2. **A rate cannot use the inherited totals → t4.** `DashboardWidget` declares
   `getCardTotal(): int` (`:321`), `currentPeriodTotal(): int` (`:344`),
   `getRangeTotal(string,string): int` (`:367`). PHP fatals at class-load on an int→float widening,
   and using them unwidened truncates 96.6 → 96 or 0. The rate is computed end-to-end inside
   `getCardRate(): float` for **every** date option including today/last_7d. The surviving int
   stubs stay as documented dead code, pinned by the WindowSeamTest.
3. **The Pro gate is missing → t5.** Add `canViewCardData()` (`FeatureEnum::COMPANY_METRICS` +
   `admin_impersonator` bypass, copying `UniqueLprCaseClaims.php:101-106`) **and** add
   `'unique-scans-to-case-claims'` to **both** hardcoded allowlists in
   `DashboardCardVisibilityTest.php` — `$expectedGated` at `:59-64` and the `array_diff` literal at
   `:481`. Omitting either turns both tests RED. The fast pass and candidate 1 both declared that
   file out of scope; the reconcile caught it.
4. **`uniqExact` everywhere, never bare `count()` → t2.** `hits_cold` measures **1.543x over**
   on this population (186,683 vs `uniqExact(id)` 120,987) and FINAL does not fix it, because
   `hit_created_at` is mutable and in the sort key.
5. **`_peerdb_is_deleted = 0` is mandatory → t2** on `public_billing_events` (1-arg
   `ReplacingMergeTree`; FINAL does not drop its tombstones) and on `hits_cold`. `public_orgs` and
   `public_api_keys` are read FINAL (a renamed org otherwise fans out 2x on the join).
6. **Reject `insightlpr` on both org sides; NEVER filter `titlemax` → t2.** `titlemax` is a
   display flag; filtering it is a −2.6% error and is precisely what caused the DEV-2409
   investigation. Pinned by a test seeding an org named `TitleMax`.
7. **`clarityDb()`/`zodDb()` helpers, never a hardcoded `clarity.`/`zod.` prefix → t2.** UTC
   calendar-month window, inclusive `BETWEEN` on the integer epoch `ts`. `event_type`, not `type`.
   Read `hits_cold`, never `hits_hot` (its ~90-day TTL would truncate a metric that spans years).
   Note this class is deliberately **not** a `ClickhouseQuery` subclass, so it carries its own
   copies of both helpers (`zodDb()` at `:175-180`).
8. **Propagate Throwable, never swallow → t2.** Matches the class's existing stance (`:86-88`). A
   genuine zero is normal for ~83% of orgs, so swallowing makes an outage indistinguishable from
   real data. Accepted consequence: a CH failure 500s the whole 16-card grid.
9. **`Cache::flexible([3600, 86400])` once per resolved window → t4.** The 13-month sparkline is
   ONE batched call of per-month **rates** (a rate series cannot be summed). Follow
   `UniqueLprCaseClaims::getMonthlyData()`/`fetchMonthlySeries()`. Do **not** import
   `BatchMonthlySparklineData` — zero callers, client-portal family.
10. **Do not touch** `CompanyMetricsOverview::cards()` (already registers the card),
    `DashboardWidget::getStatColor()` (arm already at `:311`), or the 16-card count/order
    assertions (already correct — the ticket's "bump the count" instruction is stale).

---

## Node detail

### t1 — Reproduce option C read-only against production; settle Q5 and the perf gate

`prep-context` has the option-C figures in `derivation_log` but **no committed SQL file** — unlike
DEV-2463, which shipped `docs/investigations/DEV-2463/measure-claims-card.sql` and then reused that
body verbatim in the query class. Re-materialise the verified shape into
`docs/investigations/DEV-2465/measure-rate-card.sql` and re-run it read-only against the **literal**
`clarity`/`zod` Cloud databases (P1) for the Jul 2026 UTC calendar month, fleet-wide.

Three results must hold before t2 starts:

- **(a)** `deduped` = 11,975 and numerator-on-the-triple = 11,573 → **96.6%** (AC-1's figure).
- **(b)** the **naive** predicate without the join-miss guard returns **11,819**, recorded as a
  deliberate control so constraint 1 is reproducible and testable rather than folklore.
- **(c)** `EXPLAIN` plus the `X-ClickHouse-Summary` header (`read_rows` / `read_bytes` /
  `memory_usage`) for the combined shape, run twice — **with and without** a `hotlist_org_id`
  predicate on the `hits_cold` side. This answers **Q5** and is the WS6 perf gate that
  `resource_envelope.unresolved` says must be measured before implementation: the denominator alone
  already measures a contended **p99 of 9.3s**, and Q2's unbounded lookback removes the date floor
  that would otherwise let `hits_cold` partition-prune.

Numbers go into the `.sql` header comment and the ticket — **not** into `okf/` (CLAUDE.md WRITE
rule: the wiki lands post-approval).

**Gate:** stop and return to the operator if (a) does not reproduce 96.6%, or if (c) shows the
combined shape is unaffordable against the already-measured 9.3s p99 with no prune available.

### t2 — Extend `UniqueLprCaseClaimsQuery` additively

Write `tests/Feature/Clickhouse/UniqueScansToCaseClaimsQueryTest.php` **first** — a NEW file, so
DEV-2463's `UniqueLprCaseClaimsQueryTest.php` stays byte-for-byte unmodified as Q4 requires.

Strict additivity is **mechanical, not aspirational**: the `hotlist_id` projection, the raw
`be.ts AS ts` projection and the `hits_cold` CTE are **all** conditional on a new `$withRate` flag
defaulting to `false`, so `window()` and `monthlySeries()` emit byte-identical SQL and byte-identical
`{all_claims, successful, deduped}` arrays.

Diff sketch:

- new `windowWithPreClaimScans(?int $orgId, CarbonInterface $start, CarbonInterface $end): array`
  → `{all_claims, successful, deduped, pre_claim_seen, rate}`, mirroring `window()` (`:46`)
  including its recorded fail-open `$orgId` trap.
- new `monthlySeriesWithPreClaimScans(?int $orgId, CarbonInterface $through): array` keyed
  `'Y-m-01'`, mirroring `monthlySeries()` (`:61`) including its
  `utc()->endOfMonth()`/`subMonthsNoOverflow(12)` derivation.
- `sql(bool $bucketed)` → `sql(bool $bucketed, bool $withRate = false)`. When `$withRate`: the
  `claims` CTE additionally projects `JSONExtractInt(be.additional, 'hotlist_id')` and `be.ts`; a new
  `orders` CTE groups `kept` to `(hotlist_id, min(ts) AS claim_ts)` carrying `any()`-resolved
  forwarder/agent/vin (`derivation_log` entry 6: **vin and forwarder never vary** within an order;
  agent varies in 201 of 11,855); a new `seen` CTE reads `{clarity}.hits_cold` as
  `(hotlist_id, min(scanned_at) AS first_scan)` with `_peerdb_is_deleted = 0` plus the
  t1-determined `hotlist_org_id` predicate; LEFT JOIN on `hotlist_id`; the SELECT gains
  `uniqExactIf((forwarder_org_id, agent_org_id, vin), s.hotlist_id != 0 AND s.first_scan < claim_ts) AS pre_claim_seen`.
- `run()` gains a `{clarity}` `strtr` key (no-op for the non-rate SQL); new private
  `clarityDb()` mirroring `zodDb()` (`:175-180`).
- new `normalizeRateRow()` alongside `normalizeRow()` (`:95`). **The rate is computed in PHP** —
  `$denom > 0 ? round($num / $denom * 100, 1) : 0.0` — because ClickHouse division by zero yields
  inf/nan and Q3 requires a clean `0.0`.
- docblock: the option-C grain, the LEFT-JOIN trap with its measured 11,819-vs-11,573 control,
  Q2's unbounded-lookback caveat, and an explicit *"`window()`/`monthlySeries()` are frozen —
  DEV-2463 #392 is QA-Complete"* note.

The new test pins: the join-miss guard (an order with **zero** `hits_cold` rows must not count),
the strict `scanned_at < ts` boundary against `==` and `>`, `uniqExact` on a duplicated `hits_cold`
id and on a duplicated claim triple, numerator ≤ denominator at the option-C grain, `rate` 0.0 on a
zero denominator, `insightlpr` excluded on both sides, an org named `TitleMax` still counted,
`_peerdb_is_deleted = 1` tombstones excluded on both tables, an unbounded (years-old) pre-claim hit
still counting, `hits_hot` never read (seed `hits_hot` only, assert 0), UTC calendar-month bucketing,
exactly ONE round trip per public method, and that a genuine CH failure **throws**
`ClickhouseException` rather than returning a fabricated 0 (driver `clickhouse` against dead port
`127.0.0.1:1` + `DB::purge`).

**Blast radius `qa_complete_shared_query`** per the caller's explicit instruction. Deliberately
**not** tagged `billing`: this reads the `zod.public_billing_events` CDC mirror read-only and touches
no billing job, resource, or migration.

### t3 — DEV-2463 no-regression gate

The other half of Q4's constraint, made a DAG node so it cannot be skipped. Run all five shipped
DEV-2463 files with **zero edits**: `UniqueLprCaseClaimsQueryTest`, `UniqueLprCaseClaimsTest`,
`UniqueLprCaseClaimsWindowSeamTest`, `UniqueLprCaseClaimsHerdGuardTest`,
`UniqueLprCaseClaimsLabelAxisTest`.

Confirm **P2 first** — with `CLICKHOUSE_DRIVER=pgsql` these report GREEN having executed nothing,
which would make this gate worthless.

Additionally **diff the emitted non-rate SQL by hand** (dump `sql(bucketed: false)` and
`sql(bucketed: true)` before and after t2) to prove the byte-for-byte claim directly rather than
inferring it from green tests. Private-method reflection or a scratch script — **not** a committed
test. If any of the five needs a change to pass, t2 was not additive: revert and re-approach; never
edit the shipped test.

### t4 — Card data seams

Constraint 2 shapes the whole node. The rate is computed end-to-end in `getCardRate(): float`, and
the surviving int stubs (`getModelQueryForDates()`, `currentPeriodTotal()`) are left returning 0 with
an *"unreachable by design"* docblock — **pinned**, not merely asserted, by the WindowSeamTest
written first.

Mirror the shipped sibling for everything already solved there:

- `this_month`/`last_month` come **free** from the already-cached 13-month series (no extra query,
  exactly as `getCardTotal()` `:321-333` does for integer cards); `today`/`last_7d` resolve through a
  single `windowWithPreClaimScans()` call. No filter state can trigger more than one round trip — AC-4.
- Split the call from the stage selection into `fetchWindowRate()`/`runWindowRateQuery()` seams
  (copying `UniqueLprCaseClaims.php:339-355`): DEV-2463's own history shows a
  `['deduped']` → `['successful']` mutant survived 35 tests until that split existed.
- `getMonthlyData()` returns 13 per-month **rate floats** from ONE
  `monthlySeriesWithPreClaimScans()` call under `Cache::flexible([3600, 86400])` **with the UTC
  month in the cache key** — the sibling's `:127-137` note: keyed only on the org, a series computed
  at 23:55 on the last UTC day of a month stays "fresh" into the next one and the `?? 0` fabricates a
  zero indistinguishable from a real one. A rate series is **not summable**, so nothing may aggregate
  these buckets; `chartSeries()` already casts each bucket to float and passes it through unchanged
  (`BuildsDateFilterChartSeries.php:47-63`), so the sparkline machinery needs no change.
- Because the month axis is UTC, the **label axis must co-vary**: override `getGraphLabels()` and
  `getHighlightedMonthsFromFilter()` to UTC exactly as the sibling does (`:234-254`), or a Chicago
  viewer in the first 5-6 hours of a UTC month sees every bar labelled one month early.
- Override `getDescription()` to emit `%`-suffixed values from the rate series rather than the
  base's `number_format(currentPeriodTotal())`.
- Copy the sibling's cold-path herd guard (`heldFlexible` + `COLD_BLOCK_SECONDS` /
  `COLD_LOCK_SECONDS` / `REFRESH_LOCK_SECONDS` + `flexibleCreatedKey`) and its `getCacheTTL()`
  `MIN_TTL_SECONDS` floor. This query is the sibling's ~1.83 GB scan **plus** a `hits_cold` join, so
  it is strictly worse than the 9.3s contended p99 those constants were sized against, and the 10s
  impersonator TTL collapse cannot be honoured. Deliberate duplication — extracting a shared trait
  would mean editing `UniqueLprCaseClaims` itself, widening t2's blast radius for no correctness
  gain. Docblock it; DEV-2464 is the third caller that should trigger the extraction.
- Keep `toStat()` exactly as the stub has it (`number_format($this->getCardRate(), 1).'%'`).
- **Do not** add `canViewCardData()` here — that is t5, isolated so this node carries no blast-radius
  tag. Delete the stub docblock's *"The card ticket replaces the data methods in place and removes
  this note."* line.

### t5 — Pro gate and both allowlists

The stub inherits `RendersDashboardStat`'s guest-total-true default (`:120-131`) and has no
`canViewCardData()` of its own, so **today an unentitled org would see the real rate**. Add the gate
copying `UniqueLprCaseClaims.php:101-106` verbatim in shape: `auth()->check()` **first**
(`Dashboard::cardWidgets()` reaches this unauthenticated and the Pennant resolver takes a
non-nullable `User`), then `static::canView()` ANDed with
`(Feature::active(FeatureEnum::COMPANY_METRICS->slug()) || session()->has('admin_impersonator'))`.
The AND is load-bearing — cards are built as mount-less adapters so `canView()` is otherwise not
enforced on the compact path, and `Feature::active()` is org-level so it does not subsume the role
term.

Then update **both** allowlists in `DashboardCardVisibilityTest.php` (`$expectedGated` `:59-64` and
the `array_diff` literal `:481`) and correct the adjacent prose from *"FOUR production cards are now
gated"* to FIVE. The file's own comment explains why they are hardcoded: *"Keep this an explicit
allowlist, not a 'whatever cards() says' tautology: gating a card is a deliberate, reviewed act and
never an accident."*

The Pro-tier matrix (guest / basic / pro_lite / clarity_pro / clarity_pro_plus /
admin_impersonator) plus a tripwire proving `lockedStat()` runs **no** ClickHouse query goes into
`UniqueScansToCaseClaimsTest.php`.

### t6 — Playwright spec and e2e helper constants

`tests/e2e/helpers/company-metrics.ts` currently exports only 11 of the 16 card keys — the five
DEV-2409 stubs are absent — so the spec needs new
`UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY`/`_HEADING` constants and one `COMPANY_METRICS_CARD_HEADINGS`
entry. Additive only: no existing constant or entry changes, so the four sibling specs importing
from this file are unaffected.

Mirror `tests/e2e/unique-lpr-case-claims.spec.ts` (the precedent the driver verified exists
alongside the missed-opportunities and staging-live-hit-leader specs): heading and slot (row 2 col 3),
`%` rendering, all four `DATE_FILTERS`, the sparkline, `expandCard()`/`expandedDetail()`, and the
`LOCKED_VALUE` em dash plus `UPSELL_COPY` for a non-Pro org via `loginAsNonProOrgAdmin`. Keep the
`assertNotProduction()` guard.

Per **P4**, seed fixtures **after** the final PHPUnit run. Per **P3**, this is the only node that
verifies the rendered value in a real browser.

---

## Deliberately untouched

| File | Why |
| --- | --- |
| `app/Filament/Widgets/Dashboard/CompanyMetricsOverview.php` | Already registers this card at row 2 col 3 (`:99`). The key is both toggle key **and** cache namespace — never re-insert or reorder. |
| `app/Filament/Widgets/Dashboard/Concerns/DashboardWidget.php` | The `'unique-scans-to-case-claims' => 'success'` `getStatColor()` arm already exists (`:311`). The int-typed totals at `:321`/`:344`/`:367` must **not** be widened — that would ripple to every other card. |
| `tests/Filament/Pages/DashboardCardExpansionTest.php` | `assertCount(16)` (`:95`) and the layout-order assertions already read 16 and already list this card. The ticket's "bump the card count" instruction is **stale**, predating DEV-2409 #394. Re-run unmodified only. |
| The five `UniqueLprCaseClaims*` test files | Must pass **unmodified** (Q4). New coverage goes in new files; **t3** is the gate. |
| `tests/Concerns/RequiresClickhouse.php` | Already truncates `clarity.hits_cold`, `zod.public_billing_events`, `zod.public_api_keys`, `zod.public_orgs` (`:21,39-41`) — no infra change needed. |
| `app/Clickhouse/Queries/Client/BatchMonthlySparklineData.php` | **Do not import.** Unanimous across all four candidates: client-portal class for a different metric family with **zero** callers in `app/` or `tests/`. The ticket cites it as a *pattern*; the real in-family precedent is `UniqueLprCaseClaimsQuery::monthlySeries()`. |
| `LprRepossessions.php`, `UniqueScansToRepo.php`, `UniqueScansToLprRepossession.php`, `UniqueScansLeaderboard.php` | Still zero-query DEV-2409 stubs, each its own ticket (`UniqueScansToRepo`'s is DEV-2464, on HOLD/unmerged). |
| `okf/concepts/company-metrics-dashboard.md` | Its `cards()` table lists 10 keys against the code's 16 and needs this card's row. Correct **post-merge** per the CLAUDE.md WRITE rule. |

No `excluded_sites`: both `must-touch` sites from `prep-context.exhaustiveness` are covered
(`UniqueScansToCaseClaims.php` by t4 + t5; `DashboardCardVisibilityTest.php:59-64,481` by t5), and
the one `conditional` site (`UniqueLprCaseClaimsQuery.php`) is covered by t2 + t3.

---

## Caveats for the PR and Build Record

- **Q2 — unbounded lookback.** Any pre-claim sighting counts however old. `hotlist.lookback_days`
  (default 270) is deliberately **not** used: per `okf/concepts/lpr-assist.md:77` it governs only the
  Postgres `find_historicals()` H/Z trigger and is explicitly *"not the assist attribution window"*.
  Tightening it later is a product decision, and the unbounded read is also why the `hits_cold` side
  cannot be partition-pruned by date.
- **Q3 — `0.0%`.** Matches the shipped DEV-2463 sibling and `HotlistCount.php:209-211`. Dalton's
  stated preference on record is **N/A**; Oscar has been asked twice (DEV-2409 comments 38625 and
  39130, restated as item 3 of 39291) and has not answered. If N/A is chosen later it is retrofitted
  across all cards at once.
- **A ClickHouse failure on this card 500s the entire 16-card grid**, in dev and in production. That
  is the deliberate pre-existing house pattern (`unique-lpr-case-claims` is already in that set),
  chosen because a genuine zero is normal for ~83% of orgs so a swallowed failure would be
  indistinguishable from real data. **Do not "improve" this with a try/catch.**
- **Two calendars, deliberately.** The month axis is UTC while `today`/`last_7d` stay on the viewer's
  local day, inherited from `UniqueLprCaseClaims.php:31-41`. Toggling the filter can make the
  headline **fall** for an evening Chicago viewer. Both alternatives are worse: local months break
  AC-1's UTC-calendar-month reproduction; UTC days make a daily metric read ~0 every evening.
- **The herd guard is copied, not extracted**, to keep t2's blast radius to a strictly additive query
  change. DEV-2464 will be the third caller and should trigger the extraction.
- **AC-1's 96.6% is fleet-wide production data.** Agent QA must assert a hand-computable seeded ratio
  and the `%` format, **not** the literal 96.6%.
