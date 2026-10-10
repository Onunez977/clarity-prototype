-- DEV-2339 measurement pack (read-only). Replace :ORG_ID.
-- Optional staging list: leave empty `()` or fill e.g. (101,102).
-- Paste back: elapsed, read_rows, read_bytes, peak_memory_usage, and EXPLAIN indexes=1 PrimaryKey lines.
-- Do NOT paste address rows / plates / geo payloads.

-- 0) Pick a heavy Scout org (aggregate only)
SELECT
  org_id,
  count() AS open_hotlists
FROM clarity.hotlist FINAL
WHERE _peerdb_is_deleted = 0
  AND status = 1
  AND length(plate) > 3
  AND is_staged = false
GROUP BY org_id
ORDER BY open_hotlists DESC
LIMIT 20;

-- For the chosen :ORG_ID, also count address links (this IS the expensive scan — expect slow)
-- SELECT count(DISTINCT ha.address_id) AS address_count
-- FROM zod.public_hotlist_address ha
-- INNER JOIN (
--   SELECT id FROM clarity.hotlist FINAL
--   WHERE _peerdb_is_deleted = 0 AND status = 1 AND length(plate) > 3
--     AND (org_id = :ORG_ID AND is_staged = false)
-- ) oh ON oh.id = ha.hotlist_id
-- WHERE ha._peerdb_is_deleted = 0;

/* ========== CURRENT (JOIN + IN) ========== */
EXPLAIN indexes = 1
WITH open_hotlists AS (
    SELECT id, is_staged
    FROM clarity.hotlist FINAL
    WHERE _peerdb_is_deleted = 0
      AND status = 1
      AND length(plate) > 3
      AND (org_id = :ORG_ID AND is_staged = false)
),
address_links AS (
    SELECT DISTINCT
        ha.address_id AS address_id,
        oh.is_staged AS is_staged,
        oh.id AS hotlist_id
    FROM zod.public_hotlist_address ha
    INNER JOIN open_hotlists oh ON oh.id = ha.hotlist_id
    WHERE ha._peerdb_is_deleted = 0
)
SELECT
    address_id AS out_address_id,
    argMin(hotlist_id, (toUInt8(is_staged), hotlist_id)) AS out_hotlist_id,
    min(is_staged) AS out_is_staged,
    argMax(lng, ver) AS out_lng,
    argMax(lat, ver) AS out_lat
FROM (
    SELECT
        al.address_id AS address_id,
        al.is_staged AS is_staged,
        al.hotlist_id AS hotlist_id,
        a._peerdb_version AS ver,
        toFloat64OrNull(extract(a.geo, 'POINT ?\\(([-0-9.]+) ')) AS lng,
        toFloat64OrNull(extract(a.geo, ' ([-0-9.]+)\\)')) AS lat
    FROM zod.public_address a
    INNER JOIN address_links al ON al.address_id = a.id
    WHERE a._peerdb_is_deleted = 0
      AND a.geo IS NOT NULL
      AND a.geo != ''
      AND a.id IN (SELECT address_id FROM address_links)
)
WHERE lng IS NOT NULL AND lat IS NOT NULL AND lng != 0 AND lat != 0
GROUP BY address_id;

-- Then run the SAME SELECT (without EXPLAIN) and note: elapsed / read_rows / read_bytes / rows returned count
-- SELECT count() FROM (<same SELECT as above>);

/* ========== A) JOIN-only (delete IN) ========== */
EXPLAIN indexes = 1
WITH open_hotlists AS (
    SELECT id, is_staged
    FROM clarity.hotlist FINAL
    WHERE _peerdb_is_deleted = 0
      AND status = 1
      AND length(plate) > 3
      AND (org_id = :ORG_ID AND is_staged = false)
),
address_links AS (
    SELECT DISTINCT
        ha.address_id AS address_id,
        oh.is_staged AS is_staged,
        oh.id AS hotlist_id
    FROM zod.public_hotlist_address ha
    INNER JOIN open_hotlists oh ON oh.id = ha.hotlist_id
    WHERE ha._peerdb_is_deleted = 0
)
SELECT
    address_id AS out_address_id,
    argMin(hotlist_id, (toUInt8(is_staged), hotlist_id)) AS out_hotlist_id,
    min(is_staged) AS out_is_staged,
    argMax(lng, ver) AS out_lng,
    argMax(lat, ver) AS out_lat
