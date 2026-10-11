# DEV-2801 Gate 1 — Cloud ClickHouse READ-ONLY parity (Jul 2026)

**Status:** FINAL (Gate 1 EXIT · KEEP dual-join)  
**gate1_owner_exit:** true  
**Measured at:** 2026-10-06 21:32:11 CT (2026-10-07T02:32:11Z)  
**Host:** `zd69ju2h8d.us-east-2.aws.clickhouse.cloud:8443` (readonly_user)  
**cloud_touched:** true (SELECT / WITH…SELECT / SHOW DATABASES only)  
**cloud_ch_writes:** false  
**SELECT 1:** OK  
**SHOW DATABASES:** clarity + zod confirmed (also bizintel, guardian, system)  
**Prior DEV-2660 snapshot 9149:** reference only; live re-measure db_repos=**9159** (CDC moved).

## Decisions

| Decision | Value | Why |
| --- | --- | --- |
| **COLLAPSE vs KEEP dual-join** | **KEEP dual-join** | A meets Q1 (100% / Δ0) and read_bytes ≪ M0, but m1 stale_status3_ids=12 (mutable-status CDC — prefer B if A fails stale). B also meets Q1 but read_bytes 6,666,238,834 > M0 baseline 6,315,077,036. Neither candidate clears both parity and the bytes bar after the stale fixture → KEEP dual-join. |
| **FINAL on clarity.hotlist (if collapse)** | n/a (keep) | A fails m1 stale fixture; B exceeds M0 read_bytes |
| **{floor}** | KEEP (assumed) | Answer-neutral; DEV-2660 floor-off ~12.2GB |

## Conductor locks (Dalton via Galadriel)

| Q | Topic | Locked answer |
| --- | --- | --- |
| Q1 | Parity bar | 100% agreement + `db_repos`/`deduped` Δ0; memory ~190→245 MB OK if read_bytes ≤ baseline (DEV-2660) |
| Q2 | Window | Jul 2026 UTC platform-wide only (org=0) |
| Q3 | Cloud RO | Galadriel box-side via `/workspace/first-glance/secrets/ch-cloud-readonly.env` (Frodo does not source creds) |

## Window

| Param | Value |
| --- | --- |
| ts | 1782864000 … 1785542399 |
| event_date | 2026-07-01 … 2026-07-31 |
| floor | toDate('2026-06-24') |
| org | 0 (platform-wide) |
| event_type | `'case-claim'` |
| DBs | `clarity`, `zod` (confirmed via SHOW DATABASES) |

## SQL shapes (Gate 1 measure)

**M0 baseline (shipped):** claims → `BillingClaimDedup` → `zod.public_hotlist FINAL` (status=3) → `clarity.hotlist` (no FINAL, floor) on `ch.id = h.id AND h.id != 0`; predicate `toDate(ch.first_repossessed_at,'UTC') >= claim_date - 7`.

**Candidate A:** single `clarity.hotlist` join NO FINAL — `(org_id, vin)` + status=3 + delete + floor; `d.agent_org_id != 0`.

**Candidate B:** same WITH FINAL.

Raw SQL + HTTP dumps live on investigation disk only (`/workspace/docs/investigations/DEV-2801/measure-raw/`, not committed). Soft #25 cite is this summary + `cloud-parity-jul-2026-decisions.json`.

---

## Agreement

| Metric | Value |
| --- | --- |
| base_n | 9159 |
| cand_A_n | 9159 |
| cand_B_n | 9159 |
| both_n (A) | 9159 |
| agreement % (A) | **100.0% (9159/9159)** |
| base_only / cand_only (A) | 0 / 0 |
| both_n (B) | 9159 |
| agreement % (B) | **100.0% (9159/9159)** |
| base_only / cand_only (B) | 0 / 0 |
| Δ db_repos (A) | **0** (9159 − 9159) |
| Δ db_repos (B) | **0** (9159 − 9159) |
| Δ deduped | **0** (11975 − 11975) |

---

## M0 / Candidate counts

| Variant | deduped | db_repos | read_rows | read_bytes | memory_usage |
| --- | ---: | ---: | ---: | ---: | ---: |
| M0 baseline | 11975 | **9159** | 267,215,682 | 6,315,077,036 (~5.88 GB) | 237,551,691 (~227 MB) |
| Candidate A (no FINAL) | 11975 | **9159** | 103,544,499 | 1,890,248,207 (~1.76 GB) | 21,392,322 (~20 MB) |
| Candidate B (FINAL) | 11975 | **9159** | 105,949,829 | 6,666,238,834 (~6.21 GB) | 225,365,606 (~215 MB) |

Bytes vs M0: A **−70.1%**; B **+5.6%** (fails ≤ baseline). Memory: A ~20 MB; B ~215 MB; M0 ~226 MB (all within Q1 memory OK if bytes ≤ baseline).

---

## Diagnostics

### M1 stale status

| Metric | Value |
| --- | ---: |
| stale_status3_ids | **12** |

Non-zero → Candidate A fails stale-status fixture (mutable-status CDC risk without FINAL). Prefer B path per decision rule; B then fails read_bytes ≤ M0.

### M2 tombstone gap

| Metric | Value |
| --- | ---: |
| clarity_live_missing_or_not_status3_in_public | 0 |
| public_live_missing_or_not_in_clarity_floor | 15,327,684 |

Asymmetric CDC (public ≫ clarity under floor) — dual-join retains public_hotlist hop for that reason.

### M3 VIN/org drift

| Metric | Value |
| --- | ---: |
| vin_mismatch | 0 |
| org_mismatch | 0 |
| clarity_empty_vin | 5 |
| public_empty_vin | 5 |
| paired_ids | 870,568 |

### M4 coverage

| Metric | Value |
| --- | ---: |
| h_matched_triples | 9204 |
| h_matched_ch_missing | 273 |
| h_and_ch_matched | 9159 |

h_and_ch_matched equals live db_repos (9159). 273 public-matched triples lack clarity FRA in floor window (not counted under either shape's db_repos predicate).

---

## Decision rule application

- **COLLAPSE** if A or B meets Q1 (100% agreement + Δ0) **and** read_bytes ≤ M0; prefer A only if m1 stale fixture passes (expect B if A fails stale).
- **KEEP dual-join** otherwise, citing CDC / FINAL / VIN / cardinality.

| Candidate | Q1 (100%+Δ0) | read_bytes ≤ M0 | m1 stale OK | Collapse? |
| --- | --- | --- | --- | --- |
| A (no FINAL) | yes | yes (−70%) | **no** (12) | no (stale fixture) |
| B (FINAL) | yes | **no** (+5.6%) | n/a | no (bytes bar) |

**Outcome: KEEP dual-join** + docblock update is the complete Gate 1 outcome (valid per spec).

## Provenance

- Tip: `e8657f12` (Implement KEEP) · branch `cursor/dev-2801-cm-lpr-hotlist-single-join` · PR #504 DRAFT
- Spec: `/workspace/docs/investigations/DEV-2801/DEV-2801.md`
- PHP render: investigation-disk `measure-raw/m0-baseline-windowSql.rendered.sql` from `LprRepossessionsQuery::windowSql` (not in PR tree)
- Captures: investigation-disk `measure-raw/*-headers.txt` + `*-body.txt` (not committed)
- Auth: `readonly_user` @ Cloud :8443 via `ch-cloud-readonly.env` (no writes)
