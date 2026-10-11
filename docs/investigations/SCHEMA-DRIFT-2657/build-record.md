# Insight AI — Build Record

> Mirror of Jira comment **41731** on DEV-2657 (posted 2026-09-24 ~14:15 CDT). Track: SCHEMA-DRIFT-2657 (thin platform dump mirror). Progress ticket DEV-2657.

**Sprint 41 · Repo** `insight-lpr/platform` · **PR** https://github.com/insight-lpr/platform/pull/461 (**DRAFT** — stays draft until Undraft/CI)

## TL;DR

Platform dump `database/schema/clickhouse-schema.sql` now mirrors chlocal tip `a9e366bb` migration 116 for `clarity.hotlist` (six `first_*_at` + `_peerdb_hsl_synced_at` + `prj_id_lookup_v2`) and `clarity.hotlist_incremental_mv`. Statement hashes match plan. Fleet Docker ClickHouse at `127.0.0.1:18125` (container `clarity-qa-clickhouse-schema-drift-2657`, DB `clarity_schema_drift_2657`, CH **25.8.11.66**) accepted remapped tip CREATE; DESCRIBE + `system.projections` prove the seven new fields and both projections. Review rn=1 PASS min 90. **Jeff-skip:** after this Build Record → Undraft/CI; green CI = merge gate; no QA Ready. Definition prove only (no Agent QA / no site / no PG).

## WHAT changed

* **Ship file only:** `database/schema/clickhouse-schema.sql`
  * `CREATE TABLE clarity.hotlist` — six `Nullable(DateTime64(6))` columns `first_open_at`, `first_hold_at`, `first_repossessed_at`, `first_declined_at`, `first_closed_at`, `first_invalid_at`; `_peerdb_hsl_synced_at DateTime64(9) DEFAULT now64()`; `PROJECTION prj_id_lookup` unchanged; `PROJECTION prj_id_lookup_v2` adds `first_repossessed_at` only; engine `ReplacingMergeTree`
  * `CREATE MATERIALIZED VIEW clarity.hotlist_incremental_mv` — hsl watermark, status-log arm (`hsl_deduped`), six first_* columns threaded
* Docs under `docs/investigations/SCHEMA-DRIFT-2657/` (plan / state / ticket-status / this Build Record)

**Product tip / reviewed_sha:** `1787e0462e96af3e12dbb8743006fda2f271556f` (schema implement + prove stamp)
**Docs tip / PR HEAD (pre-Verify stamp):** `350c67af83221b2f782f4b31c66875ab355c5ff3`
**DDL pin:** chlocal `a9e366bb21fee0702fd827a9d7c57488be57fc69` · migration `116_dev_2657_hotlist_status_timestamps.sql` · sections mirrored: ADD COLUMN list, ADD PROJECTION prj_id_lookup_v2, CREATE MV · excluded: MATERIALIZE COLUMN/PROJECTION, DROP VIEW, commented DROP PROJECTION, backfill script
**Material app/tests/schema since reviewed_sha:** none (docs-only commits after product tip)

## WHY

* Platform dump on `main` lagged the frozen chlocal migration-116 shape — fleet provision DESCRIBE lacked `first_*_at` / `_peerdb_hsl_synced_at` (that absence *was* the drift).
* Thin mirror only: no Cloud apply, no backfill, no product PHP, no DEV-2660. Cloud ClickHouse stays READ-ONLY forever.
* Preflight #18: definition prove must land on **real fleet Docker CH** (`:18125`), not only agent scratch `:8123`.

## HOW numbers were derived

* **Statement hashes** (tip file, whitespace-exact CREATE statements):
  * `CREATE TABLE clarity.hotlist` sha256 `ba93b813d584a900f4076a92550d26936a5242a025d637caaf7535250024e875` — **MATCH** plan
  * `CREATE MATERIALIZED VIEW clarity.hotlist_incremental_mv` sha256 `383185efb27168c87bd49ac7bdbad0338f456ed8fb598bdf47e1b32e895511b8` — **MATCH** plan