FROM (
    SELECT
        al.address_id AS address_id,
        al.is_staged AS is_staged,
        al.hotlist_id AS hotlist_id,
        a._peerdb_version AS ver,
        toFloat64OrNull(extract(a.geo, 'POINT ?\\(([-0-9.]+) ')) AS lng,
        toFloat64OrNull(extract(a.geo, ' ([-0-9.]+)\\)')) AS lat
    FROM zod.public_address a
    INNER JOIN address_links al ON al.address_id = a.id
    WHERE a._peerdb_is_deleted = 0
      AND a.geo IS NOT NULL
      AND a.geo != ''
)
WHERE lng IS NOT NULL AND lat IS NOT NULL AND lng != 0 AND lat != 0
GROUP BY address_id;

/* ========== B) prune-first subquery then join metadata ========== */
EXPLAIN indexes = 1
WITH open_hotlists AS (
    SELECT id, is_staged
    FROM clarity.hotlist FINAL
    WHERE _peerdb_is_deleted = 0
      AND status = 1
      AND length(plate) > 3
      AND (org_id = :ORG_ID AND is_staged = false)
),
address_links AS (
    SELECT DISTINCT
        ha.address_id AS address_id,
        oh.is_staged AS is_staged,
        oh.id AS hotlist_id
    FROM zod.public_hotlist_address ha
    INNER JOIN open_hotlists oh ON oh.id = ha.hotlist_id
    WHERE ha._peerdb_is_deleted = 0
),
pruned_addresses AS (
    SELECT
        id,
        _peerdb_version AS ver,
        toFloat64OrNull(extract(geo, 'POINT ?\\(([-0-9.]+) ')) AS lng,
        toFloat64OrNull(extract(geo, ' ([-0-9.]+)\\)')) AS lat
    FROM zod.public_address
    WHERE _peerdb_is_deleted = 0
      AND geo IS NOT NULL
      AND geo != ''
      AND id IN (SELECT address_id FROM address_links)
)
SELECT
    al.address_id AS out_address_id,
    argMin(al.hotlist_id, (toUInt8(al.is_staged), al.hotlist_id)) AS out_hotlist_id,
    min(al.is_staged) AS out_is_staged,
    argMax(pa.lng, pa.ver) AS out_lng,
    argMax(pa.lat, pa.ver) AS out_lat
FROM address_links al
INNER JOIN pruned_addresses pa ON pa.id = al.address_id
WHERE pa.lng IS NOT NULL AND pa.lat IS NOT NULL AND pa.lng != 0 AND pa.lat != 0
GROUP BY al.address_id;

/* ========== Stage cost: is public_hotlist_address the dominant cost? ========== */
EXPLAIN indexes = 1
SELECT count(DISTINCT ha.address_id)
FROM zod.public_hotlist_address ha
INNER JOIN (
    SELECT id FROM clarity.hotlist FINAL
    WHERE _peerdb_is_deleted = 0 AND status = 1 AND length(plate) > 3
      AND (org_id = :ORG_ID AND is_staged = false)
) oh ON oh.id = ha.hotlist_id
WHERE ha._peerdb_is_deleted = 0;

EXPLAIN indexes = 1
SELECT count()
FROM zod.public_address
WHERE _peerdb_is_deleted = 0
  AND id IN (
    SELECT DISTINCT ha.address_id
    FROM zod.public_hotlist_address ha
    INNER JOIN (
        SELECT id FROM clarity.hotlist FINAL
        WHERE _peerdb_is_deleted = 0 AND status = 1 AND length(plate) > 3
          AND (org_id = :ORG_ID AND is_staged = false)
    ) oh ON oh.id = ha.hotlist_id
    WHERE ha._peerdb_is_deleted = 0
  );
