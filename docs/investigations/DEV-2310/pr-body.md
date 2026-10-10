<!-- ticket-status -->
**Ticket:** [DEV-2310](https://insightlpr-team.atlassian.net/browse/DEV-2310)
**Phase:** done · **Route:** bug · **Mode:** normal
**Done:** intake · ultra-prep · gate · plan · provision · implement · review (98/100/100, 0 scoring defects) · agent-QA (PASS) · human-QA (PASS) · finalized
**Next:** merge (in code review)
**Open questions:** none
**Resolved questions:**
- Scope → Both surfaces (driver dot + admin markers) · human:Dalton 2026-08-06
- Persist trail lines too? → Yes, overlay both (live trail over the historical scan-path overlay) · human:Dalton 2026-08-06
- My Location button / no-kits banner? → Leave as-is · human:Dalton 2026-08-06
- Backend scoping positions by date range? → No, both endpoints range-agnostic (~24h cutoff) · code
- `useKitPositionPolling` a missed gating site? → No (grep-backstop false positive) · code
**Artifacts:** docs/investigations/DEV-2310/
<!-- ticket-claim: cleared @ done -->
<!-- /ticket-status -->
