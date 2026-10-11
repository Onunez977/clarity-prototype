# DEV-2464 q13 — `Cache::flexible` vs `DashboardWidget`'s TTL ladder

**Answered from shipped precedent.** DEV-2463 hit this exact conflict and resolved it in
`app/Filament/Widgets/Dashboard/UniqueLprCaseClaims.php`. The answer is not the either/or I put to
the operator — it is "use `Cache::flexible`, and fix the four things that makes necessary."

## I had the hazard backwards

I framed the okf warning as: *use `Cache::flexible` and accept that an impersonating admin sees a
**staler** value than on neighbouring cards.*

The real hazard is the opposite. `DashboardWidget::getCacheTTL()` collapses to **10 seconds** under
`admin_impersonator`. DEV-2463's docblock:

> `getCacheTTL()` collapses to 10s under `admin_impersonator` — shorter than this query's measured
> contended p99 (9.3s), so a re-render could start the next ~40M-row scan before the previous
> returned. This floors it.

So the danger is a TTL **shorter than the query's own runtime**, which turns every impersonated
re-render into a fresh full scan — a stampede, not staleness. `MIN_TTL_SECONDS = 60` floors it.

## What DEV-2463 actually ships — four parts, all needed

1. **`Cache::flexible([3600, 86400])`**, exactly as the ticket mandates. The ticket's instruction
   stands.
2. **`getCacheTTL()` override that floors the TTL at 60s**, so the impersonation collapse cannot
   undercut the query's own p99.
3. **A cold-path herd guard, `heldFlexible()`.** `Cache::flexible` locks **only its stale-refresh
   branch** (`Repository::flexible()`); a **total** miss is a bare `tap(value($callback), …)` with no
   lock. So a deploy or `cache:clear` admits one full scan **per entitled org AND per concurrent
   tab**. The guard reads the cache first and locks only on a total miss, so it can never mask a
   value that is present. Two details worth not re-deriving:
   - It checks **both** keys — `$key` and `illuminate:cache:flexible:created:{$key}` — because
     `flexible()`'s own miss test is `in_array(null, [$value, $created], true)`. The two carry no
     hash tag, so on the prod Redis **cluster** they land in different slots, can be evicted
     independently, and `putMany()` is not atomic across slots. Testing only `$key` let a full scan
     through.
   - `COLD_BLOCK_SECONDS` (12) must **exceed** the contended p99, or every waiter times out and
     computes anyway — the docblock records that this is how their first attempt made things worse.
     `COLD_LOCK_SECONDS` (90) must exceed `CLICKHOUSE_TIMEOUT_QUERY` (60) or a slow holder loses the
     mutex mid-query. `REFRESH_LOCK_SECONDS` (90) is passed to `flexible()` so its own refresh lock
     expires — it defaults to no expiry, so a worker killed mid-refresh would wedge the key for 24h.
4. **The window's identity in the cache key** (`'monthly-series:'.$through->format('Y-m')`).
   Keyed only on the org, a series computed at 23:55 on the last day of a UTC month is still
   "fresh" minutes into the next one, the new bucket is absent, and `?? 0` fabricates a zero —
   indistinguishable from the legitimate zero this card treats as normal.

## Two things DEV-2464 must NOT simply inherit

### The constants were tuned for a query 16x lighter
DEV-2463's `COLD_BLOCK_SECONDS = 12` and `MIN_TTL_SECONDS = 60` are calibrated against a **~40M
read_rows** query with a measured contended p99 of 9.3s. DEV-2464's chosen A1 shape measures
**664,270,998 read_rows / 15.04 GB / 2.50 GB peak memory** — roughly **16x the rows**.

Copying those numbers would very likely under-size `COLD_BLOCK_SECONDS`, and per DEV-2463's own
note that is the failure mode where the guard makes things *worse*: every waiter times out and
computes anyway, so N tabs become N x 2.5 GB. **Measure this card's own contended p99 and re-derive
both constants.** Do not inherit them.

### The machinery is private, and DEV-2464 is the second of three users
`heldFlexible()`, `flexibleCreatedKey()` and `MIN_TTL_SECONDS` are **private** to
`UniqueLprCaseClaims`; `flexibleRefreshLock()` is protected. DEV-2464 needs all of it, and
DEV-2463's own docblock says *"Any sibling ticket reusing this query (DEV-2464, DEV-2465) inherits
the same seam."*

**Recommendation: extract to a shared concern** (e.g.
`app/Filament/Widgets/Dashboard/Concerns/HeldFlexibleCache.php`) rather than write a second copy.
Three users is past the point where duplication is cheaper, and the Redis-cluster two-key subtlety
is exactly the kind of detail that rots in a copy. The constants stay per-card, since they are
calibrated per query. This is a code-organisation call, not a product one — flagged for the
operator to veto, otherwise taken as the plan's approach.

## Already done for us by #394, verified
`DashboardWidget::getStatColor()` line 310 already has
`'lpr-repossessions', 'unique-scans-to-repo', 'unique-scans-to-lpr-repossession' => 'info'`.
`'info'` is a registered colour. `recovery-counts` is also `'info'` but sits at index 1, with
`unique-lpr-case-claims` between them, so the two are not adjacent in the grid. No change needed,
and per DEV-2463's note an unregistered colour (their earlier `'purple'`) renders untinted with no
error at all — so this arm is worth leaving alone.

## The seam DEV-2464 inherits whether it likes it or not
DEV-2463 keeps **two calendars, deliberately**: the month axis is UTC (the AC reproduces a
UTC-calendar-month figure) while `today`/`last_7d` stay on the **viewer's** local day, inherited
from `resolveCardRange()`. The consequence is documented and real: at 20:00 America/Chicago on
Aug 31 (= 01:00 UTC Sep 1), "Today" counts the local Aug-31 day whose claims sit in UTC August,
while "This Month" is UTC September and near zero — so toggling the filter can make the headline
**fall**.

DEV-2464 has the same UTC-calendar-month AC, so it inherits this. It must also override
**`getGraphLabels()`, `getHighlightedMonthsFromFilter()` and `getDescription()`** to move the label
axis to UTC with the data axis — `okf/concepts/company-metrics-dashboard.md` checklist item 6 names
mismatching those two axes as a real defect class, and DEV-2463 already carries all three overrides.
