# DEV-2374 — Scope (final)

**Date:** 2026-08-14
**Decider:** Dalton McCleery

## Intent

Fix **Scout Admin Org Map historical paths** so they are comparable to **Kit User Path (KUP)**.
KUP is the accurate surface. **Live Org Map is out of scope** (working as expected).

## In scope

1. **Historical ranges only** (yesterday, last 7 / 15 / 30 / 60, etc.) — switch from fragmented `kit_scan_paths` to continuous GPS (`sc_positions`), same class of fix as DEV-2177 on KUP.
2. **Chunk / batch fetches by groups of kits** so a full-org historical load does not rely on one mega-request (refuse / thin / cap are not the chosen strategy).

## Out of scope

- Live Org Map dots/trails (no DEV-2228 work here)
- Any KUP / `KitUserPathController` behaviour change
- DEV-2341 R2 / R3 (leave)
- Editing comments on DEV-2222 / DEV-2341 / PR #320

## Vocabulary

**Continuous** = GPS track from `clarity.sc_positions` (smooth driving path), not scan-event polylines from `clarity.kit_scan_paths`.

## Residual disposition (2026-08-14)

| Residual | Disposition |
|---|---|
| Bbox into KitRangePathsQuery (strategy B) | **Fixed** — early CH geo on `sc_positions`; count + cache keyed |
| Aggregate fleet point budget | **Fixed** — 1.5M across chunks via `max_points` + `X-Scout-Render-Points` |
| Silent 422 skip | **Fixed** — toast; continue/stop with user signal |
| Serial chunk + pan abort | **Fixed** — waves of 4 + incremental publish + generation guard |
| PG-024 `lower(kit_mapping)` | **Out of scope** — ClickHouse projection in chlocal, not platform |

Decider: Dalton McCleery

## Probe artifacts

One-shot bbox/budget bench command + JSON fixtures removed from the branch after
the decision was recorded (RN4 M4/M5 — prod org id / kit_mapping PII + duplicated
pipeline SQL). Numbers that informed strategy B live in this file and the journal.
