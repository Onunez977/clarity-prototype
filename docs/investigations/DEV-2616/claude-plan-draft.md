# Ultra-Plan — DEV-2616 (independent)

**Clarity DB-prep qualifier on the Company Metrics path**
Tip re-read: `cursor/dev-2616-clarity-db-prep-625a` @ `b61044c3` · base `main` @ `76d4f5fc`
Verdict: **verify-only / no product PHP.** Prep's core call holds on tip. Two inventory corrections below.

---

## 1. ship_surface

| Surface | Ship? |
| --- | --- |
| Product PHP (`app/`) | **No** |
| Tests (`tests/`) | **No** — existing coverage already pins every AC-3 invariant |
| Migrations / schema | No |
| `okf/` | No (queue two corrections post-approval) |
| Jira / Galadriel | Yes — ticket-out follow-ups only |

The diff this key lands is **artifacts only** (`docs/investigations/DEV-2616/**`).

### Working-tree hygiene (blocking before any commit)

`git status` carries four modified files that are **not** this ticket's work:

```
M .cursor/rules/laravel-boost.mdc
M .junie/guidelines.md
M AGENTS.md
M CLAUDE.md
```

These are `laravel/boost` regeneration drift (a `npm run build` side effect — it rewrites the guideline mirrors). They must be reverted, not committed, or this PR silently rewrites the agent guideline surface. Prep does not mention them.

---

## 2. AC decisions, with proof

### AC-1 — `ScanStatsBatchQuery` uses `clarityDb()`, no hardcoded `clarity.`
**Decision: already satisfied on main. No edit.**

- `app/Clickhouse/Queries/ScanStatsBatchQuery.php:47` — `$clarityDb = static::clarityDb();`
- `app/Clickhouse/Queries/ScanStatsBatchQuery.php:53` — `FROM {$clarityDb}.scan_report_hourly`
- Helper: `app/Clickhouse/Queries/ClickhouseQuery.php:102-107` (`clarityDb()`), `:115-120` (`zodDb()`). Both return the literal schema under the `pgsql` bridge, otherwise `config('database.connections.{clarity,public}_clickhouse.database')`.
- Pattern parity: `app/Clickhouse/Queries/HitStatsBatchQuery.php:52` and `:91`, with the footgun documented at `:8-13`.
- Guard already in tree: `tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php:108-117` sets the configured database to `clarity_some_worktree` and asserts both the presence of `FROM clarity_some_worktree.scan_report_hourly` and the absence of `FROM clarity.scan_report_hourly`.

The Jira description's cited line 19 is the pre-port location. AC-1 needs verification, not work.

### AC-2 — CM `/app` loads on a local-CH QA worktree DB with no `clarity` view shim
**Decision: verify at QA. No shim. Page will load — but one header number is silently wrong.**

Two independent guards make a per-worktree DB non-fatal:
- `CompanyMetricsOverview::getStats` wraps **only** `toStat()` in `try`/`catch (\Throwable)` (`app/Filament/Widgets/Dashboard/CompanyMetricsOverview.php:208-213`); a failing card renders `--` / *Unavailable* via `unavailableStat()` (`:219-228`) and the loop continues.
- `UniqueScanCounts` additionally owns an unavailable flag — `UniqueScanCountsTest:552 test_an_unavailable_total_renders_as_unavailable_not_as_zero`.

**Correction to prep's framing.** Prep records the `clarity.kits` residual as fail-soft and stops there. Verified mechanism, and why it matters for how AC-2 is QA'd:

- `app/Clickhouse/Models/Kit.php:14` — `protected $table = 'clarity.kits';` (hardcoded).
- `app/Filament/Widgets/Dashboard/KitUsers.php:5` imports it; `cameraKitsCount()` at `:79-92` is a **deliberate no-catch** `cache()->remember()` ("letting a ClickHouse failure surface").
- `KitUsers` is **not** a CM grid card — `KitUsers.php:46-49` records that an earlier Company Metrics card change removed `'kit-users'` from `CompanyMetricsOverview::cards()`.
- The live consumer is `HotlistCount::kitUserCounts()` (`app/Filament/Widgets/Dashboard/HotlistCount.php:82-96`), which wraps **each leg** in `degradesToZero()` (`:114-123` — `report($e); return 0;`), outside `KitUsers`' cache so no fabricated 0 is cached.

