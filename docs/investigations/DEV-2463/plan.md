# DEV-2463 — implementation plan

**Route:** feature · **Base:** `main` · **Sizing gate:** pass, no split
**Perf gate:** `go` (measured — see `perf-measurement.md`)

## Operator decisions this plan encodes

| # | decision |
| --- | --- |
| q1 | The card's number is **`deduped`** (11,975 for Jul 2026) — the billed count. |
| q6 | **EXTEND `DashboardWidget`** — overriding the driver's bypass recommendation. So the ticket's literal `getStatColor()` instruction applies, and `getMonthlyData()` must be overridden to keep the one-query AC. |
| q7 | **Show `0`** — no N/A, no hiding. Decided after measuring that only 14% of *entitled* orgs read zero on a month window (4 of 29), against the ticket's assumed 83%. |
| q8 | **Cherry-pick DEV-2464 `cc34c05c`'s exact diff** for the two shared-infra files. |

## t0 — driver pre-step: carry the two infra fixes (pre-approved at q8)

`database/schema/clickhouse-schema.sql` (+`zod.public_billing_events`) and
`tests/Concerns/RequiresClickhouse.php` (+4 `zod.public_*` truncations), taken as DEV-2464's
**exact** diff. These are prerequisites, not cleanup: without the schema line CI cannot create
the table t1's query reads, so t1's feature test cannot run there at all.

## t1 — `UniqueLprCaseClaimsQuery` (complex, no deps)

Port `claimsCte()` + `deduped` from backyard `RepoReport.php` on `origin/deploy`, reusing the
**already production-validated SQL** in `measure-claims-card.sql` verbatim. Nine hard
requirements, every one either measured or read from source — see `plan.json` for the full list.
The four that are easiest to get wrong:

1. **Single pass.** Three references to the `claims` CTE scan `billing_events` three times —
   119.8M `read_rows` against 39.9M. Use `count()` / `countIf()` / `uniqExactIf()`.
2. **The 3-column dedup key**, not the class docblock's stale 2-column prose.
3. **`insightlpr` excluded by an explicit SQL predicate.** It is a no-op against today's data —
   only org 1 has ever been a case-claim forwarder, with 0 successful — which is precisely why
   it must be explicit and tested rather than left to emerge from the data.
4. **Do not extend `ClickhouseQuery`.** Its `query()` swallows `Throwable` to `[]`, and a
   genuine `0` is now a known-normal reading here (12 of 29 entitled orgs on the Today filter),
   so a failure must be distinguishable from it.

TDD: the feature test pins the **rules**, not the production number — 15,128 is not reproducible
in CI.

## t2 — the card (complex, depends on t1)

Three consequences of the q6 decision to inherit:

- **(a)** Add the `'unique-lpr-case-claims'` arm to `getStatColor()`. **Confirmed to be the sole
  definition at `DashboardWidget:299`** — the grep backstop's claim that it also lives at
  `RendersDashboardStat:165` was wrong (that line is `lockedStat`).
- **(b)** Override `getMonthlyData()` to return the same 13-element array from **one**
  `Cache::flexible([3600, 86400])` call on t1's grouped query, preserving the
  `$this->monthlyDataMemo` contract and the `formatMonthlyData()` hook so `getCardTotal()`,
  `chartSeries()` and `getDescription()` keep working untouched.
- **(c)** `getCardTotal()` routes `today` / `last_7d` through `currentPeriodTotal()` — a
  **different** path. It needs its own single-window one-query implementation, or the one-query
  AC is only half met.

Register at `cards()` **index 2** (after `recovery-counts`, before `staging-live-hit-leader`) —
mockup-relative, not appended.

`test_filter` is deliberately `null`: this node edits three shared test files and the shared
`DashboardWidget` concern, so it runs the **full suite**.

## Execution: sequential in-repo, not worktree fan-out

Flagged rather than taken silently. The DAG is strictly serial — t2 consumes t1's query API — so
there is no parallelism to win, and two worktree bootstraps (vendor copy + `migrate:fresh` each)
would be pure cost. Both nodes run in order on the ticket branch in `REPO_PATH` against the
standing Phase-3.5 environment, exactly as the single-node fast path does.

## ⚠ The risk I found while planning, which prep did not catch

**`getMonthlyData()` builds its month boundaries from the *user's* timezone**
(`auth()->user()->timezone ?? 'UTC'`, line 218). This metric's port rule 2 requires a **UTC**
calendar month to reproduce Brek's figures.

So the override **must** bucket by UTC — which means this card's monthly buckets are defined
differently from every other card's on the same dashboard. That divergence is required by the
AC and must be documented in the class docblock and the Build Record. Quietly inheriting the
user-tz boundary would shift the month by 5–6 hours for a Chicago user and silently break the
cross-check.

Five further risks (epoch derivation, `Cache::flexible` being new to the repo, the heading
collision with "Total Repossessions", the grid-alignment docblock claim, the contention tail)
are recorded in `plan.json`.
