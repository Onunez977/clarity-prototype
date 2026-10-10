# DEV-2737 Claude co-pilot Ultra-Plan draft (read-only)

You are a co-pilot planner. Produce a concise verify-first plan draft for DEV-2737. Do not mutate env, commit, or push. Do not invent numbers.

## Locked (Question Gate · Gandalf/Dalton)
- Q1: Org **14** (Brad Webb / premieradjusters)
- Q2: Reconcile UI ≈ **509** on **This Month**; production/Cloud ClickHouse; **admin-impersonating Brad Webb**; card = LPR Repos This Month (`db_repos` / `LprRepossessions`). Do not invent a more precise integer than ~509 unless Cloud measure returns one.

## Ticket AC
1. Document exact query path(s) feeding LPR Repos for org/month.
2. Reconcile UI vs underlying query (± Cloud measure).
3. If wrong → follow-on fix ACs with evidence; if correct → proof notes.

## Prep already found
- Card `lpr-repossessions` / `LprRepossessions`; This Month → `db_repos` current UTC month.
- `LprRepossessionsQuery` reads `clarity.billing_claim_events_v` (`is_internal=0`, `event_type=case-claim`); org via auth org_id as forwarder or agent; join status-3 hotlist + status log within claim_date-7 days; does not read clarity.orgs.
- The prior LPR Repossessions coverage work is on main. Branch path main→main. Cloud CH READ-ONLY.

## Output
Markdown plan draft covering: verify steps, Cloud CH READ-ONLY measure SQL shape for org 14 This Month, what constitutes match vs mismatch vs follow-on fix, no product PHP unless measure proves wrong and fix is in scope (prefer measure-first; product change only via follow-on ACs if AC-3). Keep simple (#24). Cite files.
