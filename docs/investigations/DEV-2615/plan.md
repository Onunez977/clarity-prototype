# DEV-2615 Plan

## Goal
Restore `org_id` pruning on the Scans ClickHouse list using historical orgs from `zod.kit_last_scan`, without reintroducing current-owner row loss.

## Subtasks

### t1 — Restore prune (complex, single node)
1. `Scan::scopeForOrgs(array $orgIds)` via `whereIntegerInRaw('org_id', ...)`.
2. `Kit::kitScanOrgSetCacheKey` + `Kit::scanOrgIdsForPrune($orgId, $withShared)`:
   - kit ids from `Kit::getKits`
   - `DISTINCT org_id` from `zod.kit_last_scan` where `kit_id IN (...)`
   - union with `org_id` values on those kit models (covers never-scanned kits)
   - cache until `forgetCachedKits`
3. `ListScans::buildClickhouseQuery`: `->forOrgs(...)->forKits(...)`; rewrite the docblock that currently forbids org predicates.
4. Tests: invert `ClickhouseScanQueryShapeTest` org_id absence; add real-id + moved-kit + cache invalidation coverage.

## Perf validation
Approach does **not** defer perf: prune is the fix. Ticket EXPLAIN (human-approved Q2): kit_id-only ~2.21B rows vs org predicate ~24.5M. Verdict: **go**.

## Out of scope
DEV-2614 Hits; date floor; timeout config change; main backport; okf until post-merge.
