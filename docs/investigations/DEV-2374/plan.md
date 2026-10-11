# DEV-2374 plan

## Goal
Org Map **historical** paths (yesterday / last_7 / last_15 / last_30 / last_60) render continuous `sc_positions` GPS tracks like KUP. Loads are **chunked by kit groups**. **Live Org Map untouched.** `today` scan-paths overlay stays on `KitScanPathsQuery`.

## Subtasks

### t1 — Backend: historical `scanPaths` → `KitRangePathsQuery` (complex)
- For historical ranges only, resolve scoped kits → `kitsByMapping()`, call `KitRangePathsQuery` with UTC window + KUP-matching dedup (5m / 50m broad).
- Preserve wire shape `{kit_id, color, name, path, trip_date, is_today}`.
- Per-request `countPoints` guard (same class of backstop as KUP); client chunking keeps batches small.
- Optional bbox: keep segments whose path has any point in viewport (parity with prior scan-path behaviour).
- `today` + unknown→today coercion: unchanged (`KitScanPathsQuery`).

### t2 — Frontend: chunk historical fetches by kit groups (simple)
- `fetchScanPaths`: in admin mode, for historical ranges, split kit ids (selection or `drivers` roster) into batches of 25; merge results; abort cancels the batch.
- `buildUrl`: do not overwrite an explicit `kit_ids` param (needed for chunks when a selection is set).

### t3 — Tests (simple, after t1)
- Retarget `last_30` scan-paths tests to `sc_positions` seeds.
- Keep today coercion on `kit_scan_paths`.
- Pin historical continuous path shape (`path` numeric lng/lat pairs).

## Out of scope
Live dots/trails · KUP · DEV-2228 · editing DEV-2222/2341/PR#320
