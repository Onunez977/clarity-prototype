<!-- ticket-status -->
**Ticket:** [DEV-2385](https://insightlpr-team.atlassian.net/browse/DEV-2385)
**Phase:** reviewed (gate overridden) · **Route:** bug · **Mode:** normal
**Done:** intake (base=deploy) · prep · gate CLEAN · planned · implemented · RN7 (min 78) · human override
**Next:** verify blocked — this Cursor worktree is not a `.claude/worktrees` checkout, so bootstrap no-ops; `.env` is shared `zod` + ClickHouse Cloud. Need a Claude worktree before qa-prep.
**Open questions:** none
**Resolved questions:**
- Branch/PR base → `deploy` · human:Dalton 2026-08-18
- Q1 pathological shift → Option A bounded last-N summary · human:Dalton 2026-08-18
- Q2 N → 8 hours · human:Dalton 2026-08-18
- Q3 skip email/reason → N/A · human:Dalton 2026-08-18
- Q4 retries → `$tries = 2` · human:Dalton 2026-08-18
- Window end → `ended_at` (no last-scan probe) · human:Dalton 2026-08-18
- Empty truncated window → Log::warning, still send · human:Dalton 2026-08-18
- Truncated stats labels → last 8 hours, not Total · human:Dalton 2026-08-18
- Review gate → override RN7 min 78 · human:Dalton 2026-08-18
**Review:** RN7 min=78/90 · overridden · lanes 86 / 94 / 78 · residual [DEV-2387](https://insightlpr-team.atlassian.net/browse/DEV-2387)
**Artifacts:** docs/investigations/DEV-2385/
<!-- ticket-claim: daltonmccleery@gmail.com @ 2026-08-18T14:04:04Z -->
<!-- /ticket-status -->