* **Fleet Docker CH prove (Preflight #18)** — 2026-09-24 ~14:14 CDT:
  * ping `Ok.` · version `25.8.11.66` · HTTP `127.0.0.1:18125` · container `clarity-qa-clickhouse-schema-drift-2657` · DB `clarity_schema_drift_2657`
  * Applied remapped tip objects (`clarity.` → `clarity_schema_drift_2657.`, `zod.` → `zod_schema_drift_2657.`): DROP VIEW IF EXISTS MV → DROP TABLE hotlist → CREATE remapped hotlist (**OK**)
  * DESCRIBE / `system.columns`: all seven new fields present with expected types (`Nullable(DateTime64(6))` ×6; `_peerdb_hsl_synced_at DateTime64(9) DEFAULT now64()`)
  * `system.projections`: `prj_id_lookup` select list unchanged (no `first_*`); `prj_id_lookup_v2` adds `first_repossessed_at` only; both `ORDER BY id`
  * Engine: `ReplacingMergeTree(_version, _peerdb_is_deleted)`
  * MV CREATE soft-failed: missing dep `zod_schema_drift_2657.public_orgs_latest` on slim fleet stand (zod has `public_orgs` / hotlist sources but not `public_orgs_latest`). **Hard bar** (table columns + projections) **SATISFIED**; MV prove is file+hash (+ soft flag)
  * `cloud_touched=false` · no Cloud write
* **Review rn=1:** scores **[92, 90, 91]** (correctness / conventions / blast-radius) · min 90 · threshold 85 · `reviewed_sha=1787e0462e96af3e12dbb8743006fda2f271556f` · `rerun_required=false` · act_on **NONE**
* **Thin conventions #16:** conventions **90** green; ship is `.sql` + docs only — no Pint required. OKF `okf/models/clickhouse/hotlist.md` still lacks `first_*` — soft OK (non-goal this PR)
* **No Agent QA** on this track (definition prove only; Jeff-skip)

## AC verdict (SCHEMA-DRIFT track — this PR)

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 Dump CREATE TABLE has six Nullable(DateTime64(6)) first_* + `_peerdb_hsl_synced_at DateTime64(9) DEFAULT now64()` | SATISFIED | hash `ba93b813…` + tip file + fleet DESCRIBE seven fields |
| AC-2 `prj_id_lookup` unchanged; `prj_id_lookup_v2` adds `first_repossessed_at` only | SATISFIED | tip SHOW CREATE + fleet `system.projections` |
| AC-3 `hotlist_incremental_mv` present with hsl watermark / status-log arm / six columns threaded | SATISFIED | file+hash `383185ef…`; fleet CREATE soft-flagged (missing `public_orgs_latest`) |
| AC-4 Pin fidelity a9e366bb / mig 116; excluded MATERIALIZE/DROP/backfill | SATISFIED | plan sections_mirrored / sections_excluded; dump only |
| AC-5 Preflight #18 real Docker CH fleet `:18125` definition prove | SATISFIED | apply + DESCRIBE + projections on fleet 25.8.11.66 |
| AC-6 Out-of-scope respected (no Cloud write, no backfill, no product PHP, no DEV-2660) | SATISFIED | ship file only; `cloud_touched=false` |
| AC-7 Thin conventions #16 | SATISFIED | conventions 90; no Pint needed; OKF lag soft OK |
| DEV-2657 full ACs (Cloud apply / backfill / product) | OUT_OF_SCOPE / NOT_COVERED | progress ticket scope elsewhere; do not HARD FAIL this dump PR |

## Soft flags (non-blocking)

* Fleet CH **25.8.11.66** vs Implement scratch / CI **26.2.x** — definition compatible; noted
* Naming: dump/chlocal `first_declined_at` (status 4) vs some Jira prose `first_closed_positive_at` — same status; dump name wins
* OKF hotlist model lacks `first_*` — non-goal this PR
* chlocal PR 37 still **OPEN** (DDL pin = tip commit, not merge)
* Implement scratch prove used agent `:8123` — **superseded** by this fleet `:18125` prove
* Fleet MV CREATE soft-fail: `zod_schema_drift_2657.public_orgs_latest` absent on slim stand; table+projections hard bar cleared

## What this ticket / PR did NOT do

* No ClickHouse Cloud write / ALTER / apply
* No backfill script or historical fill
* No product PHP / `LprRepossessionsQuery` (DEV-2660)
* No QA Ready flip · no undraft · no merge (next phase owns those)
* No Agent QA / site / Postgres stand (definition prove only)

## Next (Jeff-skip)

1. Undraft PR #461
2. Green CI = merge gate
3. Leave Docker CH `:18125` **UP** until Undraft/CI closeout tears it down
4. No QA Ready

**Gates (local):** Build Record + Rework run after comment **41731** recorded.
