-- DEV-2465 — Unique Scans to Case Claims: the canonical measurement query.
--
-- Mirrors DEV-2463's docs/investigations/DEV-2463/measure-claims-card.sql convention: this file
-- IS the shape the shipped class implements, kept so the number can be re-derived without
-- reading PHP. Run read-only against production ClickHouse Cloud as `readonly_user`.
--
-- ADOPTED GRAIN (operator decision 2026-09-09, "option C"): numerator AND denominator are both
-- counted on the (forwarder_org_id, agent_org_id, vin) triple. The denominator is exactly
-- UniqueLprCaseClaimsQuery's existing `deduped` stage, untouched. Fleet-wide Jul 2026:
--   11,573 / 11,975 = 96.6%
-- Re-measured against the SHIPPED shape below (review round 2, after FINAL was removed, and
-- again after review round 3's stats-removal + h-prune): 11,573 / 11,975 = 96.6% -- IDENTICAL
-- across all three shapes.
--
-- Why NOT orders / deduped (11,449 / 11,975 = 95.6%): that divides ORDERS by (agency, vin)
-- PAIRS. Measured consequence on an org-scoped card: 16 of 257 orgs render ABOVE 100%, worst at
-- 200%. Mechanism: 82 triples each span two hotlist orders, so where both have a pre-claim hit
-- the numerator gains 2 and the denominator 1. Counting both sides on the triple removes it.
--
-- ⚠ THE LEFT JOIN DEFAULT-FILL TRAP — the whole reason this file exists.
-- ClickHouse `join_use_nulls` defaults to 0, so a LEFT JOIN miss fills the non-Nullable
-- `scanned_at` with 1970-01-01 rather than NULL. 1970 precedes every claim timestamp, so the
-- naive predicate `first_scan < claim_ts` counts EVERY order with NO hit at all as
-- "seen before the claim". Measured: naive 11,819 vs correct 11,449 — the difference is exactly
-- the 370 orders with no hit (11,855 claimed - 11,485 with any hit). It inflates the card from
-- 96.6% to 99.7%, silently. The guard is `h.hotlist_id != 0` in the
-- flagged CTE below. The tell: the naive figure EXCEEDS the count of orders that have any hit
-- at all, which is impossible. Always cross-check a timing split against the has-any-match count.
--
-- REVIEW ROUND 3 (S1 + S4) — the shape below. `stats` (all_claims/successful) was DELETED: it
-- fed rate()'s numerator/denominator with values NOTHING reads, at the cost of a whole extra
-- unprunable scan of public_billing_events. Measured cost of removing it alone: -40.0M
-- read_rows, -1,746 MiB read_bytes, -430 MiB peak memory (106.8M/3,828 MiB/929.6 MiB peak ->
-- 66.8M/2,082 MiB/499.6 MiB peak). With `stats` gone, `h`'s hotlist_id IN (...) prune (removed
-- in round 2, R1, to cut `kept`'s expansion from three to two) was restored: it costs +40.5M
-- read_rows over the unpruned S1-only shape (back to ~107.3M, close to today's read volume) but
-- cuts peak memory to 233.9 MiB -- about a QUARTER of what ships today -- because the LEFT JOIN
-- build side below can't spill (max_bytes_in_join / join_algorithm are unset on
-- clarity_clickhouse). 29-entitled-org worst case: ~27 GB (today) -> ~14.5 GB (S1 alone) ->
-- ~6.8 GB (S1+S4, this file). All three shapes return the same 11,573 / 11,975 = 96.6%.
--
-- Substitute :org for a real org id to reproduce the per-org (card) reading; leave the
-- (:org = 0 OR ...) arm as 0 for the fleet-wide figure.

WITH
-- FINAL on orgs: it is a JOIN source, and without FINAL a renamed org contributes two rows and
-- fans out its claims x2. Same reasoning as UniqueLprCaseClaimsQuery's own docblock.
orgs AS (
    SELECT id, lower(replaceRegexpAll(coalesce(name, ''), '[^a-zA-Z0-9]', '')) AS n
    FROM zod.public_orgs FINAL
    WHERE _peerdb_is_deleted = 0
),
claims AS (
    SELECT
        ak.org_id                                                AS forwarder_org_id,
        JSONExtractInt(be.additional, 'claimed_by_org_id')       AS agent_org_id,
        upper(trimBoth(JSONExtractString(be.additional, 'vin'))) AS vin,
        JSONExtractInt(be.additional, 'hotlist_id')              AS hotlist_id,
        be.success                                               AS success,
        be.ts                                                    AS ts
    FROM zod.public_billing_events AS be
    INNER JOIN (
        SELECT id, org_id FROM zod.public_api_keys FINAL WHERE _peerdb_is_deleted = 0
    ) AS ak ON ak.id = be.api_key_id
    -- public_billing_events is a ONE-argument ReplacingMergeTree(_peerdb_version): FINAL does
    -- NOT drop its tombstones, so the explicit _peerdb_is_deleted = 0 is mandatory and is NOT
    -- interchangeable with FINAL (platform-gotchas PG-028).
    WHERE be._peerdb_is_deleted = 0
      AND be.event_type = 'case-claim'          -- the discriminator column is event_type, not type
      AND be.ts BETWEEN toUnixTimestamp(toDateTime('2026-07-01 00:00:00', 'UTC'))
                    AND toUnixTimestamp(toDateTime('2026-07-31 23:59:59', 'UTC'))
),
kept AS (
    SELECT c.*
    FROM claims AS c
    LEFT JOIN orgs AS fo ON fo.id = c.forwarder_org_id
    LEFT JOIN orgs AS ao ON ao.id = c.agent_org_id
    -- Reject insightlpr on EITHER side. NEVER filter titlemax in the counting path: it is a
    -- display flag, and filtering it is a -2.6% error (the DEV-2409 investigation).
    WHERE position(coalesce(fo.n, ''), 'insightlpr') = 0
      AND position(coalesce(ao.n, ''), 'insightlpr') = 0
      AND (0 = 0 OR c.forwarder_org_id = 0 OR c.agent_org_id = 0)   -- :org axis, 0 = fleet-wide
),
-- One row per (order, triple) with that order's earliest claim instant. NOTE: no
-- `hotlist_id != 0` filter here (review round 2, R1) -- it moved into `flagged`'s predicate so
-- this CTE can ALSO serve `deduped` (which never cared about hotlist_id) without a second
-- `kept` scan. `oc` is referenced TWICE below (review round 3, S4 restored the second
-- reference): once by `h`'s pruning subquery, once by `flagged`.
oc AS (
    SELECT hotlist_id, (forwarder_org_id, agent_org_id, vin) AS trip, min(ts) AS claim_ts
    FROM kept
    WHERE success AND vin != ''
    GROUP BY hotlist_id, trip
),
-- REVIEW CORRECTION (round 1, all three lanes, H1): the `hotlist_org_id = :org` predicate
-- this file previously carried here was REMOVED as a correctness defect, not kept as a
-- deliberate optimisation. kept's denominator admits a claim on EITHER axis
-- (forwarder_org_id = :org OR agent_org_id = :org), but hotlist_org_id is the single
-- order-owning org and can equal at most one of them. For every claim where forwarder !=
-- agent, the predicate zeroed out numerator credit for one participating org while that
-- org's claim still counted in the denominator -- rendering a silent 0.0%, indistinguishable
-- from the legitimate no-claims reading.
--
-- REVIEW CORRECTION (round 2, R4): FINAL, added in round 1 for H5, is REMOVED here.
-- REVIEW CORRECTION (round 3, S3): the reason recorded for that removal was wrong twice over.
-- (a) `_peerdb_is_deleted = 0` WITHOUT FINAL excludes the tombstone VERSION but leaves a prior
-- live version in place, so a deleted-but-unmerged hit CAN still contribute to min() -- min()
-- is duplicate-idempotent, not deletion-idempotent. (b) min() is only duplicate-idempotent if
-- scanned_at is immutable across CDC versions, which the 1970 default path below disproves.
-- FINAL stays removed anyway: re-measured against production, both with and without FINAL:
-- 11,573 / 11,975 identically, and FINAL suppresses PREWHERE movement here
-- (optimize_move_to_prewhere_if_final defaults to 0), forcing a merge-on-read across every
-- partition for no benefit measured on this population. The deleted-but-unmerged-hit risk is
-- accepted (it can only ever inflate the numerator), not fixed.
--
-- ADDED (round 3, S3): `scanned_at > toDateTime(0)`. hits_cold.scanned_at is non-Nullable with
-- no DDL default, but `_peerdb_scan_synced_at` DEFAULTs to toDateTime('1970-01-01') -- a row
-- synced before its scan facet lands can read scanned_at at that 1970 default, which precedes
-- every claim and makes min() return it. Same failure mode as the hitless-order 1970
-- default-fill below, arriving through a door that guard can't cover because the join
-- genuinely matches. One line, unconditionally safe: a 1970 scan is not a real sighting.
--
-- REVIEW CORRECTION (round 2, R1) then RESTORED (round 3, S4): the
-- `hotlist_id IN (SELECT DISTINCT hotlist_id FROM oc WHERE hotlist_id != 0)` pruning predicate
-- was removed in round 2 because it was a second textual reference to `oc`, which was how
-- `kept` expanded three times instead of two. Round 3 deleted `stats` (S1) instead -- which
-- was the OTHER source of that third expansion -- making the prune affordable again at the
-- SAME two-reference floor. See the file header for the measured memory win.
--
-- REVIEW DECISION (round 2, R6, operator): no `kit_org_id` predicate. ANY Insight camera counts
-- toward pre_claim, not only the viewing org's own -- the approved metric wording is "seen by
-- OUR cameras first", where "our" means Insight's network, and that fleet-wide reading is the
-- only one that reproduces the 96.6% figure above.
h AS (
    SELECT hotlist_id, min(scanned_at) AS first_scan
    FROM clarity.hits_cold
    WHERE _peerdb_is_deleted = 0
      AND scanned_at > toDateTime(0)
      AND hotlist_id IN (SELECT DISTINCT hotlist_id FROM oc WHERE hotlist_id != 0)
    GROUP BY hotlist_id
),
flagged AS (
    SELECT
        oc.trip AS trip,
        -- h.hotlist_id != 0 IS THE GUARD. Without it the epoch default-fill makes every
        -- hitless order look pre-claim. Do not remove it; a test pins this.
        --
        -- REVIEW ROUND 4 (T3): the companion `oc.hotlist_id != 0` conjunct was DELETED here and
        -- in the shipped class. It was unconditionally unreachable: `h` is built from
        -- `hotlist_id IN (SELECT DISTINCT hotlist_id FROM oc WHERE hotlist_id != 0)`, so `h`
        -- can never hold a 0 row; and the join is a plain equality, so even an unpruned `h`
        -- would answer a 0 with `h.hotlist_id = 0`. Either way `h.hotlist_id != 0` is already
        -- false for a hotlist-less claim. Answer-neutral -- 96.6% is unchanged by the deletion.
        --
        -- The guard must stay in the SELECT list, NOT in WHERE: moving it would turn the LEFT
        -- JOIN into an effective inner join and drop hotlist-less trips out of uniqExact(trip),
        -- silently shrinking the denominator.
        (h.hotlist_id != 0 AND h.first_scan < toDateTime(oc.claim_ts)) AS pre_claim
    FROM oc
    LEFT JOIN h ON h.hotlist_id = oc.hotlist_id
),
-- REVIEW CORRECTION (round 1, H2): all_claims/successful computed in ONE pass over `kept`
-- (the `stats` CTE, at the time).
-- REVIEW CORRECTION (round 3, S1): `stats` is DELETED outright. Nothing downstream reads
-- all_claims/successful -- the card's rate() only ever reads deduped/pre_claim -- so computing
-- them cost a whole extra unprunable scan of public_billing_events for two discarded integers.
pre AS (
    SELECT uniqExact(trip) AS deduped, uniqExactIf(trip, pre_claim) AS pre_claim FROM flagged
)
SELECT
    pre.pre_claim AS numerator,       -- Jul 2026 fleet-wide: 11,573
    pre.deduped AS denominator,       -- Jul 2026 fleet-wide: 11,975  (= deduped)
    round(pre.pre_claim / pre.deduped * 100, 1) AS rate_pct   -- Jul 2026 fleet-wide: 96.6
FROM pre;

-- ---------------------------------------------------------------------------------------------
-- CONTROL — the naive form, kept deliberately so the trap stays reproducible. Swapping the
-- flagged CTE's predicate to just `h.first_scan < toDateTime(oc.claim_ts)` yields 11,819 orders
-- / 99.7%, i.e. +370 hitless orders counted as seen. If a future change makes these two agree,
-- the guard has been lost.
-- ---------------------------------------------------------------------------------------------

-- =====================================================================================
-- REVIEW ROUND 5 (M2) — does hit QUALITY belong in the numerator?
--
-- Raised by a review lane: `h` counts ANY clarity.hits_cold row, including ones an operator
-- explicitly rejected (`disposition IN ('Invalid Scan','Invalid')`) and fuzzy `hit_type='P'`
-- Probable Matches. Because the numerator takes min(scanned_at) over an unbounded lookback,
-- one old rejected/fuzzy read can flip an order to "seen before the claim". It can only ever
-- INFLATE the rate. okf/models/clickhouse/hits_cold.md documents validDisposition() as the
-- canonical valid-hit rule, and the card applies no filter at all -- so this was an open
-- data-semantics question, not a recorded decision.
--
-- MEASURED read-only against production (readonly_user), Jul 2026 fleet-wide, both variants
-- rendered from the SHIPPED class via reflection rather than retyped:
--
--   as-built          deduped=11,975  pre_claim=11,573  rate=96.64%
--   quality-filtered  deduped=11,975  pre_claim=11,571  rate=96.63%
--
--   DELTA: -0.01 pp. 2 of 11,573 orders reclassified. Denominator unchanged (the filter
--   touches only the numerator's hit pool).
--
-- The variant predicate measured was, injected into `h`'s WHERE:
--   AND (disposition IS NULL OR disposition NOT IN ('Invalid Scan', 'Invalid'))
--   AND hit_type != 'P'
--
-- DECISION: keep as built, unfiltered. The effect is immaterial on this population (2 orders),
-- and "our cameras saw this vehicle" reads naturally as any camera read. Recorded here rather
-- than left implicit so a future reader knows the question was asked and answered with a
-- measurement. Re-measure before trusting this if the disposition mix ever shifts materially.
--
-- This run also RE-VERIFIES AC-1 on the post-T3 shape, which had not been re-measured after
-- the dead conjunct was deleted: 11,573 / 11,975 = 96.64%, unchanged. T3 is answer-neutral.
-- =====================================================================================
