# Low-hanging fruit: research and brainstorm

Research only. No screen was built. This is what the copied Clarity tree (`insight-lpr/platform` `main`, vendored here) actually stores, and a phased way to show a repossession manager the assignments most likely to recover soon.

The assignment in Clarity is a **hotlist row**, not a separate "order" model. RDN is one integration that fills that row.

## 1. What already exists

### Assignments (RDN orders)

| What | Where |
| --- | --- |
| Assignment | `public.hotlist` via `app/Models/Hotlist.php` (`zod` connection). Plate, state, VIN, year/make/model, `status`, `order_type`, `is_staged`, `org_id`, `client_org_id`, `lienholder_org_id`, `integration_id`, `remote_order_id`, `lookback_days`. |
| Status | `app/Enums/HotlistStatusEnum.php`. Open = `1`. Hold = `2`. Repossessed = `3`. Closed / closed-positive / invalid are terminal. |
| Order dates and money | `public.hotlist_meta` via `app/Models/HotlistMeta.php`: `order_date`, `repo_date`, `close_date`, `monthly_payments`, `balance_on_account`, `amount_past_due`, `additional_info` (shown as the reason). |
| RDN payload | `public.hotlist_json`. `app/Dto/HotlistJsonData.php` only projects `branch_id`. The stored blob has more keys; writing through that DTO drops them. Case enrichment is `app/Jobs/Rdn/GetOrgHotlistCases.php` and `GetOrgHotlistCaseInfo.php`. |
| RDN link | `Hotlist::remoteLink()` builds the external URL from `InsightLPR\BlackAdam\Enum\IntegrationEnum` and `remote_order_id`. |
| Which orders generate hits | Database function `find_hits()` / `find_historicals()` in `packages/laravel-zod-migrations/database/schema/zod-schema.sql`. Open status, `order_type IN (1, 3, 88)`, non-empty plate, not on `blacklist_plates`. |

There is **no client-priority field** and **no recovery-likelihood score**. The hotlist list (`app/Filament/Resources/HotlistResource.php`) defaults to `created_at` descending and filters by status (default Open), VIN, plate, order id, client, missing plate, and Flex Search. That is a search table, not a queue.

### Addresses on the order

| What | Where |
| --- | --- |
| Address | `public.address` via `app/Models/Address.php`. `orig` (raw string), parsed `house_number`, `road`, `city`, `state`, `postcode`, PostGIS `geo` (point, SRID 4326), `type_id`. |
| Link to the order | `public.hotlist_address` via `app/Models/HotlistAddress.php`. Many addresses per hotlist. Nothing in the pivot caps the count at 6; 1–6 is the RDN business shape, not a schema constraint. |
| Address kind | `type_id = 2` is the **repo / address-of-recovery**, used by recovery views in the zod schema and by `okf/concepts/hotlist.md`. It is not "home" vs "work". Client-given addresses are the other `hotlist_address` rows. There is no stored home/work label. |
| Geocoding | `Address::geocode()` calls Geocodio. Queries that need a point require `geo IS NOT NULL` and should exclude the `0,0` sentinel called out in `okf/concepts/hotlist.md`. |
| Distance from a hit to a given address | `app/Http/Controllers/NearestGivenAddressController.php`. `ST_DistanceSphere` from the hit lat/lng to the nearest geocoded `hotlist_address`, returned in miles. Shown on the Hits table as "Distance From Given" / "Nearest Given Address" (`app/Filament/Resources/HitsResource.php`), behind `FeatureEnum::HIT_ADDRESS_DISTANCE`. |

### Hits and scans

