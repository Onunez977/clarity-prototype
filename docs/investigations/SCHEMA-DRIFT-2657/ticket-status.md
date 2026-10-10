# ticket-status — SCHEMA-DRIFT-2657

**Ticket:** SCHEMA-DRIFT-2657 (progress ticket [DEV-2657](https://insightlpr-team.atlassian.net/browse/DEV-2657), left In Code Review, assignee Dalton McCleery)
**Phase:** done
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · ticket provision (fleet Docker CH cite · slim) · Implement · Review (PASS min 90) · Verify/Build Record (comment 41731 · fleet :18125 prove)
**Next:** Undraft/CI (Jeff-skip; green CI = merge; no QA Ready)
**Open questions:** none
**Status:** Next Undraft/CI (Jeff-skip)
**Review:** rn=1 PASS min 90 (correctness 92 / conventions 90 / blast-radius 91). reviewed_sha `1787e0462e96af3e12dbb8743006fda2f271556f`. Act-on Implement: NONE.
**Build Record:** Jira comment 41731 on DEV-2657.
**Ship:** `database/schema/clickhouse-schema.sql` mirrors chlocal `a9e366bb` migration 116. Hashes `ba93b813…` / `383185ef…`.
**Preflight #18:** fleet Docker CH `127.0.0.1:18125` / `clarity_schema_drift_2657` / 25.8.11.66 — tip apply + DESCRIBE seven fields + both projections SATISFIED. MV CREATE soft (missing public_orgs_latest).
**Closeout:** Jeff-skip. Undraft PR #461; green CI = merge gate. No QA Ready. Leave Docker CH UP.
**Soft:** Fleet 25.8 vs CI 26.2; `first_declined_at` vs prose `first_closed_positive_at`; OKF hotlist model lag; chlocal PR 37 open; Implement :8123 scratch superseded.
**PR:** https://github.com/insight-lpr/platform/pull/461 (draft).
**Artifacts:** docs/investigations/SCHEMA-DRIFT-2657/
