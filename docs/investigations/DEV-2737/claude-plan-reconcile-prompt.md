# DEV-2737 Opus reconcile (Claude-first #23 step 3)

You are reconciling the primary Opus Ultra-Plan draft with the CloudAgent review. Produce `claude-plan-reconcile.md` content on stdout only (read-only tools). Do not commit, push, mutate env, or invent numbers.

## Inputs (read these files)
- `docs/investigations/DEV-2737/claude-plan-draft.md` — PRIMARY draft
- `docs/investigations/DEV-2737/cloud-plan-review.md` — CloudAgent review (verdict holds-with-gaps)
- `docs/investigations/DEV-2737/cloud-measure-org14-sep2026.md` — Cloud CH RO measure
- `docs/investigations/DEV-2737/cloud-measure-org14.sql` — SQL that produced 533
- `docs/investigations/DEV-2737/plan.md` / `plan.json` — prior CloudAgent-authored counsel plan (NOT authority)
- Live code citations as needed (Read/Grep/Glob only)

## Locks
- Org **14** (Brad Webb / premieradjusters)
- UI ≈ **509** This Month, admin-impersonating, LPR Repos `db_repos`, Cloud/prod CH
- Branch path main→main (First Glance 42082)
- `product_php=false`; preflight #25 does_not_fire
- Cloud measure: Sep 2026 bucket `2026-09-01` deduped **630** / db_repos **533** vs UI ~509 → mismatch_pending_v1

## Output structure for claude-plan-reconcile.md
1. **Adopted from review** (bullets + why)
2. **Rejected from review** (bullets + why) — reject scope inflation (#24)
3. **Adopted from primary draft** that review affirmed
4. **Measure decision** — 533 vs ~509; V1 required; follow-on AC text; no fix PHP
5. **Final plan directives** — concrete bullets that must appear in plan.json/plan.md (so a human can finalize without re-judging)
6. **Hard stops / non-goals**

Keep it short and decisive. No product PHP. No inventing architecture.
