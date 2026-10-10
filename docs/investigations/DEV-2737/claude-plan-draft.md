Write/Bash tools are disabled in this session, so the draft is returned inline below (the invoking harness captures stdout into `claude-plan-draft.md`).

---

# DEV-2737 — Ultra-Plan draft (Claude co-pilot, read-only)

Verify-first. No product PHP in this ticket unless the Cloud measure proves the card wrong **and** the fix is explicitly pulled into scope; otherwise the fix lands as follow-on ACs (AC-3).

Locked inputs: org **14** (Brad Webb / premieradjusters); UI ≈ **509** on **This Month**, card `lpr-repossessions`, production/Cloud ClickHouse, viewed while **admin-impersonating** Brad Webb. No integer more precise than ~509 is assumed anywhere below — pinning the exact UI integer is step V1.

## AC-1 — The exact query path (read from code, not memory)

| # | Hop | File · lines |
| --- | --- | --- |
| 1 | Card key `lpr-repossessions` → widget `LprRepossessions` | `okf/concepts/company-metrics-dashboard.md:28` |
| 2 | Default filter is `this_month` (`$date`, and `mount()`'s `filters['date_toggles'] ?? 'this_month'`) | `app/Filament/Widgets/Dashboard/Concerns/DashboardWidget.php:25,97` |
| 3 | **`this_month` does NOT use the window leg.** `getCardTotal()` routes `today`/`last_7d` to `currentPeriodTotal()`; everything else reads the **last bucket of the 13-month series** | `DashboardWidget.php:356-367` |
| 4 | `getMonthlyData()` → `heldFlexible(sharedMonthlySeriesCacheKey($orgId, now('UTC')->format('Y-m')), ladderFlexibleTtl(), fetchMonthlySeries(...))`; headline = `$series['<UTC month>-01']['db_repos'] ?? 0` | `app/Filament/Widgets/Dashboard/LprRepossessions.php:225-267` |
| 5 | Seam → `LprRepossessionsQuery::monthlySeries($orgId, now('UTC'))` | `LprRepossessions.php:403-406` |
| 6 | Window arithmetic: `end = through->utc()->endOfMonth()`, `start = end->subMonthsNoOverflow(12)->startOfMonth()`, `buildSql(bucketed: true)` | `app/Clickhouse/Queries/LprRepossessionsQuery.php:125-149` |
| 7 | SQL body: `claims` (from `{clarity_db}.billing_claim_events_v`, `is_internal = 0`, `event_type = 'case-claim'`, `ts BETWEEN`, `event_date BETWEEN`, org on **either** side) → `deduped` (`BillingClaimDedup::dedupedCteSql()`) → LEFT JOIN status-3 `public_hotlist` FINAL → LEFT JOIN `public_hotlist_status_log`; `db_repos = uniqExactIf(triple, toDate(hsl.created_at,'UTC') >= claim_date - 7)` | `LprRepossessionsQuery.php:170-218`; `app/Clickhouse/Queries/BillingClaimDedup.php:22-25` |
| 8 | View resolves to `clarity.billing_claim_events FINAL WHERE _peerdb_is_deleted = 0` | `database/schema/clickhouse-schema.sql:85` |

Two facts that dominate the reconciliation and are easy to get wrong:

- **The headline is a bucket of a 13-month query, not a month-scoped query.** `windowSql()` exists but only serves `today`/`last_7d` (`LprRepossessions.php:612-642`). A measure that scopes `ts`/`event_date` to September alone is a *cross-check*, not the card's SQL.
- **Org 14 is counted on either side**: `({org} = 0 OR org_id = {org} OR agent_org_id = {org})` (`LprRepossessionsQuery.php:193`) — forwarder **or** agent. `$orgId` is `auth()->user()->org_id` (`LprRepossessions.php:233`), i.e. the *impersonated* user's org.

Not part of the 509: the `N scans per LPR repo` description line has a separate, viewer-local numerator (`RatioAgainstUniqueScans`, `LprRepossessions.php:440-447`). Out of scope unless that is what was actually read.

## Verify steps (in order; stop at the first that explains a gap)

- **V1 — Pin the UI integer.** Re-open the card while impersonating Brad Webb; record the exact headline, the selected filter, and the description line (`Sep '26: X | Aug '26: Y`, `LprRepossessions.php:301-319`). The description is an independent read of the same array — if it disagrees with the headline, the bug is in the widget, not in ClickHouse.
- **V2 — Confirm the org.** Read `users.org_id` for Brad Webb (Postgres, read-only). If it is not 14, the whole reconciliation is against the wrong org.
- **V3 — Bound the cache age.** With `admin_impersonator` in the session the TTL is **10 seconds** (`app/Filament/Widgets/Dashboard/Concerns/ResolvesDashboardCacheTtl.php:30-32`), passed as a `[fresh, hard]` pair (`LprRepossessions.php:209-214`). Stale-serve is therefore bounded at ~10s and cannot explain a material gap. Record this rather than assuming it.
- **V4 — Lift the SQL from the app, do not retype it.** Expand the card in that same impersonated session: `getCardQueryStatements()` emits `monthlySeriesSql($orgId, now('UTC'))` verbatim (`LprRepossessions.php:632-641`), and `tests/Filament/Widgets/Dashboard/Concerns/CardQuerySqlFidelityTest.php` holds the explainer equal to what executes. Copy that text — zero-drift source. Fallback only if the explainer is unavailable: render the string locally via tinker, then **substitute the database prefixes**, because `clarityDb()`/`zodDb()` read local config (`LprRepossessionsQuery.php:287-305`) and will not emit prod's `clarity.` / `zod.` unless the local env happens to match.
- **V5 — Run it on Cloud, READ-ONLY.** Same SQL, unmodified, `{org} = 14`. Read the row where `bucket = toStartOfMonth(today UTC)`; compare `db_repos` to V1.

## Cloud measure — shape (READ-ONLY, `readonly_user`)

**M1 (primary, authoritative).** The V4 text, byte-for-byte, org 14. Nothing hand-written. Read `bucket`, `deduped`, `db_repos` for the current UTC month row.

**M2 (localiser, only if M1 ≠ V1).** Same `claims`/`deduped` CTEs, month-scoped, decomposed so the divergence has an address:

```
-- L0  raw claim rows        count()             FROM billing_claim_events_v WHERE <card predicates>
-- L1  deduped triples       uniqExact(triple)                 -- must equal M1.deduped
-- L2  headline              uniqExactIf(triple, hsl.created_at >= claim_date - 7)
-- L3  any status-3 hotlist  uniqExactIf(triple, h.id != 0)    -- L2 <= L3 <= L1 must hold
```

**M3 (axis splits, only if M2 is inconclusive).** Re-run L2 three ways: `org_id = 14` only · `agent_org_id = 14` only · both (the card's predicate). A gap on one side only names the defect. Also run once with month-scoped `ts`/`event_date` bounds vs the full 13-month bounds — if those disagree, the bucketing is the bug.

Guardrails: no writes, no `SYSTEM`/`OPTIMIZE`, no settings changes, no `.env` edits, no test run pointed at production. Save the exact SQL and raw output to `docs/investigations/DEV-2737/measure-org14-this-month.sql` and `.../measure-results.md`.

## Match / mismatch / follow-on

**Match** — `M1.db_repos` for the current UTC month equals the V1 integer.
→ AC-3 satisfied by **proof notes**: the hop table, the emitted SQL, the Cloud row, the V3 cache-age note. No code. Wiki edits queue until after approval (CLAUDE.md WRITE rule).

**Benign mismatch** — numbers differ but an evidenced non-defect accounts for it: UI on a different filter, different org/viewer, readings straddling a UTC month roll, or ≤10s cache age. Resolve by re-reading under controlled conditions; record both readings. Still no code.

**Real mismatch** — reproducible under matched conditions. Do **not** patch here. Localise with M2/M3 and write follow-on fix ACs carrying the measured evidence. Candidate causes already documented in-tree, so the follow-on starts from them:

- bucketed-vs-window disagreement (the `this_month` leg never uses `windowSql`);
- the `h.id != 0` sentinel guard on the chained LEFT JOIN — an unmatched hotlist yields `0`, not NULL, and without the guard counts a triple with no status-3 hotlist at all (`LprRepossessionsQuery.php:204-215`);
- `is_internal` frozen at MV refresh from `zod.public_orgs FINAL`; later org renames are not rewritten and api-key moves re-read only an 8-day window (`LprRepossessionsQuery.php:68-74`) — a plausible small, org-specific drift;
- hotlist CDC asymmetry: FINAL on `public_hotlist`, deliberately no FINAL on `public_hotlist_status_log` (`LprRepossessionsQuery.php:60-62`);
- the `claim_date - 7` recovery window (variant A1; the cheaper `repo_date` variant is measured-and-rejected, `LprRepossessionsQuery.php:52-57`).

Existing coverage to extend if a fix ever lands: `tests/Feature/Clickhouse/LprRepossessionsQueryTest.php`, `tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php`, `tests/Unit/Clickhouse/BillingClaimDedupTest.php`. Per the execution policy, run them only when asked.

## Deliverables

1. `reconcile.md` — AC-1 hop table, emitted SQL, V1/V5 side by side, verdict.
2. `measure-org14-this-month.sql` + `measure-results.md` — read-only Cloud evidence.
3. Either proof notes (match) or follow-on fix ACs with evidence (mismatch). Wiki updates queued, not written.