| What | Where |
| --- | --- |
| Hit | `public.hits` via `app/Models/Hit.php`. `hotlist_id`, `scan_id`, `kit_id`, `hit_type`, `created_at`. No score column. |
| Scan | `public.scans` via `app/Models/Scan.php`. `ocr`, `us_state`, `geo`, `kit_id`, `camera_name`, `guid`, `scanned_at`, `is_visible`. |
| Scan extras | `public.scans_metadata` via `app/Models/ScanMetaData.php` and `app/Dto/ScanMetaDataDto.php`. Observed vehicle type/color/make, formatted address, disposition, `is_daytime`. Not year/model. |
| Hit types | `app/Enums/HitTypeEnum.php`. `L` live, `H` historical, `S`/`Z` staging live/historical, `M`/`F` missed opportunity, `P` probable. |
| How a hit is born | **Not application code.** `find_hits()` runs `AFTER INSERT` on `scans`. `find_historicals()` runs `AFTER INSERT OR UPDATE` on `hotlist`. Both require an **exact** `ocr = plate` match. The main live and historical paths do **not** require state to match. State is checked on a few special paths (Location Services closed-case hits, whitelist). |
| Hits before the order exists | `find_historicals()` selects visible scans with that plate for the org where `scanned_at >= today - lookback_days` and inserts `H` (or `Z` for staged). Factory default `lookback_days` is 270 (`database/factories/HotlistFactory.php`). Those scans can be months older than `hotlist.created_at`. The hit's `created_at` is when the row was inserted; the useful clock is `scans.scanned_at`. |
| "Ranked" | `Hit::scopeRanked()` is `row_number()` per `hotlist_id`. It is an ordinal, not a likelihood. |
| Disposition | `app/Enums/DispositionEnum.php`, stored on scan metadata and synced to ClickHouse by `app/Jobs/SyncDispositionToClickhouse.php`. Includes human labels `Moving`, `Accessible`, `Inaccessible`, `Invalid Scan`. |
| Fuzzy | Flex Search (`app/Support/FlexSearch.php`, `FeatureEnum::FLEX_SEARCH`) is a **search** toggle on the hits/hotlist tables. `isCloseMatch()` means the row plate is not exactly the searched plate. It does not create hits. `P` probable-match rows are written when a user sends a flex match to ClearPlan (`app/Jobs/SendFlexMatchToClearPlanJob.php`). |
| YMM / moving | Year/make/model live on the hotlist. The camera's make/color/type live on scan metadata. Neither is a hit gate. `app/Ai/VehicleAnalysisService.php` can ask Bedrock whether the photo matches and whether the car looks moving or parked. The result is cached for five minutes (`vehicle_analysis:{hit id}`). It is not a column. |
| Clustering | `app/Models/Concerns/ClustersByLocation.php`. `Hit::coordinates()` uses PostGIS `ST_DWithin` of `0.002` degrees (on the order of 200 m). |
| Hits UI | `app/Filament/Resources/HitsResource.php`, default sort `created_at` desc. Filters include plate, type, status, kit, date. Company Metrics (`app/Filament/Widgets/Dashboard/CompanyMetricsOverview.php`) counts live, historical, staging, repos, and leaderboards. Counts, not a ranked book. |

### Address visits, GPS, drivers

Two different "was someone here" facts. They must not be merged.

| Fact | Definition | Store |
| --- | --- | --- |
| LPR visit | A camera scan within **0.1 mile (160.934 m)** of a point, grouped into 1-hour blocks, last 60 days. Any plate, not "this assignment's car". | Computed in ClickHouse by `app/Clickhouse/Queries/LprVisitCountQuery.php`. `app/Http/Controllers/LprVisitCountController.php`. |
| Scout check-in | A Scout kit's GPS fix within **0.1 mile** of an **open** order address during a UTC hour. One row per kit, place, hour, and direct/staging side. | `platform.scout_check_ins` via `app/Models/ScoutCheckIn.php`. Recorded by `app/Console/Commands/ScoutRecordCheckIns.php` from `clarity.sc_positions` joined to `clarity.scout_open_addresses`. Documented in `okf/concepts/check-in.md`. |

A check-in is **not** "we knocked and the car was gone". It is "a kit's GPS was near this address". The recorder does not exclude hours that also produced a hit. No outcome column. `hour_bucket` is UTC, so it is the wrong clock for "overnight". Use `scans.scanned_at` in the scan timezone, or `scans_metadata.is_daytime`, for time of day.

Absence of a check-in is not proof the address was skipped. The address may be ungeocoded, the kit may not be reporting, or the order may be newer than the address book refresh (`okf/concepts/check-in.md`).

Driver position for Bird's Eye is `app/Services/Scout/ResolveOrgDriverPositions.php`: `clarity.sc_positions` (lat, lng, `timestamp_utc`, speed, heading), falling back to scan paths, ignored after 24 hours. Speed is the **kit**, not the target car. `speed > 1` is only used to decide whether a heading is meaningful.

Bird's Eye (`app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`, `/scout/org-map`) shows driver dots, zones, stats, and recent hits. It is not an assignment queue.

Kits are `public.kits` (`app/Models/Kit.php`) with optional fixed lat/lng. The assigned driver is `platform.kit_settings.user_id` (`app/Models/KitSettings.php`). Users and roles are the Filament account (`app/Models/User.php`).