Net: under a per-worktree `clarity_<wt>`, the page **200s**, but *Active Kit Users* and its *kits* secondary both render **0** — a wrong value, not an error. **AC-2 must therefore be QA'd by reading the header numbers, not by observing HTTP 200.** A "did it load" check passes while two numbers are silently zeroed. This is pre-existing and out of scope to fix here; it is in scope to state so QA does not mis-pass it.

### AC-3 — Scan Count monthly totals still correct in unit/feature coverage
**Decision: keep existing tests. Add none. Change no SELECT list.**

Consumer path confirmed: `UniqueScanCounts::fetchTotalMonthlySeries` (`app/Filament/Widgets/Dashboard/UniqueScanCounts.php:753-766`) calls `ScanStatsBatchQuery::handle()` — deliberately `handle()`, not `query()`, because `ClickhouseQuery::query()` swallows `Throwable` and returns `[]` in `production|testing` (`ClickhouseQuery.php:29-37`), which would make a failure indistinguishable from an empty org.

Coverage already pinning the monthly totals:

| File | Pins |
| --- | --- |
| `tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php` | db prefix (`:108`), viewer-zone month cut (`:48`), one-zone bounds↔grouping (`:71`), unlisted-tz→UTC (`:91`), malformed bound rejected (`:122`) |
| `tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php` | `:264` bucket-zone month keys, `:216` description reads the ported total, `:480` no fabricated zero on CH failure, `:604` non-swallowing entry point, `:768` bar-13 zone |
| `tests/Filament/Widgets/Dashboard/Concerns/CardQuerySqlFidelityTest.php` | delegation only (`:162`) — **not** a prefix guard; the contract test's own docblock (`:12-19`) says so |

The contract test lives in `Tests\Unit` on purpose (`:21-25`): the `Feature\Clickhouse` classes `use RequiresClickhouse` at class level, which would skip every method when `CLICKHOUSE_DRIVER != clickhouse` and make these guards invisible locally.

### AC-4 — Grep remaining hardcoded `FROM clarity.` / `FROM zod.` on the CM path; fix or ticket
**Decision: no CM-path qualifier remains. Ticket the rest out. Correction: prep's inventory is incomplete.**

Every class imported by `app/Filament/Widgets/Dashboard/**` resolves its database:

```
LprRepossessions            → LprRepossessionsQuery
Concerns/BuildsDateFilterChartSeries, Concerns/RatioAgainstUniqueScans → ScanPlateStatsBatchQuery
Concerns/SharedHitStatsData → HitStatsBatchQuery, HitPlateStatsBatchQuery, ScanPlateStatsBatchQuery
UniqueScanCounts            → ScanStatsBatchQuery, ScanPlateStatsBatchQuery, Models\Scan
RecoveryLeader              → RecoveryLeaderQuery
StagingLiveHitLeader, StagingLiveHits → StagingLiveHitLeaderQuery (+ plate/scan siblings)
UniqueLprCaseClaims         → UniqueLprCaseClaimsQuery
RecoveryCounts              → RecoveryTotalsBatchQuery
UniqueScansLeaderboard      → ScanPlateStatsBatchQuery
MissedOpportunities         → Models\MissedOpportunity   ($table = 'missed_opportunities', unqualified — MissedOpportunity.php:20)
```

None of `LprVisitCountQuery`, `RdnScanHourCountQuery`, `RdnHitHourCountQuery`, `Client/CohortClosedQuery`, `Client/CohortDeclinedQuery` appears in that import set. **AC-4's "fix" branch does not trigger; the "ticket" branch does.**

---

## 3. ticket-out list (Galadriel / Jira follow-ups — do **not** edit under this key)

