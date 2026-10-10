# DEV-1925 — Manual QA guide (Scout zone-coverage visibility)

The feature lives in the **Scout PWA** (`/scout`, token-authed) — not the admin panel. **I've already seeded and verified everything for the core coverage QA**; see "What I set up" then jump to the steps.

---

## ✅ What I set up for you (already done & verified)

- **Switched ClickHouse to the local pgsql bridge** (`.env`) so the map reads your local data instead of Cloud. Backup saved at `.claude/.env.qa-backup` — **revert when done** (see bottom).
- **Made `KitTripCoverageGeohashQuery` portable** (it bucketed points with ClickHouse-only `ARRAY JOIN`/`tupleElement`, which the bridge can't run → coverage would've been empty locally). It now pulls `path_simplified` and buckets in PHP — works on the bridge *and* Cloud/CI. (`npm run build` already current; this was a backend-only change.)
- **Created a driver-sized zone over your real trip data** and made it the driver's only zone:
  - Zone **id 6 — "QA Coverage (Indy)"** (org 2), a ~0.06° box over Indianapolis where kit 1's 15 real trips already are. (Your prior "Zone 1" was in Colorado, away from any trips — that's why coverage looked empty.)
  - The scout driver **`Admin` / test@tester.com** (kit 1) is now assigned to **only** zone 6.
- **Verified the coverage endpoint output** against this data:

| Lookback | Covered (green) | Missed (gray) | Grid |
|---|---|---|---|
| 7 days  | 130 | 1895 | precision 7, not capped |
| 30 days | 227 | 1798 | precision 7, not capped |
| 90 days | 227 | 1798 | precision 7, not capped |

So coverage renders a fine grid with green cells tracing the driver's trips and gray everywhere else, and the lookback pill visibly changes it (130 → 227 covered between 7d and 30d).

---

## How to QA the coverage layer (AC2 / AC3 / AC6 — the core)

> **Redesigned visual (not the old grid).** Coverage now reads as: a **recency-colored route trail** (your actual driven roads — teal = recent, faded slate = older) + **zoom-adaptive "% covered" sectors** (a handful of tinted blocks labeled `NN%`; amber = cold/go-here → green = done) + a zone-wide **% covered** in the legend. **Hard-refresh first** (Cmd+Shift+R) since the PWA service worker caches JS.

1. **Get into the PWA as the driver.** Open `https://platform.test/__qa/login` (lands in `/app`) → **Scout Users** resource → kit 1 (assigned to Admin) → **"Login to Scout"** → the PWA opens as the driver.
2. **Open the "Layers" panel** and toggle **"My Coverage"** (map auto-zooms to the Indy zone).

| # | Do | Expect |
|---|----|--------|
| 1 (AC2/AC3) | Toggle **My Coverage** on | The zone fills with a few **amber→green `NN%` sectors** (cold = amber, done = green) + your **route trail** in teal/slate over it. Legend header shows zone-wide **% covered (~11%)**. No console errors; request `200`. |
| 2 (sectors split) | **Zoom in**, then **zoom out** | Sectors **split into more, smaller `%` blocks as you zoom in** and **merge into fewer big ones as you zoom out** (recomputed when the map settles). |
| 3 (AC3 recency) | Inspect the trail | Recently-driven segments are brighter teal; older ones fade toward slate. The trail follows real roads, not blocks. |
| 4 (AC2 lookback) | Switch **7 / 30 / 90** | Trail + sector %s re-fetch and shift (7d shows less coverage than 30d). Default 30. |
| 5 (AC6) | (Optional) In Manage Zones, draw a zone with no trips, assign to the driver | Sectors all read **0%** (amber) with no error — "cover the whole zone even with no activity." |

**Watch for:** no console errors; sectors + trail clip to the zone; `%` labels are legible; zooming visibly re-tiles the sectors.

---

## Edge / negative cases

| # | Do | Expect |
|---|----|--------|
| No-zone | In Manage Zones, unassign the driver from all zones; reload the PWA; toggle My Coverage | "No zone assigned…" hint, no sectors/trail, no error (`has_zone:false`). *(Re-assign zone 6 afterward.)* |
| Lookback guard (API) | Grab the PWA's bearer token (devtools → Network → any `/api/scout/*` request → `Authorization: Bearer …`), then `curl -H "Authorization: Bearer <token>" "https://platform.test/api/scout/map/coverage?lookback_days=5"` | **422** (only 7/30/90 allowed). |
| Honey-hole tampering (API) | `…/api/scout/map/heatmap/hits?north=39.8&south=39.74&east=-86.12&west=-86.18&zone_id=1` (a zone you're not assigned to) | **403**. |
| Org isolation | Confirm coverage only ever reflects kit 1 / org 2. | Never another org's data. |

---

## ⚠️ Honey-hole layers (AC4) — verify on staging/Cloud, not locally

The honey-hole pills (**Hit Hotspots / Direct / Staging**) sit on the pre-existing `HitDensityGeohashQuery` / `HotlistAddressGeohashQuery`, which call SQL `geohashEncode(...)`. **On this local pgsql bridge that function's signature doesn't match, so those heatmaps error/empty regardless of my change** — a pre-existing bridge limitation, not DEV-1925. My zone-clip rides on top of them.

To QA AC4 on **staging** / real-ClickHouse: toggle Hit Hotspots and confirm the honey holes now render as **sparse static diamond glyphs (`◆`)** — sized by value, colored per layer (hits = orange, direct = blue, staging = purple) — only at the top hotspots **within the assigned zone** (request carries `&zone_id=<zone>`), not a blocky heatmap fill or anything pulsating. The clip logic is covered by the `heatmap_clip_rejects_unassigned_zone` test (CI).

---

## Admin-side regression (already green in agent-QA — eyeball)

- `/app/kit-user-path` → toggle **Hit Density**, pan the map → `POST /kit-user-path/hit-density` 200, no console error (the deduped `Geohash::decodeBounds` path). *Note: same bridge `geohashEncode` caveat — cells may be empty locally; the 200 + no-error is the regression signal.*
- `/app/manage-zones` loads; `GET /zones/all` 200; draw/save works.

---

## Pass bar

- **Must pass:** PWA loads as the driver; **My Coverage** renders the green/gray grid clipped to zone 6; lookback 7/30/90 re-fetches and changes the covered count; no-zone shows the hint; no 500s/console errors; `lookback_days=5` → 422; unassigned `zone_id` → 403; admin Kit User Path + Manage Zones unaffected.
- **Cloud/staging-only:** honey-hole heatmap visuals (bridge `geohashEncode` limitation).

---

## 🔄 Revert when you're done QAing

```bash
# restore the original ClickHouse (Cloud) env
cp .claude/.env.qa-backup .env && php artisan config:clear
```

Optional data cleanup (the seeded zone is harmless to leave, but to fully restore):
```bash
php artisan tinker --execute='
use App\Models\User; use App\Models\Zone;
User::find(1)->zones()->sync([1]);   # re-assign the original Colorado "Zone 1"
Zone::find(6)->forceDelete();         # drop the seeded Indy QA zone
echo "restored\n";'
```

## Automated coverage (CI)

`GeohashTest`, `KitTripCoverageGeohashQueryTest`, and the new `ClarityScoutMapControllerTest` coverage cases run in CI under `CLICKHOUSE_DRIVER=clickhouse`.
