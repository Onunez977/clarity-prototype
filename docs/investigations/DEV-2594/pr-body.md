<!-- ticket-status -->
**Ticket:** [DEV-2594](https://insightlpr-team.atlassian.net/browse/DEV-2594)
**Phase:** done · **Route:** bug · **Mode:** normal · **no_spawn**
**Done:** intake · prep · gate · plan · implement · review · verify · human QA · handoff
**Next:** code review / merge
**Open questions:** none
**Resolved questions:**
- Viewport → cover both · human:Dalton 2026-09-18
- Click target → cover both · human:Dalton 2026-09-18
**Not in this ticket:** Day/Trip overlay z-parity
**Artifacts:** docs/investigations/DEV-2594/
<!-- ticket-claim: cleared -->

## Summary
Fix Scout Live Hits clickability: reliable `--scout-controls-h`, Live Hits above controls (`z-26`), collapse-on-select, elevated Hit Details sheet.

## Test plan
- [x] Desktop: newest Live Hits row opens Hit Details
- [x] Compact: newest row opens Hit Details
- [x] Map logo marker opens Hit Details
- [x] Controls still usable when Live Hits collapsed