Prep scoped its grep to `app/Clickhouse/Queries`. A whole-`app/` grep finds **two executable qualifiers prep never lists**, marked ⚠ below.

| # | File:line | SQL | Consumer | Named in ticket? |
| --- | --- | --- | --- | --- |
| 1 | `app/Clickhouse/Queries/LprVisitCountQuery.php:19` | `FROM clarity.scans_cold` | `LprVisitCountController` | yes |
| 2 | `app/Clickhouse/Queries/RdnScanHourCountQuery.php:27` | `FROM clarity.scan_report_hourly` | `Services/Rdn/RdnMetricsPublisher` | yes |
| 3 | `app/Clickhouse/Queries/RdnHitHourCountQuery.php:38` | `FROM clarity.hits_hot FINAL` | `RdnMetricsPublisher`, `Jobs/Rdn/DispatchRdnMetricsHourly` | yes |
| 4 | `app/Clickhouse/Queries/Client/CohortClosedQuery.php:30,84,138,179` | `FROM zod.public_hotlist_status_log` ×4 | Client Analytics cohort | no |
| 5 | `app/Clickhouse/Queries/Client/CohortDeclinedQuery.php:30,84,138,179` | `FROM zod.public_hotlist_status_log` ×4 | Client Analytics cohort | no |
| 6 ⚠ | `app/Http/Controllers/ScanVehicleController.php:42` | `FROM zod.scans_metadata_extracted` | scan-vehicle endpoint | **missing from prep** |
| 7 ⚠ | `app/Http/Controllers/NearestGivenAddressController.php:44` | `FROM clarity.recovery_address ra FINAL` | nearest-address endpoint | **missing from prep** |
| 8 | `app/Clickhouse/Models/AffiliateStatsHourly.php:41` **and `:47`** | `FROM clarity.affiliate_stats_hourly FINAL`, `INNER JOIN clarity.kits` | affiliate stats | prep cites `:41` only |
| 9 | `app/Clickhouse/Models/LprAssist.php:64` **and `:65`** | `FROM clarity.lpr_assists`, `JOIN clarity.recoveries` | LPR assist | prep cites `:64` only |
| 10 | `app/Clickhouse/Models/Kit.php:14` | `$table = 'clarity.kits'` | `KitUsers` → `HotlistCount` header, **degrades to 0** | — |
| 11 | `app/Clickhouse/Models/Recovery.php:16` | `$table = 'clarity.recoveries'` | not CM | — |
| 12 | `app/Clickhouse/Models/HitStatsDaily.php:12` | `$table = 'clarity.hit_stats_daily'` | not CM (CM uses `HitStatsBatchQuery`) | — |
| 13 | `app/Clickhouse/Models/RecoveryKitScanReport.php:13` | `$table = 'clarity.recovery_kit_scan_report'` | not CM | — |

**Recommended split:** one follow-up for rows 1–3 (ticket-named query classes), one for 4–7 (Client cohort + the two controllers), one for 10–13 (Eloquent `$table` prefixes — needs a `$table` resolution strategy, not a string swap, so it is a genuinely different design). Row 10 carries the silent-zero header note from AC-2.

---

## 4. Verification commands (existing tests only — run on request)

No live ClickHouse required for the first two: `handleSql()` is a pure string function and the prefix test sets `driver => 'clickhouse'` itself.

```bash
php artisan test --compact tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php
php artisan test --compact tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php
php artisan test --compact tests/Filament/Widgets/Dashboard/Concerns/CardQuerySqlFidelityTest.php
php artisan test --compact tests/Filament/Widgets/Dashboard/CompanyMetricsOverviewTest.php
```

Narrowest single gate for AC-1:

```bash
php artisan test --compact --filter=test_the_database_prefix_is_resolved_not_hardcoded
```

Static re-grep for the AC-4 record:

```bash
rg -n -i '(FROM|JOIN|INTO|UPDATE)\s+(clarity|zod)\.' app/
rg -n "\$table\s*=\s*'(clarity|zod)\." app/
```

