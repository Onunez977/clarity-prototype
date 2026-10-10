# DEV-2546 plan (as-built, restored on resume)

Original `plan.json` was never committed. This records what actually shipped through RN5.

## Decision

Four filter-reactive metric cards above `/app/hits`, counting over the **same** filtered query the table uses.

## Subtask

**t1 — Hit metric cards (complex, merged)**

- `HasHitMetricCards` + `HitMetricCards` widget on `ListHits`
- One grouped aggregate (not four queries)
- No FINAL; CH total = `uniqExact(id)`; PG aggregate capped at 5s `statement_timeout`
- Cache TTL = repo ladder (10s / 3s under impersonation)
- Exactly four cards (fifth "Other Hits" removed per operator)

## Explicit non-goals

- Dashboard / `hit_stats_daily` widgets
- Changing Hits filters
- Sibling bare `count()` sites elsewhere
- Fixing the pre-existing `clickhouse:hits` vs `clickhouse-hits` Pennant slug bug
