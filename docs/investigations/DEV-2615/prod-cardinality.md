# Production cardinality — DEV-2615

Measured read-only against ClickHouse Cloud on 2026-09-18 (aggregates + EXPLAIN only).

## Representative Scans-list search (ticket failing shape)

`EXPLAIN ESTIMATE` on `clarity.scans_cold` with the ticket's kit list + OCR/date predicates:

| Predicate | parts | rows | marks |
| --- | ---: | ---: | ---: |
| `kit_id` only (as shipped) | 212 | **2,217,023,592** | 270,918 |
| `org_id IN (1208)` + `kit_id` (fixed shape) | 119 | **24,542,538** | 3,004 |

Ratio: ~90× fewer rows estimated with the leading `org_id` prune.

## Moved-kit parity — kit 10044

`SELECT count()` aggregates (no row dump):

| Predicate | count | wall clock (s) |
| --- | ---: | ---: |
| `kit_id` only (ground truth) | 2,141,322 | 7.59 |
| `org_id IN (1254, 665727)` historical | **2,141,322** | **0.56** |
| `org_id IN (665727)` current owner only | 233,517 | 0.22 |

`zod.kit_last_scan` for kit 10044: `org_id` set `{1254, 665727}` (2 rows).

**Verdict:** historical prune is lossless vs kit-only and ~13.5× faster on this COUNT; naive current-owner drops ~89% of rows.
