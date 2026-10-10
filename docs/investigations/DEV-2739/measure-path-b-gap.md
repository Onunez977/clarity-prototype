# DEV-2739 — Path B gap: PG SoT vs `clarity.recovery_address` (READ-ONLY)

**When:** 2026-09-25 ~15:00 CT · Cloud CH READ-ONLY · zod = PeerDB mirror of Postgres  
**Q3 lock:** Path B is the product path (do **not** swap to Path A).  
**Extends:** `measure.md`, `measure-q3-contrast.md`  
**No product PHP · Provision/Implement PARKED**

## Seam (what Path B reads)

Controller / blades → nearest-given-address → `clarity.recovery_address FINAL` with `geo.1/geo.2 != 0`.

`recovery_address` is **not** a direct PeerDB table of “given addresses.” It is filled by:

**`clarity.recovery_address_mv`** — `REFRESH EVERY 10 MINUTE APPEND TO recovery_address`

Effective filter (from live `CREATE MATERIALIZED VIEW`):

1. Only hotlists whose `public_hotlist_address` **or** linked `public_address` `_peerdb_synced_at` is within the **last 15 minutes** (incremental window).
2. `zod.public_hotlist_address` ⋈ `zod.public_address`
3. **`a.type_id = 2`** (Given Address)
4. `a.geo IS NOT NULL`
5. both sides `_peerdb_is_deleted = 0`
6. Parses EWKT `POINT (lng lat)` into CH `Point`

Postgres / PeerDB SoT tables: `zod.public_address`, `zod.public_hotlist_address` (ReplacingMergeTree, `_peerdb_*`).

## Cohort

Same locked window as Q3: orgs **14, 1271, 541, 418753, 1143** · Open · New Hit+Accessible · Today UTC `[2026-09-25 05:00, 18:54)` · usable scan geo · **B-miss · A-hit** hotlists (Path A geocoded present, Path B qualifying RA absent).

| org | B-miss·A-hit hotlists | type_id=2 geocoded in zod | only non-type2 (NULL) geocoded | any RA row | RA only zero-geo | RA deleted | **no RA row at all** |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1271 | 439 | **0** | **439** | 0 | 0 | 0 | **439** |
| 14 | 260 | **0** | **260** | 0 | 0 | 0 | **260** |
| 541 | 187 | **0** | **187** | 0 | 0 | 0 | **187** |
| 1143 | 135 | **0** | **135** | 0 | 0 | 0 | **135** |
| 418753 | 79 | **0** | **79** | 0 | 0 | 0 | **79** |
| **Σ** | **1100** | **0** | **1100** | **0** | **0** | **0** | **1100** |

Drop-type_id thought experiment on same B-miss·A-hit set: `would_pass_current_MV = 0` · `would_pass_if_drop_type_id = 884` (unique hotlists with geocoded link; all `type_id IS NULL`).

Global zod.public_address (not deleted): ~2.79M `type_id=2` · ~198M `type_id IS NULL` — PeerDB **does** sync `type_id` when set; NULL here is not “column missing from mirror.”

## Answers (Dalton’s five)

### 1) Does PG hotlist_address⋈address with geo exist?

**Yes** (via zod PeerDB mirror of PG). Every B-miss·A-hit hotlist has ≥1 non-deleted hotlist_address → address with non-empty `geo`.

### 2) Corresponding `recovery_address` / peers?

**No row at all** in `clarity.recovery_address FINAL` for these hotlists (not zero-geo, not soft-deleted — absent). Upstream zod rows exist (`type_id` NULL, geo present, not deleted).

### 3) type_id=2 filter: drop vs absent?

Rows are **present in SoT mirror with geo but `type_id IS NULL`**, so they **fail `a.type_id = 2`** and never enter the MV.  
Dropping / widening the type filter would make them MV-eligible; keeping type_id=2 requires PG SoT to set Given Address (`type_id=2`) on those rows. They are not “missing from address table.”

### 4) Lag?

Not the driver for this cohort.

| Signal | Value |
| --- | --- |
| max RA `_peerdb_synced_at` | ~2026-09-25 19:48 UTC (healthy) |
| max public_address sync | ~2026-09-25 19:57 UTC (type2 and null-type both fresh) |
| B-miss sample addr sync | same day, hours before measure (13:34–18:47 UTC) |
| Path B **present** control | addr sync → RA sync within **~seconds** when `type_id=2` |

Incremental 15‑minute MV window is a **latent** risk for historical backfill, but these sample links synced today and still never entered RA because of type_id — lag alone does not explain the gap.

### 5) Plain root class

**Dominant (100% of measured B-miss·A-hit hotlists): mirrored but wrong / missing type_id**

- SoT mirror has address + geo + hotlist link.
- `type_id` is **NULL** (not 2).
- MV requires `type_id = 2` → no `recovery_address` row → Path B UI N/A by contract.

Not: zero geo · deleted in CH · empty PeerDB pipeline · controller formatter on zero scan geo.

Secondary / latent (not proven as this cohort’s cause): incremental-only MV (15‑min affected_ids) could leave historical type_id=2 gaps if never backfilled — separate from this packet’s 1100 hotlists.

## Concrete examples — B-miss · A-hit (gap)

