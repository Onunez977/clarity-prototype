# DEV-2461 — Implementation plan

**Card:** Staging Live Hit Leaderboard · **Mockup slot:** row 3 col 2 (slot 10 of 16)
**Base:** `main` · **Branch:** `claude/DEV-2461` · **PR:** #385

> **Plan provenance.** Authored by the driver in-session at Opus, the same tier
> `TICKET_PLAN_ARBITER_MODEL` specifies. The dedicated arbiter sub-agent could not be
> dispatched — three consecutive HTTP 529 Overloaded responses from the API. Recorded as a
> deviation rather than silently downgrading to Sonnet.

## DAG

```
t1 (query)  ─┐
             ├─> t3 (widget) ──> t4 (registration + card-contract tests)
t2 (gate)   ─┘
```

`t1` and `t2` run in parallel — disjoint files, no shared symbols. Max concurrency 2.

| Node | Title | Files | Complexity | Blast radius |
| --- | --- | --- | --- | --- |
| t1 | `StagingLiveHitLeaderQuery` — batched kit leaderboard | 2 | complex | — |
| t2 | Pro entitlement — `FeatureEnum::COMPANY_METRICS` + Pennant + tiers | 3 | complex | **pro-entitlement** |
| t3 | `StagingLiveHitLeader` widget | 2 | complex | — |
| t4 | Card registration + both card-contract tests | 3 | complex | — |

**t2 carries a blast-radius tag** (Pro entitlement governs what paying orgs see). Its human
approval is on record: Dalton answered Q1 at the Phase 2 gate choosing the epic-wide
`COMPANY_METRICS` flag, which names these exact three files and their tier placement.

## The three decisions this plan encodes

1. **Pro gate (Q1).** New `FeatureEnum::COMPANY_METRICS = 'platform_repo_pro_company_metrics'`,
   `Feature::define()`d with the `SHARED_KITS`/`MISSED_OPPORTUNITY` shape and **no**
   `config('app.experimental.*')` wrapper, added to the `CLARITY_PRO` and `CLARITY_PRO_PLUS`
   tier lists. Tier membership is what grants it with no Backyard write, because
   `Org::isProEnabled()` checks an individual `org_meta` flag first and then falls back to
   `proTier()->features()`.
2. **Colour (Q2).** Hardcoded `->color('warning')` in the card's own `toStat()`.
   `DashboardWidget::getStatColor()` is **not** touched — it is unreachable for a
   `ChartWidget`-direct card, so an arm there would be dead code.
3. **Sparkline (Q3).** Fed from the cross-card cache key
   `staging-live-hits:unique:{Y-m}:org={orgId}` that `SharedHitStatsData` already writes from
   `HitPlateStatsBatchQuery`, so this card and the sibling Staging Live Hits card agree by
   construction instead of diverging by 3.5%–68.8%.

## The hazard that shaped t3

Q3's answer says read the sibling card's cached monthly buckets. But **this card is slot 10 and
`staging-live-hits` is slot 15**, so `CompanyMetricsOverview::getStats()` renders *this* card
first — and on a cold cache those keys are still empty. Reading them naively would draw a
flat-zero sparkline.

So the card must be able to **fill** them as well as read them, using the *same*
`HitPlateStatsBatchQuery::handle()` call, the *same* key shape, and the *same* `getCacheTTL(1)`
past-month TTL. Whichever card renders first populates them; the other reads them. The deduped
monthly series therefore stays at exactly **one** ClickHouse call shared across both cards —
which is precisely what the ticket's cross-class cache-**key** rule exists to achieve, and why a
`static::$memo` array (copied per using-class — the DEV-2406 hazard) would not work.

## Non-obvious constraints the workers must honour

- **Read the batched reference at `3aef4158`, not the branch tip.** `claude/DEV-2409`'s tip
  (`db605df1`) is an operator-directed MVP cut that reverted batching back to the 13-query shape.
- **`uniqExactIf(ocr, hit_type='S')`, never a bare `count()`.** `hits_cold`'s mutable
  `hit_created_at` is in the sort key, so duplicates survive `FINAL` and counts run ~30% high.
- **No join to `clarity.kits`.** `hits_cold` carries both `kit_id` and `kit_name`.
- **`static::clarityDb()`**, never a hardcoded `clarity.` prefix. Copy `HitPlateStatsBatchQuery`,
  not `RecoveryLeaderQuery`.
- **Key the leaderboard by `kit_id`, never by name** (DEV-2430's non-unique-name hazard).
- **Call `handleMonthly()` directly**, bypassing `ClickhouseQuery::query()` — it swallows
  `Throwable` and would persist a real outage as an empty leaderboard. Pair with a short-TTL
  negative-cache failure marker.
- **`canViewCardData()` must be total with no authenticated user** — `Dashboard::cardWidgets()`
  reaches it unauthenticated, so guard the `auth()` dereference.
- **`DB::listen` cannot count ClickHouse queries** (`DashboardCardMetricsTest.php:838`). AC-4's
  round-trip proof uses the cache-seed technique; precedent `HotlistCountTest.php:196-224`.
- **Do not edit `test_all_four_hit_cards_are_contiguous()`.** Slot 10 sits before the hit block
  (slots 13–16), so their array adjacency is preserved. Verify, don't touch.
- **`composer format` (Pint) before finishing.** Style is never a review finding.

## Sizing gate

10 files total: **6 production, 4 test**. Production files are exactly at the 6-file limit, not
over, and the work is one concern — ship one gated card. The only defensible split would be
lifting the `COMPANY_METRICS` flag into its own epic-infrastructure ticket so all nine sibling
cards could depend on it; Dalton was offered that trade at the gate and chose to define it here.
Disclosed rather than decided silently.

## Excluded sites

12 recorded in `plan.json`, the substantive ones being the nine pre-existing hardcoded-`clarity.`
prefix offenders (out of scope by the ticket's own framing — named as offenders *not to copy*,
not to fix) and `DashboardWidget::getStatColor()` (excluded by Q2's answer).