### Branches, zones, and the new locations

| What | Where | Useful for distance? |
| --- | --- | --- |
| RDN branch | `platform.branches` via `app/Models/Branch.php`. Name, street, city, state, zip, phone. Filled by `app/Jobs/Rdn/GetOrgBranches.php`. `hotlist_json.branch_id` points at a branch. | **No coordinates** on the model. |
| Scout zone | `platform.zones` via `app/Models/Zone.php`. Named polygon (`definition` + PostGIS `geom`), color, assigned users (`zone_user`). | Coverage polygons for drivers, not a route. |
| Locations & Coverage | Prototype only: `platform.org_locations` and `platform.org_coverage_zones` (`app/Models/OrgLocation.php`, `app/Models/OrgCoverageZone.php`). Name, type (main office / storage / satellite), address, optional phone and notes, lat/lng, per-location radius or polygon, plus one company-wide radius or polygon. Seeded for Test Company. | Yes, once a hit or address has a point. Not read by hits, check-ins, or Bird's Eye today. |

## 2. A scoring model a manager can trust

Call it **Recovery Likelihood**. Rank **open** hotlists (`status = 1`, plate length > 3). Score the order by its **best address**, and keep the other addresses visible so a manager can send someone to Address 2 even when Address 1 is cold.

Use an additive score with a published point table, not a trained model. Every point that fires must be a clause in the reason sentence. If a signal is missing, omit it. Do not impute.

Suggested first weights, all computable from columns above:

| Signal | Points | Why | Already in Clarity? |
| --- | --- | --- | --- |
| Newest hit on this order is under 48 hours (`scans.scanned_at`) | 40 | The car was just seen. | Yes. Prefer `scanned_at` over `hits.created_at`. |
| Newest hit is 2–10 days old | 25 | Still a pattern, not a sighting. | Yes. |
| Newest hit is older than 10 days, or the only hits are historical and inside `lookback_days` | 8 | The order arrived onto old camera history. Say so. | Yes. `H` vs `L`, and `lookback_days`. |
| 2 or more hits within **0.1 mile** of the same given address in 10 days | 20 | Repeat place. 0.1 mile is already the LPR-visit and check-in radius (`160.934 m`). A tighter 400 ft band can be an extra +10, not a new definition. | Yes. Same geometry as `NearestGivenAddressController`. |
| Those repeats fall in the same local day-part (overnight, morning, evening), using scan timezone or `is_daytime` | 10 | Home-or-work **hypothesis**. Do not label the address "home". | Partial. No home/work type. |
| Nearest given address is under 0.1 mile | 15 | The client's address and the camera agree. | Yes, per hit, Pro-gated in the UI. |
| Nearest given address is 0.1–1 mile | 5 | Same area, not the driveway. | Yes. |
| That address has **no** `scout_check_ins` row | 10 | Nobody on a Scout kit has been recorded nearby while the order was open. | Yes, with the absence caveat above. |
| That address has check-ins, and the hits are **after** the latest `matched_at` | 12 | Someone was nearby, and the car was seen again later. | Yes. Do not call the check-in a failed recovery. |
| Hit or address point lies inside the company coverage zone, or within N miles of a storage facility / main office (`org_locations`) | 8 | The yard can actually work it today. | Prototype locations only. Zones exist but are driver polygons, not yard locations. |
| A kit with a GPS fix under 24 hours old is within a few miles of that point | 8 | A driver is already in the area. | Yes, `ResolveOrgDriverPositions`. Stale dots must score 0. |
| Order age under 3 days (`hotlist_meta.order_date`, else `hotlist.created_at`) | 5 | Fresh assignments get a small boost, not the lead. | Yes. |
| Disposition is `Invalid Scan` | −40 | A person already rejected the read. | Yes. |
| Disposition is `Moving` | −15 | Human label that this read was a drive-by. It is not camera speed. | Yes, as a label only. |
| Plate on the scan equals the hotlist plate | required for `L`/`H`/`S`/`Z` | These hits are exact OCR. | Yes. |
| `P` probable / Flex Search close match | do not add by default | A person opted into a non-exact plate. Show it as a separate chip, not as points, until someone confirms it. | Yes, but rare and user-initiated. |

Things Oscar named that this tree cannot score honestly yet:

- **Home vs work as a fact.** Infer a day-part cluster. Do not store it as an address type.
- **Parked vs moving for the target car.** Kit GPS speed is the camera vehicle. Bedrock `vehicle_status` is not persisted. The disposition label is the only durable flag, and it is manual.
- **YMM match as a gate.** The prototype Configuration toggle is not wired to `find_hits()`. A mismatch can only be shown when scan metadata or a stored vehicle-analysis result exists.
- **Plate and state must match.** The live/historical triggers match plate only. A state mismatch should be a **warning chip**, not a silent filter, until product decides to change the trigger.
- **Client priority.** No column. `client_org_id` can filter. `amount_past_due` exists on `hotlist_meta` and should stay out of v1 so the queue is not secretly a collections sort.
- **Visit outcome** (empty driveway, refused, recovered). Not stored.

### Explainability

One sentence per order, built only from clauses that scored:

> 3 exact hits within 0.1 mi of Address 2 in the last 10 days, all between 1am and 4am local. Newest hit 6 hours ago. No Scout check-in on that address. Inside the company 25-mile zone.

Secondary line, smaller:

> Address 1 has a check-in yesterday at 2pm UTC and no hit since. Plate on the hits matches the order. State on two hits differs from the order.

Rules:

- Say **exact** or **probable**. Never say "confident match" for a `P`.
- Say **historical, scanned {date}** when `hit_type` is `H` or `Z` and `scanned_at` is before `order_date`.
- Say **no check-in recorded**, not "never visited".
- Say **day-part cluster**, not "this is the home".
- If the address has no `geo`, the reason is "Address 2 is not geocoded" and that address scores nothing.
- Cap the sentence at three clauses. The rest sit behind "Why this rank".

### Views

1. **Ranked queue (order level).** Open assignments, score descending, reason, client, order age, newest hit time, best address. This is the morning list. Default filter: score above a floor, or "has a hit in 10 days". Everything else stays on the existing hotlist search.
2. **Address rows inside the order.** Each given address: distance of the nearest hit, hit count, day-part, check-in count and last hour, LPR-visit count (camera traffic, any plate). Sort addresses by their own points. This is how Address 2 beats Address 1.
3. **Unvisited-with-hit callout.** Address has a hit inside 0.1 mile in 10 days and zero `scout_check_ins`. One strip at the top of the queue, not a fourth product.
4. **Map clusters.** Reuse the hit map / zone map. Pins are recent hits colored by score band. Circles are the 0.1-mile given-address halos. Coverage polygon from Locations & Coverage. Tiles may be blank without `GOOGLE_MAPS_API_KEY`; the queue still works.
5. **Driver plan.** Pick a driver (kit user). Keep stops whose point is inside that driver's zones or the company coverage zone, and within a straight-line radius of the driver's last GPS fix. Order by score, then by distance from the previous stop. This is a suggestion list, not turn-by-turn routing. No routing engine exists.
6. **Hot alert.** When a new `L` hit lands inside 0.1 mile of a given address on an open order, raise the existing hit notification (`app/Notifications/Hits/HitNotification.php`) with the reason clause attached. Do not invent a second alert channel in the prototype.

Order-level ranking is the default because the manager assigns **work**, and work is the RDN order. Address-level ranking is the drill-in, because the recoverable place is often not address 1.

## 3. Quick wins vs new data

**Build from data already here**

- Rank open hotlists by recent exact hits, proximity to `hotlist_address.geo`, repeat location, order age, and disposition penalties.
- Explain with `scanned_at`, hit type, distance miles, and address index.
- Show check-ins from `scout_check_ins` as "GPS nearby", split from LPR visits.
- Day-part from `scanned_at` plus scan timezone / `is_daytime`.
- Drop or flag `0,0` and null `geo`.
- Filter to the prototype coverage zone and distance to `org_locations` once those rows exist.
- Straight-line distance to a fresh driver dot.

**Needs new data or a product decision before it is honest**

- Home vs work labels (or a confirmed day-part model).
- Persisted YMM match and parked/moving for the **target** vehicle.
- State-required matching (that is a trigger change in `find_hits()` / `find_historicals()`, not a UI toggle).
- Visit outcome.
- Client priority.
- Branch coordinates (geocode `platform.branches`, or keep using Locations & Coverage as the yard).
- Drive time. Straight-line distance will be wrong across rivers and interstates.

## 4. Risks

