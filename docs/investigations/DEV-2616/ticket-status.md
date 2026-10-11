<!-- ticket-status -->
**Ticket:** [DEV-2616](https://insightlpr-team.atlassian.net/browse/DEV-2616)
**Phase:** Undraft / CI (in flight)
**Gate:** CLEAN (Verify/Build Record PASS · BR 42108)
**Done:** Ultra-Prep · Ultra-Plan · Provision · Implement (verify-only) · Agent QA · Verify / Build Record · Undraft
**Next:** CI green → QA Ready (PM)
**Open questions:** none
**Ship:** verify-only / no product PHP. AC-1 already on main (`clarityDb()`). Agent QA PASS on real Docker CH `:18129`. CM `/app` loads without clarity view shim; header kit **Active Kit Users 0 / 0 kits** (expected silent-0). Nine CM query classes clean. Ticket-out residuals not ported.
**product_php=false · no app/ diff · Review skipped (verify-only)**
**#18:** PASS cite Agent QA — `CLICKHOUSE_DRIVER=clickhouse` @ `:18129` · `cloud_touched=false`
**#25:** N/A (no query rewrite this tip)
**#16:** Pint N/A (docs-only)
**#27:** PASS — Agent QA UI evidence https://github.com/insight-lpr/platform/pull/471#issuecomment-5872392164
**Branch path:** main→main (First Glance **42081**)
**Tips:** docs/PR head on branch · verify-record `b906112…` · product checkout `79b8db4…`
**BR comment:** 42108
**Provisioned:** true — site :18086 · PG :15438 · CH :18129 (LEFT UP through CI) · ledger `docs/investigations/DEV-2616/qa-prep-ledger.json`
**PR:** https://github.com/insight-lpr/platform/pull/471 (**ready for review** — undrafted)
**Assignee:** DaltonMcCleery
**Jira assignee:** Dalton McCleery (unchanged)
<!-- /ticket-status -->
CI trigger after undraft (PR #471 already ready; prior tips skipped checks).
