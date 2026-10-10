# DEV-2312 — Plan

**Route:** bug · **Base:** main · **Gate:** CLEAN

## Root cause (confirmed)
In ClickHouse mode the Hits table query uses `App\Clickhouse\Models\Hit`, so `Table::getModel()` → the CH Hit class → `TableMapComponent` is mounted with `model = App\Clickhouse\Models\Hit`, `relationship = 'scans'`. `formatRecords()` hops `$record->scans`; the CH Hit model has no `scans` relationship → `RelationNotFoundException` → 500 on `/app/hits`.

## Subtask t1 (single node, no fan-out)
**Make `TableMapComponent` ClickHouse-safe + give CH hits map-pin parity.**

1. `app/Livewire/TableMapComponent.php` — in `formatRecords()`, resolve `$this->relationship` only when the resolved record actually defines it; otherwise map from the record itself. Removes the exception; also hardens the bulk-select path.
2. `app/Clickhouse/Models/Hit.php` — add read-only `Attribute` accessors `us_state` (← `scan_us_state`), `vehicleImage` (← `scan_img(scan_guid, Plate)`), `vehicle` (← year/make/model). `ocr`/`address` are raw columns; `latitude`/`longitude` accessors already exist. So CH pins carry the same label/image/vehicle as the Postgres path.
3. `tests/Feature/Livewire/TableMapComponentTest.php` — add a case binding `model = App\Clickhouse\Models\Hit` + `relationship = 'scans'`; assert `loadRecordsForMap` does **not** throw and dispatches `filament-maps-widget/updateMapData` with mapped pins. Keep existing Scan/default cases green.

**Test filter:** `TableMapComponentTest` · **UI impact:** yes · **Blast radius:** none · **Complexity:** complex (2 source files, tightly coupled).

## Acceptance criteria
1. CH admin mode: "Show Cluster on Map" opens the Map with the cluster's pins, no 500.
2. Pins show label + vehicle image/details (parity with Postgres).
3. No regression: Scans cluster map + Postgres hits map still work.

## Out of scope
`/geocode/reverse` (502) and `/nearest-given-address` (500) init errors — separate follow-up ticket (Dalton).