- **Stale historical hits.** A 270-day lookback will surface a parking-lot read from last spring the moment the order is accepted. The reason must show the scan date and the word historical. Old `H` hits should not outrank a 6-hour-old `L`.
- **Exact OCR is still wrong sometimes.** The trigger does not check state or YMM. A one-character plate error never becomes an `L` hit; a wrong-but-exact read (bad camera, similar plate) does. Invalid-scan dispositions are the correction. Flex/`P` hits are a different, weaker population.
- **Fuzzy false confidence.** If probable matches are mixed into the same score, managers will chase near-misses. Keep them out of the points until confirmed.
- **Check-in absence.** Treating "no row" as "never visited" will scold drivers for ungeocoded addresses and kits with no GPS. Copy has to say "not recorded".
- **Check-in presence.** A check-in during a hit hour is not a failed attempt. Do not phrase it as one.
- **`0,0` and ungeocoded addresses.** They dominate distance queries if not excluded. The existing hotlist address metrics already exclude the sentinel.
- **UTC hour buckets.** Scoring "overnight" off `scout_check_ins.hour_bucket` will be wrong for US agencies. Use the scan clock.
- **Frozen hit status.** ClickHouse hit rows can keep the hotlist status from insert time (`okf/concepts/hit.md`). The queue should read live `hotlist.status`, not the frozen copy.
- **Data ownership.** Hits, scans, and hotlists are Insight-generated (`zod`). Check-ins are Clarity's GPS ledger (`platform`). RDN remains the system of record for the order. This prototype must not write a score back to RDN, ClearPlan, or the hit triggers. Locations & Coverage is sample data in this sandbox.
- **Two orgs, one car.** Staging (`is_staged`, `S`/`Z`) is another agency's order. A manager queue should default to the org's own open book (`org_id`, not staged) and treat staging as a separate lane.

## 5. Phased prototype (next, not this change)

Three screens, all stubbed on Test Company sample data, on top of the current Configuration page's locations. No RDN writeback. No trigger changes.

### Screen A — Ranked queue

Filament page under Search, next to Hits. Title: **Low-hanging fruit**.

```
Low-hanging fruit                          [Open only] [Hits in 10 days v]

  86  ABC1234  IN   Test Lender          Order 14d
      3 exact hits within 0.1 mi of Address 2, last 10 days,
      overnight. Newest 6h ago. No Scout check-in recorded.
      [Why]  [Open in RDN]

  71  XY9999   IN   Other Lender         Order 3d
      1 live hit 0.4 mi from Address 1, yesterday 4pm.
      Check-in on Address 1 last Tuesday.
      ...

  —   older historical only, or no geocoded address, below the fold
```

Wireframe notes: score is a number, not a gauge. The sentence is the product. "Why" expands the clauses and the addresses. Default sort is score. Plate, client, and order age are columns a manager already knows from `HotlistResource`. Empty state: "No open assignments with a recent exact hit."

### Screen B — Order drawer (address level)

Click a row. Right drawer, not a new app.

```
ABC1234 · 2019 Honda Civic · Order 48211
Company zone: inside 25 mi of Test Company HQ

Address 1  120 Main St          0 hits nearby     check-in Tue 14:00
Address 2  88 Oak Ave           3 hits, 0.04 mi   no check-in recorded
           overnight cluster (1–4am local)
           [map: hit pins + 0.1 mi halo + coverage outline]

Hits
  6h ago   exact L   0.04 mi from Address 2   kit North 4   parked label: none
  2d ago   exact L   0.06 mi from Address 2   same kit
  9d ago   historical H  scanned 4 months before the order   do not treat as fresh
```

Wireframe notes: Address 2 is listed first because its points win, even though it is not address 1. The map can be the same coordinate plot Locations & Coverage uses when Google tiles are blank. Historical hits stay in the list and are visually marked so they cannot masquerade as today's fruit.

### Screen C — Morning map / driver strip

One map of the ranked set, plus a driver picker that does not pretend to route.

```
[ coverage outline ]
[ coral pins = score 70+ ]  [ gray = older ]
[ driver dot, if GPS < 24h ]

Driver: Jordan (North 4)     last fix 12 min ago
Suggested stops inside coverage
  1. ABC1234  Address 2     86    4.1 mi from Jordan
  2. QWE4321  Address 1     74    1.2 mi from stop 1
  3. ...
```

Wireframe notes: straight-line miles, labeled as straight-line. Stops limited to about eight. If the driver has no fresh GPS, the strip says so and falls back to "inside the company zone" with no distance-from-driver points.

### What not to stub yet

A learned model, RDN case updates, changing `find_hits()` to require state or YMM, and any sentence that says "never visited" or "this is the debtor's home".
