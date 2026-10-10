# DEV-2182 — Implementation Plan

**Route:** bug · **Base:** main · **Sizing:** 1 subtask, 2 files (controller + test), single concern — no split.

## t1 — Org bird's-eye lists all active org kits (drop the `kit_settings`-row filter)

**Files:** `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`, `tests/Feature/Http/Controllers/Api/ClarityScoutBirdsEyeControllerTest.php`

**Change:** Rewrite `orgScoutKits(int $orgId)`:
- Query `Kit::query()->forOrg($orgId)` with `leftJoin('kit_metadata', kits.id = kit_metadata.kit_id)->whereNull('kit_metadata.deleted_at')->select('kits.*')->orderBy('name')->get()` — mirrors `Kit::getKits(withDeleted:false)`'s soft-delete exclusion **inline** (not calling `getKits`, which selects only `id,name` and is hourly-cached; the controller needs `kit_mapping` + full columns, uncached).
- Keep the separate keyed `KitSettings` lookup (cross-connection: `Kit`=zod, `kit_settings`=platform — a join would silently return `[]`) and `setRelation('settings', ...)` per kit.
- **Drop** `->filter(fn ($kit) => $settings->has($kit->id))` so non-enrolled kits are retained (settings null when absent).
- Reword the `drivers()` and `orgScoutKits()` docblocks to "all non-deleted org kits".

**Why:** Single choke-point — `scopedKits()` (used by `driverPositions`/`driverPaths`/`scanPaths`/`hits`/`stats`/`recentHits`) and `drivers()` all funnel through `orgScoutKits()`, so this one method fixes every bird's-eye endpoint at once. Satisfies all three ACs: all active kits listed; a kit renders without a `user_id` (null-safe consumers); no manual `kit_settings` insert needed.

**TDD:** The existing test pins the OLD settings-required exclusion — rewrite it first (red), then implement to green:
- a kit with **no** `kit_settings` row appears, with `driver_name`/`vehicle_type` null;
- a kit with `kit_metadata.deleted_at` set is **excluded**;
- cross-org kits never leak;
- a non-enrolled kit's `kit_id` is honored by a `scopedKits`-derived endpoint (`hits`/`stats` `kit_ids` filter).

**No blast-radius** (no migrations/billing/auth/CH-purge). **No schema change. No frontend change** (already null-safe). **No caching** on the new query (map must reflect kit changes live).

## Verify-phase note
Perf: widening from enrolled-only to all-active kits enlarges the `kit_id` IN-list on the CH stats/hits/scanPaths queries. Bounded (tens of kits/org, org-scoped + windowed) and inherent to the AC — observe on the largest-kit org during QA, not a code gate.