| org | hotlist | plate | hit_id | address_id | type_id | geo (EWKT) | street | ha/addr synced (UTC) | RA rows |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 14 | 198227363 | SHK5039 | 365015475 | 323223809 | **NULL** | POINT (-97.276107 32.884759) | 4917 Cedar River Trl | 16:19 / 16:20 | **0** |
| 14 | 198225827 | SMS0474 | 365005175 | 323220782 | **NULL** | POINT (-97.073002 32.605127) | 3655 Prairie Waters Dr | 15:35 / 15:36 | **0** |
| 1271 | 198229869 | SWR4032 | 365027578 | 323226975 | **NULL** | POINT (-98.404503 29.555694) | 12660 Uhr Ln | 17:17 / 17:17 | **0** |
| 541 | 198226372 | WVY6077 | 365008759 | 323221956 | **NULL** | POINT (-97.338709 32.862063) | 1816 Western Center Blvd | 15:45 / 15:45 | **0** |
| 418753 | 198237082 | CJ83591 | 365042451 | 323230232 | **NULL** | POINT (-87.59951 41.545714) | 508 E Mulberry Ct | 18:47 / 18:47 | **0** |
| 1143 | 198209535 | HHM6700 | 364989440 | 323214863 | **NULL** | POINT (-81.841706 41.450837) | 20100 Lorain Rd | 13:34 / 13:34 | **0** |

## Concrete examples — Path B present (control · works)

| org | hotlist | plate | hit_id | address_id | type_id | addr synced | RA geo | RA synced |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| 14 | 198237015 | TYH1103 | 365041779 | 323231988 | **2** | 19:18:39 | (-97.066037,32.692133) | 19:17:48 |
| 14 | 198236472 | SRG2747 | 365038193 | 323231884 | **2** | 19:14:38 | (-95.352445,29.795911) | 19:14:40 |
| 1271 | 198233250 | WGG0487 | 365034267 | 323231504 | **2** | 19:05:34 | (-98.458909,29.372332) | 19:05:36 |
| 541 | 198226382 | WVY6077 | 365013317 | 323232592 | **2** | 19:32:43 | (-97.323882,32.986872) | 19:32:40 |
| 1143 | 198225586 | KZL5304 | 365003434 | 323231510 | **2** | 19:06:35 | (-84.609025,39.091711) | 19:05:57 |

When SoT has `type_id=2` + geo, MV → RA within seconds. Path B pipeline is healthy for correctly typed rows.

## Caveat (PG vs zod)

All SoT reads are **zod PeerDB mirrors**, not a live `psql` session. Evidence that NULL is real in PG: same `public_address` table carries ~2.79M live `type_id=2` rows with fresh sync. Confirming a single address_id in Herd/fleet PG would be optional hardening — not required to classify the CH seam.

## Cite

```sql
-- MV filter (live DDL): type_id = 2 AND geo IS NOT NULL AND 15-min affected hotlists
-- Path B qualify
SELECT * FROM clarity.recovery_address FINAL
WHERE id = {hotlist_id} AND geo.1 != 0 AND geo.2 != 0 AND _peerdb_is_deleted = 0;

-- SoT mirror for same hotlist
SELECT ha.hotlist_id, a.id, a.type_id, a.geo, a._peerdb_synced_at
FROM zod.public_hotlist_address AS ha FINAL
INNER JOIN zod.public_address AS a FINAL ON a.id = ha.address_id
WHERE ha.hotlist_id = {hotlist_id} AND ha._peerdb_is_deleted = 0 AND a._peerdb_is_deleted = 0;
```

OKF: Path B = CH Hits + nearest-given → `recovery_address` (see prior Glorfindel / `okf/concepts/hit-address-distance.md`). This packet adds live MV DDL as the type_id=2 seam.

## Still parked

No product PHP. No Path A controller swap. Provision/Implement behind #468 Off GO. Next is Dalton/data-owner call on whether NULL `type_id` addresses should be Given (`type_id=2`) in PG, vs intentional non-given addresses that Path B correctly ignores.

## Glorfindel / OKF cite (2026-09-25)

Confirms Path B product path; **not** recommending Path A swap.

- **PG SoT:** `public.recovery_address` is itself a **materialized view** (`laravel-zod-migrations` `database/schema/zod-schema.sql`): `hotlist_address` ⋈ `address`, filter **`a.type_id = 2`**, `row_number() OVER (PARTITION BY hotlist_id ORDER BY address_id) = 1` → **one type-2 row per hotlist** (lowest `address_id`). PG MV has **no** `geo IS NOT NULL` predicate.
- **CH:** `clarity.recovery_address` analytical mirror; refresh MV in chlocal `migrations/cloud_schema/clarity/materialized_views/recovery_address_mv.sql` (every 10 min): `type_id = 2`, `a.geo IS NOT NULL`, deleted=0.
- **type_id=2** = repo / address-of-recovery — `okf/concepts/hotlist.md`, `okf/concepts/hit-address-distance.md`.
- **Path B controller:** `NearestGivenAddressController.php` (~L35–47): `clarity.recovery_address FINAL`, `ra.id = hotlist_id`, `ra.geo.1/2 != 0`. Admin impersonation / `clickhouse:hits` → always Path B for these columns.
- **Expected parity:** geocoded **non-type-2** hotlist_address can feed Path A but **does not belong** in recovery_address by MV definition (PG or CH).
- **Lag / deletion** as Brad Webb cause: still **UNPROVEN** per Glorfindel; our measure agrees lag is not the driver for the 5-org B-miss·A-hit cohort (100% NULL type_id, zero RA rows).

This cite **locks** the measure root class: mirrored address+geo with `type_id IS NULL` is a **proven filter gap** vs Path B contract, not a PeerDB empty-pipeline failure.
