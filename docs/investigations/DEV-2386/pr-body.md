<!-- ticket-status -->
**Ticket:** [DEV-2386](https://insightlpr-team.atlassian.net/browse/DEV-2386)
**Phase:** verified · **handoff — awaiting human QA** · **Route:** feature · **Mode:** normal
**Done:** intake — prep — gate CLEAN — plan — provision — implement — review RN1–RN4 (gate failed; operator override) — verify (agent-qa PASS; resource not-measured at prod scale)
**Next:** Human QA (Cloud AC-2/3, prod-host AC-4/AC-6). Then `/ticket DEV-2386 --resume` for handoff. `rerun_required` still true — done-gate will fail until a passing review or a later override.
**Open questions:** none
**Resolved questions:**
1. Confirm org 14 is Scout-enabled app-side → Doesn't matter. · human:Dalton 2026-08-18
2. Where are AC-2 / AC-3 / AC-4 measured → Production ClickHouse (prod host for AC-4). · human:Dalton 2026-08-18
3. Design → both matchers on scout_open_addresses, CROSS JOIN bbox, schedule uncomment. Staleness gate withdrawn 2026-08-19. · human:Dalton
4. 973k sizing figure. · human:Dalton 2026-08-18
5. Sibling wiki updated here. · human:Dalton 2026-08-19
- Operator override: `--force --only=verify` despite review MIN 72 · human:Dalton 2026-08-19
**Sizing gate:** fired (18 files >6). Operator override: one PR (Phase 2 Q3).
**Artifacts:** docs/investigations/DEV-2386/ · `.context/qa-plan-human.md`
<!-- ticket-claim: daltonmccleery@gmail.com @ 2026-08-19T13:30:00Z -->
<!-- /ticket-status -->
