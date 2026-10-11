<!-- ticket-status -->
**Ticket:** [DEV-2904](https://insightlpr-team.atlassian.net/browse/DEV-2904)
**Phase:** Undraft/CI
**Gate:** CLEAN
**Done:** intake · Ultra-Prep · Ultra-Plan · Provision · Implement · Review · Agent QA · Verify/Build Record · HITL GO
**Next:** QA Ready on green
**HITL:** GO locked (2026-10-05). UI liked. CSV AC check PASS (headers VIN, Plate, RDN Case, Claimed By, Repo Agent Name; filename `lpr-repossession-export-YYYY-MM-DD.csv`; parity export_rows==db_repos; grain is the triple).
**Ship:** CSV export of the expanded LPR Repossessions chart. One listing statement, string triple key, no maxRows. Aggregate chart SQL unchanged. Cloud ClickHouse not written. Export control is the native `ExportAction`, rendered from `getHeading()` when the card is expanded. Stock `filament-widgets::chart-widget` view. The copied chart blade is gone.
**Provisioned:** true
**Branch path:** main→main (First Glance **42732**)
**PR:** https://github.com/insight-lpr/platform/pull/501 (**READY**, base `main`)
**Assignees:** Jira Dalton McCleery (unchanged). GitHub assignee DaltonMcCleery.
**Plan tip:** `b6a99e6e`
**Implement tip:** `fcc6e1f760bf5244d4a3e52058c98d2782a049f0`
**Review:** rn=3 · scores 96/97/98 · min 96 · threshold 85 · PASS · act_on NONE
**Agent QA:** rn=3 PASS · 69/69 (304) real Docker CH `:18133` · Soft #27 https://github.com/insight-lpr/platform/pull/501#issuecomment-5995238168 (supersedes 5961356606)
**Build Record:** **43034** (prior **42918** on `dfbfd882`)
**Fleet ledger:** Path A LIVE LEFT UP · site :18090 · PG :15442 · CH :18133 · tear on QA Ready exit only
**Cloud ClickHouse:** not written. Read-only rule stands.
**Hard stops:** no vendor chart fork · native ExportAction heading · aggregate SELECT/GROUP BY/uniqExactIf identical · listing-only join-comment strip · CSV only · Cloud CH RO · no UniqueLprCaseClaims widen · blank Repo Agent Name · omit maxRows · string triple key
<!-- /ticket-status -->