Per repo policy these run **only when explicitly requested**; CI runs the suite after commit. No `--parallel`. `.env.testing` only.

---

## 5. Explicit non-goals

- Re-editing `ScanStatsBatchQuery` SQL or its SELECT list.
- Any `clarity.*` / `zod.*` **view shim** in QA (the thing AC-2 exists to make unnecessary; the prior QA view-shim work is QA Complete — not reopened).
- Porting rows 1–13 above under this key.
- A new database-resolution abstraction or a second resolver beside `clarityDb()`/`zodDb()`.
- Fixing the `clarity.kits` header-zero, the missing `FINAL` on `ClickhouseKit` (`HotlistCount.php:269-271`), or the unfiltered "camera kits" product question.
- `okf/` edits during this run (WRITE gate — post-approval only).
- Marking provisioned; provisioning anything; any Cloud ClickHouse write.
- Inventing a fleet or Mac Herd DSN.
- Any touch of the soft-twin fleet — no shared worktree, branch, ledger, or env.

---

## 6. Next phase

**Provision — verify-only path.** Nothing to implement, so the phase is a read-only confirmation, not a build:

1. Revert the four boost-regen files (§1) so the PR is artifacts-only.
2. Run the four test files above (on request) — AC-1 and AC-3 close on green.
3. For AC-2, if a local-CH QA worktree DB is stood up: load `/app`, confirm **200 + the CM grid renders with no card showing `--` / Unavailable**, and **separately read the header** — *Active Kit Users* / *kits* at 0 is the expected pre-existing `clarity.kits` degradation, not an AC-2 failure and not a pass-by-silence.
4. File the §3 follow-ups; attach the row 6/7 additions to the AC-4 record.
5. Queue (do not write) the two `okf/` corrections in §7.

---

## 7. Disagreements vs `docs/investigations/DEV-2616/prep-context.json`

Prep's headline call — AC-1 already on main via `93f9a249`, no product PHP, ticket the rest out — **is confirmed on tip at the cited lines.** Four corrections, none of which change the verdict:

1. **Inventory incomplete (material to AC-4).** `reconcile.driver_verified[6]` scopes the grep to `app/Clickhouse/Queries`, and `inventory.ticket_out` reads as the full picture. A whole-`app/` grep adds two executable qualifiers absent from every prep list: `app/Http/Controllers/ScanVehicleController.php:42` (`FROM zod.scans_metadata_extracted`) and `app/Http/Controllers/NearestGivenAddressController.php:44` (`FROM clarity.recovery_address ra FINAL`). AC-4 asks for a grep; the recorded grep under-reports.

2. **AC-2 framing is optimistic (material to how QA is run).** Prep says `HotlistCount::kitUserCounts` "degrades a KitUsers failure to 0" and leaves AC-2 as a clean HTTP check. The mechanism is verified (`HotlistCount.php:92-95` + `:114-123`), but the consequence is not stated: under a per-worktree DB the header renders **0, silently**. Prep's own AC-2 `verify_later` reason ("proving HTTP 200 is Agent QA") would pass on a page whose header numbers are wrong. AC-2 QA must read the numbers.

3. **Line citations undercount.** `AffiliateStatsHourly` also hits at `:47` (`INNER JOIN clarity.kits`), `LprAssist` also at `:65` (`JOIN clarity.recoveries`). Prep lists one line each. Cosmetic, but a port scoped off prep's line numbers would miss half the statement.

4. **Count drift.** `synthesis`/`driver_verified[7]` say "twelve Company Metrics query classes"; `inventory.in_scope_company_metrics` enumerates **nine**. The nine are correct and complete against the Dashboard import set — the "twelve" is unsupported.

Agreed with prep and independently confirmed: `jira_drift[0]` (description cites the pre-port line 19; immaterial, journal only) and `jira_drift[1]` (`okf/concepts/company-metrics-dashboard.md` claiming a `ScanStatsBatchQuery` failure propagates to a grid 500 — contradicted by `CompanyMetricsOverview.php:208-213`; queue the wiki correction, code wins).
