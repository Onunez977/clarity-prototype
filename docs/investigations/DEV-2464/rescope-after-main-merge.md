# DEV-2464 — re-scope after merging origin/main (2026-09-09)

Merged `origin/main` at `539124bc`. Four commits landed while this ticket was held, and they
change it materially. **Our code diff against main is now empty** — DEV-2463 cherry-picked both
infra fixes byte-identically, so this branch carries artifacts only.

| commit | effect on DEV-2464 |
| --- | --- |
| `012030f3` DEV-2463 Unique LPR Case Claims (#392) | **The unblock condition.** Also carried both shared-infra fixes from `47612ab1`. |
| `9569ae76` DEV-2409 stub the five remaining cards (#394) | **Biggest change — see below.** |
| `00a89c51` DEV-2467 Unique Scan Counts (#393) | sibling card |
| `ad29de3b` DEV-2466 spike (#391) | produced the `X-ClickHouse-Summary` technique — see correction 1 |

## The remaining scope is much smaller than prepped

`#394` already shipped **`app/Filament/Widgets/Dashboard/LprRepossessions.php`** as a UI stub and
registered `'lpr-repossessions' => LprRepossessions::class` in `cards()` at index 3. The registry
tests were updated with it (11 → 16 cards).

So most of what the ticket's "Card contract" section asks for is **already done**:
registration at the mockup slot, the registry-test updates and the `getStatColor()` arm. The stub's
own docblock states the remaining job: *"The card ticket replaces the data methods in place and
removes this note."*

> **CORRECTION (2026-09-09, caught by the Phase 3 plan arbiter).** An earlier version of this note
> also claimed #394 shipped "the Pro-gate shape". **It did not, and the opposite is true.**
> `LprRepossessions.php` has **no** `canViewCardData()` — it inherits `RendersDashboardStat`'s
> default, which returns `true` for an authenticated but non-entitled ADMIN. #394's own commit
> message is explicit: *"Drop the `canViewCardData()` override from the five stubs ... A stub has no
> data to protect; **the Pro gate arrives with the query in each card's ticket.**"*
> So the AC "non-Pro orgs do not see the value" is **entirely unimplemented** and is this ticket's
> job. Adding it also requires adding `'lpr-repossessions'` to `$expectedGated` in
> `tests/Filament/Widgets/Dashboard/Concerns/DashboardCardVisibilityTest.php` (currently four keys,
> deliberately omitting this card) — a shared cross-card test whose docblock insists the allowlist
> stay explicit because *"gating a card is a deliberate, reviewed act and never an accident."*

**DEV-2464 is now: replace `getModelQueryForDates()`, `getMonthlyData()` and `currentPeriodTotal()`
in the existing stub with the real ClickHouse query.** Nothing else.

### That also answers open question q5 (base class), against my recommendation

I recommended bypassing `DashboardWidget`. The shipped stub **extends** it and overrides exactly the
two methods that reach `cache()->remember()`. That is a better answer than either option I offered,
because overriding `getMonthlyData()` *is* the seam that removes the 13-month loop — you keep the
base class's rendering, TTL ladder and colour handling, and replace only the query paths. **q5 is
closed by shipped code.** Note `getMonthlyData()` must return **13 elements** — `getData()` zips it
positionally against `getGraphLabels()`.

## Three corrections to what I reported earlier

### 1. The server-side envelope IS obtainable — q12 was wrong
I reported `read_rows`/`read_bytes`/peak memory as unobtainable because `readonly_user` lacks
`READ ON REMOTE` for `clusterAllReplicas(system.query_log)`. DEV-2466 found the answer:
**the `X-ClickHouse-Summary` response header carries `read_rows`, `read_bytes` and `memory_usage`
with no grant needed.** `okf/concepts/billing-events.md` uses it throughout. **q12 is closed**, and
the ticket's "restate the unmeasured cost in the Build Record" AC should now restate a *measured*
figure instead.

### 2. My "+7%" sparkline figure was wall-clock noise; the real answer is that a wider window is free
I measured 1-month median 5.04s vs 3-month 5.40s and called it +7%. Measured server-side instead,
`okf/concepts/billing-events.md` reports **40,046,830 `read_rows` / 1.83 GB for a one-month window —
and the same figure for 3-month and 13-month.** Nothing prunes, so the window genuinely does not
change the read at all. My +7% was contention, not cost. The conclusion (use one grouped query,
3-month span) survives and is in fact stronger than I argued.

Same page, same lesson I recorded from my own mistake: *"Wall clock is dominated by ClickHouse Cloud
contention, not by the query"* — 0.31s median quiet vs 3.6s median / 9.3s max under contention.

### 3. My ported SQL probably does THREE full scans, not one
`okf/concepts/billing-events.md`: *"Each additional reference to a CTE over this table is another
full scan: three references measured 119,848,324 `read_rows` / 5.50 GB versus 39,949,441 / 1.83 GB
for a single-pass — exactly 3.0×."*

`measure-funnel-total.sql` references `deduped` **three times** (`pairs`, `floor_date`, and the final
`FROM`). Prep candidate 1 raised CTE re-evaluation and I dismissed it as single-reference for the
compact card; that was wrong — `orgDbReposSql()` references it three times. **The port needs a
single-pass rewrite before it ships**, and the ~5s I measured is likely 3 scans where 1 would do.

## Two things my port is missing outright

- **The `insightlpr` internal-org exclusion.** The canonical form is
  `position(lower(replaceRegexpAll(coalesce(name,''), '[^a-zA-Z0-9]', '')), 'insightlpr') = 0`,
  joined through `orgs`. My measurement omitted it and still matched `deduped` 11,975 exactly —
  because the 32 rows it removes are all failures, already dropped by `success`. Correct by luck at
  the deduped stage, wrong at the all-claims stage. Also note it **fails open**: `FINAL` on `orgs`
  *does* drop tombstones, so a soft-deleted internal org would fall out of the join, `coalesce()`
  would yield `''`, and its claims would silently re-enter.
- **`uniqExactIf` over the triple** is the canonical dedup shape, not my `GROUP BY` + `count()`.
  Use `uniqExact`, never `uniq` (HyperLogLog, approximate, and these figures are reconciled against
  a report).

## A NEW tension the ticket does not know about

The ticket mandates **one `Cache::flexible([3600, 86400])` query per resolved window**.
`okf/concepts/company-metrics-dashboard.md` documents the `DashboardWidget::getCacheTTL($index)`
ladder — 1 year for sparkline buckets, 10 minutes for the headline, **10 seconds while
impersonating** — and warns: *"A card that caches through its own path (e.g. `Cache::flexible`)
**bypasses this ladder entirely**, including the impersonation collapse to 10 s."*

So the ticket's caching instruction and the card family's caching contract point in different
directions. This needs a decision before implementation.

## And a blast-radius decision this card cannot dodge

`getStats()` is a plain loop with **no per-card `try`/`catch`**, so a card that lets an exception
escape returns a **500 for all sixteen cards**. In production, `ClickhouseQuery::query()` swallows to
`0`; `unique-lpr-case-claims` deliberately propagates instead, because *"for a card where zero is a
normal reading, swallowing is worse than failing."*

**That is exactly this card**: 83% of orgs legitimately read 0. So the swallow-vs-propagate choice is
forced, and DEV-2463 has already set the precedent (propagate). Worth matching it deliberately rather
than inheriting `ClickhouseQuery`'s swallow by accident.

## Stale doc noticed — queued, not written

`okf/concepts/company-metrics-dashboard.md`'s `cards()` table lists **10** cards and omits all five
stubs from `#394`, including `lpr-repossessions`. The page is timestamped 13:07 and `#394` landed at
13:04 the same day. Main's real registry has **16**. Per CLAUDE.md's WRITE rule this is queued for
the post-approval pass, not corrected now.
