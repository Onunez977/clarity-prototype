<!-- ticket-status -->
**Ticket:** [DEV-2923](https://insightlpr-team.atlassian.net/browse/DEV-2923)
**Phase:** Undraft/CI
**Gate:** CLEAN
**Done:** intake · Ultra-Prep · Ultra-Plan · Provision · Implement · Review (rn=1 PASS) · Agent QA · Verify · Build Record · HITL GO locked
**Next:** QA Ready on green
**Ship:** Logging only. `LoggingClickhouseHttpClient` + `LoggingClickhouseConnection` installed from `AppServiceProvider::booted()`. Soft #18 forced CH timeout log context on fleet twin `:18134` — `ch_code` 159 + `TIMEOUT_EXCEEDED` + SQL/caller on db-error. No timeout budget, product query, or dashboard-constant change. Cloud CH not written.
**Branch path:** main→main (First Glance **43033**)
**PR:** https://github.com/insight-lpr/platform/pull/502 (**READY**, base `main`)
**Assignees:** Jira Dalton McCleery (unchanged). GitHub assignee DaltonMcCleery.
**Plan tip:** `66a0aaad` · **Product tip:** `42389469eb9d1044c925d021eb98f1efce363ff1` · **Reviewed sha:** `8b1b447d06e62d8dfdd9812f9bc7cd7e5e288030`
**HITL:** GO locked
**Review:** rn=1 · scores 95/93/97 · min 93 · threshold 85 · PASS · act_on NONE · rerun_required=false
**Agent QA:** PASS · 11/11 (96) real Docker CH `:18134` · Soft #18 receipt `soft-18-twin-prove.md` · log `phpunit-agent-qa-rn1.log`
**Build Record:** Jira comment **43096** (`Insight AI — Build Record`)
**Soft #18:** PASS on fleet twin `:18134` (`CLICKHOUSE_DRIVER=clickhouse`). `wait_end_of_query=1` test-local only.
**Soft #27:** N/A (logging-only)
**Preflight:** #16 PASS · #18 PASS · #25 does_not_fire · #26 main→main · #27 N/A
**Fleet ledger:** Path A LEFT UP :18091/:15443/:18134 until QA Ready tear · `docs/investigations/DEV-2923/qa-prep-ledger.json` · `cloud_touched=false`
**Parallel:** DEV-2904 Path A LEFT UP (:18090/:15442/:18133) — untouched
**Hard stops:** logging only · SQL truncate 4096 · platform-only · Cloud CH RO · no timeout budget change · `wait_end_of_query` not in prod — held
**Act on:** NONE
**Cloud ClickHouse:** not written. Read-only rule stands.
<!-- /ticket-status -->
