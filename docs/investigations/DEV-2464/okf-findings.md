# DEV-2464 — what the okf wiki answers (and what it doesn't)

Searched `okf/` (106 pages) at the operator's prompt, before asking any further questions.

## It answers question 1 (the sparkline) — the pattern exists and is documented

`okf/concepts/client-analytics.md:83` — *"Chart widgets extend `ClientChartWidget` /
`ClientClickhouseChartWidget` and use `BatchMonthlySparklineData` for monthly series."*
Also named in `okf/concepts/clickhouse-query-patterns.md:118`,
`okf/concepts/clickhouse-integration.md:38`, and `okf/concepts/lpr-assist.md:156`.

`app/Clickhouse/Queries/Client/BatchMonthlySparklineData.php` (verified present, 4,670 bytes) is
**one grouped query per metric** covering the whole span — `groupByClientAndMonth()`, not a
per-month loop. Its default span is **`$months = 3`**, not 13, and it takes an explicit
`$timezone` argument (default `'UTC'`).

So the "one wide `GROUP BY` month" shape I had listed as option (c) — and flagged as never
authorised — is in fact **the house pattern for sparklines in this repo**, and it is short-span by
default. That materially changes the recommendation. Caveat that stands: it is a *Client Analytics*
(client panel) pattern over `clarity.*` aggregates, and nobody has measured a month-bucketed
`GROUP BY` over THIS funnel's 389M-row join.

`okf/concepts/lpr-assist.md:108` independently confirms the convention at model level:
`byMonth` / `byClientAndMonth` are documented as the "Sparklines / drill-downs" API.

## It bears on question 4 (which hotlist table), without settling it

`okf/concepts/clickhouse-integration.md:106` maps the three layers:
`hotlist` (PG) → `zod.public_hotlist` (CDC mirror) → `clarity.hotlist` (denormalized).
`okf/concepts/data-architecture.md:34-37` shows the intended analytics read path as
`zod.public_*` → `clarity.*` → `clarity.*_aggregate` → `app/Clickhouse/{Models,Queries}` → widgets,
i.e. **`clarity.*` is the documented read side**, and
`clickhouse-integration.md:122` warns *"Don't confuse `clarity.*` aggregates with `zod.public_*`
CDC mirrors."*

Three things push the other way for this specific card, which is why this stays a question:
1. `clarity.hotlist.status` is `Nullable(Int32)`; `zod.public_hotlist.status` is non-null `Int32`.
   A `status = 3` predicate is not identical across the two.
2. `clarity.hotlist` is a `SharedReplacingMergeTree` too, so it needs the same `FINAL` care —
   it is not a free win.
3. Backyard's SQL reads the raw base table. `zod.public_hotlist` is the closer 1:1 port.

**A lead worth checking, not assuming:** `okf/concepts/data-architecture.md:46` says the
`zod.public_*` → `clarity.*` step extracts **`repo_date`** onto `clarity.hotlist`. If that is
usable, the recoveries leg might not need the 389M-row `hotlist_status_log` join at all. But it is
NOT obviously equivalent — `clarity.hotlist.repo_date` derives from `hotlist_meta.repo_date`
(a client-reported date), whereas backyard uses `max(hotlist_status_log.created_at)` for the
transition to `status = 3`. Different fields, different semantics. Verify before believing.

## It confirms the `FINAL` rule I measured — with one documented tension

`okf/concepts/clickhouse-integration.md:120` — *"**Always `FINAL` + filter
`_peerdb_is_deleted = 0`** on replicated `ReplacingMergeTree` tables."*
`okf/concepts/lpr-assist.md` repeats it as an implementer rule.
But `okf/concepts/data-architecture.md:48` also says *"`FINAL` forces dedup (use sparingly —
impacts performance)."*

My measurements refine rather than contradict this: `FINAL` matters on the hotlist leg, and on
`zod.public_hotlist_status_log` it changed nothing while costing 10x. Both statements are true; the
wiki just doesn't carry the per-table numbers. **Queue that for a post-merge wiki update** — do not
write it now (CLAUDE.md forbids writing to `okf/` while work is in flight).

### A structural asymmetry the measurement surfaced

Production engine args, read from `system.tables.engine_full`:

- `zod.public_hotlist` → `SharedReplacingMergeTree(..., _peerdb_version, _peerdb_is_deleted)`
- `zod.public_api_keys` → `SharedReplacingMergeTree(..., _peerdb_version, _peerdb_is_deleted)`
- `zod.public_billing_events` → `SharedReplacingMergeTree(..., _peerdb_version)` — **no
  `_peerdb_is_deleted` argument**

So `billing_events` does **not** collapse soft-deletes at merge time. My earlier "raw == FINAL"
reading for it holds only because no deletes or duplicate versions exist there yet — it is not
structurally immune. A `_peerdb_is_deleted = 0` predicate is still required for correctness.

## Also useful for the resource envelope

`okf/concepts/clickhouse-integration.md:29` — the `clarity` connection sets
`max_bytes_before_external_group_by = 10 GB` so a large `GROUP BY` spills to disk instead of
OOMing. That is a real argument for putting a month-bucketed funnel query on `clarity_clickhouse`
rather than `public_clickhouse`, and it should be checked against the `public_clickhouse` block.

## What okf does NOT answer

- **Question 2 (DEV-2463 sequencing)** — a scheduling call, not domain knowledge.
- **Question 3 (base class)** — no okf page documents `CompanyMetricsOverview`, `DashboardWidget`,
  or the compact-card system at all. All four prep passes grepped for it and found nothing. That is
  a genuine wiki gap for the whole Company Metrics epic, worth landing post-merge.
- **Questions 5 and 6 (titlemax disclosure, 83% of orgs read 0)** — zero hits for `titlemax`,
  `rejectInternalOrgs` or `isExternalRepoSystem` anywhere in `okf/`. These are product decisions
  with Oscar; the wiki has nothing to offer.
- **Question 7 (server-side envelope)** — no page covers `system.query_log` access or the
  `READ ON REMOTE` grant.
