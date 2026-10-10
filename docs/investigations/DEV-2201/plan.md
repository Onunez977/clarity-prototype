# DEV-2201 — Plan: Zone Manager KML bulk import

**Route:** feature · **Base:** main · **Approach:** single implementation node (tightly-coupled, mostly-serial; interfaces must co-evolve).

## What ships

A custom Filament toolbar Action on the Zone Manager (`ZoneResource`) that uploads a `.kml`, parses one GeoJSON polygon (with holes) per `<Placemark>`, and creates one Scout zone per valid polygon through the **existing** `Zone::rebuildGeometryFromDefinition()` pipeline — so imported zones are identical to hand-drawn ones.

- Small uploads → import **synchronously** with an immediate "N created / M skipped" notification.
- Large uploads (> ~256 KB) → persist the file, **dispatch a queued job**, and email the user the result when it finishes.

## Grooming decisions baked in (Dalton, 2026-07-20)

- `.kml`-only (no `.kmz`) · allow duplicate names · **support polygon holes** (`<innerBoundaryIs>` → GeoJSON interior rings) · **queue large files**.
- Fixed defaults (color=`Zone::DEFAULT_COLOR`, no-scan=false, no driver) · skip `ZoneBroadcaster` (no-op for driverless) · one batched `Analytics::track(ZoneImport)`.

## The one load-bearing correctness rule (all 3 prep passes flagged it)

`rebuildGeometryFromDefinition()` only guards the `geom` column — it never blocks `Zone::create()`. The real "polygon-or-ZIP required" rule lives **only** in the `ManageZones` Livewire form, which an Action does not inherit. **AC5 requires the importer to validate the outer ring BEFORE `create()`**, or it silently persists empty zones.

## Files (single node `t1`, complexity=complex, no blast-radius)

| File | Role |
|---|---|
| `app/Enums/ScoutAnalyticsEvent.php` | + `ZoneImport` case |
| `app/Services/Zones/KmlZoneParser.php` | KML → GeoJSON polygons (outer + holes), altitude strip, ring closure/validity, namespace-aware |
| `app/Services/Zones/ZoneKmlImporter.php` | shared executor: parse + per-placemark txn create + rebuild + tally + one analytics event (called by BOTH paths) |
| `app/Jobs/ImportZonesFromKml.php` | `ShouldQueue`; reads stored file → importer → emails result → cleanup; `failed()` notifies |
| `app/Notifications/ZoneImportCompleted.php` | queued mail: "N created / M skipped" (+ failure) |
| `app/Filament/Actions/ZoneKmlImportAction.php` | FileUpload + sync/queue threshold + immediate (sync) / "started" (queue) notification |
| `app/Filament/Resources/ZoneResource.php` | wire the action into `toolbarActions()`, gated `static::canCreate()` |
| `tests/Services/Zones/KmlZoneParserTest.php` | parser incl. a holed polygon, unclosed ring, `<Point>`, altitude, fallback name |
| `tests/Jobs/ImportZonesFromKmlTest.php` | queued path: creates zones, emails result, cleans up |
| `tests/Filament/Resources/ZoneResourceTest.php` | gating parity, sync happy-path, partial-skip counts, org isolation, large-file dispatch (`Bus::fake`) |

## Sizing note

Above the 6-file heuristic, but **not split** — one non-decomposable feature; the count is driven by the holes + queue scope and test files. Built as one node so the parser/importer/job/action interfaces stay coherent.

## Assumptions to confirm at QA

- Async feedback = **email** (not the Filament bell — panel-wide bell isn't enabled today).
- Queue threshold = file size > ~256 KB (named constant; tune if needed).
- `acceptedFileTypes()` MIME set + `mimes:kml` — verify empirically against a real `.kml` (finfo often sniffs KML as `text/xml`).
