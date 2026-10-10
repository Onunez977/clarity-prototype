# DEV-2597 Plan

Stacked on prior companion branch (admin `recentHits` `limit(20)` already removed).

## t1 — Truncation UI + loading (simple)
- Show `rangeHitsCapped` (“Showing the 100 most recent hits.”) on **admin** Org Map, not only driver.
- Show Live Hits **loading / refreshing** from `dashboard` store flags.
- Do **not** change `MAX_MAP_HITS` or reintroduce a list cap.

## t2 — Measure (depends on t1)
- Cold + warm wall times for `recentHits` + `hits` at `last_7` / `last_15` / `last_30`.
- Targets: cold p95 today/`last_7` < 1s, `last_15` < 2s, `last_30` < 3s; warm < 300ms.
- Record aggregates only; `no-go` stops for re-plan.

## Excluded
- `limit(20)` removal → companion admin-list-cap ticket
- Changing the value 100
- Historical GPS caps
