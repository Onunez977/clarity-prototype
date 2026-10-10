<!-- ticket-status -->
**Ticket:** [DEV-2659](https://insightlpr-team.atlassian.net/browse/DEV-2659)
**Phase:** Verify / Build Record **done**
**Done:** intake · Ultra-Prep · Question Gate · Ultra-Plan (+ dual-plan compare) · ticket provision (fleet Docker CH cite) · Implement · Review rn=1 · Agent QA FAIL (stale tip) · tip-fix Implement · Review rn=2 · Agent QA PASS (fleet `:18126`) · Verify / Build Record
**Next:** Undraft / CI / QA Ready
**Open questions:** none
**Sprint:** Sprint 41
**Ship:** Both funnel queries read `clarity.billing_claim_events_v` with `is_internal = 0`. Delete the orgs CTE and the insightlpr needle joins. Shared dedup triple. Item 5 / AC-7 out of ship. No `type IN (1, 2)` docblock: no orgs path remains.
**Review:** rn=2 PASS min 92 (95/92/93). `reviewed_sha` `3b9bc75aa083137f85a69302483c8c95acb5fbf8`. Act-on Implement: NONE.
**Agent QA:** PASS · Pint 10 · PHPUnit 189/707/0 skip · `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18126` · keyed `clarity_dev_2659` (31) · `cloud_touched=false` · Live NOT_COVERED soft.
**Build Record:** Jira comment `41825` · marker `Insight AI — Build Record`.
**PR:** https://github.com/insight-lpr/platform/pull/465 — **DRAFT** (stays draft until Undraft). Product tip `3b9bc75…` · PR head `4584b5a…`.
**Provision cite:** site `http://127.0.0.1:18083` `clarity-qa-app-dev-2659` GET `/app/login` → 200. PG `127.0.0.1:15435` `zod_dev_2659` + `zod_testing_dev_2659` user `clarity`. CH strategy=`docker` `clarity-qa-clickhouse-dev-2659` HTTP `:18126` keyed `clarity_dev_2659` (+ `clarity_testing_dev_2659`) + zod twins. `billing_claim_events` / `_v` / `_mv` present. `cloud_touched=false`. `mac_herd_refused=true`. Ledger `docs/investigations/DEV-2659/qa-prep-ledger.json`.
**SHOW CREATE VIEW:** open/non-blocking (view present on classic @ `:18126`).
**Assignees:** Jira Dalton McCleery (unchanged). GitHub `DaltonMcCleery`.
<!-- /ticket-status -->
