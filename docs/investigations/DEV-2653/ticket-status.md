<!-- ticket-status -->
**Ticket:** DEV-2653
**Phase:** Undraft / CI
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · ticket provision (fleet Docker CH) · Implement · Review RN=1 FAIL + fix · query-owned scan cache · Agent QA PASS (suite + real Docker CH #18) · Verify/Build Record (Jira 41498) · Review RN=2 PASS
**Next:** CI green → QA Ready (Jeff)
**Review:** RN=2 PASS on product tip `eddf64399` — min 88 ≥ 85 (correctness 91 · conventions 90 · blast-radius 88). `rerun_required=false`. Prior stamp HEAD `75b2ccd` was docs-only [skip ci].
**Agent QA:** PASS at `eddf64399`. Real Docker CH (#18): 223 passed / 0 failed / 0 skipped / 793 assertions — CLICKHOUSE_DRIVER=clickhouse 127.0.0.1:18123 · cloud_touched=false. Evidence on disk only.
**Open questions:** none
**Sprint:** Sprint 41
**Ship:** PHP cache sharing for ScanPlateStatsBatchQuery (primary); keep LprRepossessionsQuery shared keys; no UniqueLprCaseClaimsQuery billing-leg shared cache; closed-month settle on shared TTL ladder.
**Out:** Zod, Cloud ClickHouse writes, DEV-2659, DEV-2654.
**Schema on main:** 25870f75 billing_claim_events objects in clickhouse-schema.sql.
**Provision:** fleet Docker cite; cloud_ch_writes false.
**Jira assignee:** DaltonMcCleery
<!-- /ticket-status -->
