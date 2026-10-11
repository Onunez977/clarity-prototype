# Dual-plan draft (preflight #23) — DEV-2659

You are an independent Ultra-Plan co-pilot. Read-only. Do not mutate files, commit, push, or touch Herd/fleet env. Cloud ClickHouse is READ-ONLY; do not write it.

Ticket: https://insightlpr-team.atlassian.net/browse/DEV-2659
Repo: insight-lpr/platform (this worktree at tip cdb90f8b).
Artifacts already present: docs/investigations/DEV-2659/{prep-context,plan,state,journal,precedents,question-gate}.*

## Task
Produce an **independent** Implement plan for DEV-2659 ship surface. Do not rubber-stamp the existing plan.md — re-read the PHP queries and schema, then write your own plan in markdown.

## Locked scope
- Both UniqueLprCaseClaimsQuery and LprRepossessionsQuery read denormalized billing (`clarity.billing_claim_events_v`), not zod.public_billing_events + JSONExtract + public_api_keys.
- Item 5 / AC-7 (drop forwarder_org_id from uniq key) is DEFERRED — out of ship.
- After is_internal=0, orgs CTE / insightlpr needle joins may be unused — confirm from code.
- Keep: uniqExact; single-pass; join_use_nulls=0 sentinels; neither class extends ClickhouseQuery; reconciliation Jul 2026 UTC 15128/14288/11975.
- Agent QA needs real Docker CH (not pgsql mirror). No Cloud writes. No product implement in this draft — plan only.

## Output
Write a concise plan covering: read surface, orgs path keep/delete, shared dedup approach, subtasks/files/tests, fleet Docker CH note, explicit non-goals. End with disagreements vs any existing docs/investigations/DEV-2659/plan.md you notice (convergences/divergences).
