# DEV-2739 — Dual-plan compare (#23)

**Authoritative plan:** `plan.md` / `plan.json` (Ultra-Plan after QG CLEAN).
**Claude draft:** `claude-plan-draft.md` (independent co-pilot; not authority).
**Date:** 2026-09-25 ~14:45 CT

## Convergences

- Measure-first; no invented root cause; Cloud CH READ-ONLY.
- Org 14 impersonation → Path B locked as list mode, not cause.
- Kit-scoped via `Kit::getKits` / confirm `org_id = 14`; not `hotlist_org_id = 14`.
- Pin Hit date Today to 2026-09-25 local midnight → 13:54 CT; `hits_hot` no FINAL; disposition `(NULL OR Accessible)`.
- Classify: zero geo or no qualifying `recovery_address` → stop for Dalton; qualifying rows + UI N/A → bug candidate.
- Smallest single Path B edit (controller / blade / formatter); no architecture rewrite; DEV-1611 stays Done.
- Path A contrast only when Path B has no qualifying row; do not pick a winner (Q3 defer).

## Divergences (Claude draft vs plan)

| Claude | Authority |
| --- | --- |
| **M0 browser Network/Elements before any CH query** (POST fired? status? `[]` vs values; blade `x-data` vs bare N/A) | **Adopt** into t1 as free first step — three classes resolve without Cloud bytes. |
| Both-columns-N/A eliminates “blank `formatted_address` with miles” | **Adopt** as diagnostic note (blades use different truthiness). |
| Mirror-freshness control (`count`/`max(_peerdb_synced_at)` on all `recovery_address FINAL`) | **Adopt** into t1 when Path B ids look empty — separates pipeline vs Org 14. |
| Status stratification (Open vs Repossessed hotlists × recovery_address coverage) | **Adopt** as optional t1 cut when Path B empty — feeds Q3 packet only; **not** a root-cause claim. |
| Path A contrast broken out by `type_id` and `(0,0)` sentinel | **Adopt** shape for the disagreement packet if Path A contrast runs. |
| §5.3 Postgres Path A read inside controller if Q3 picks Path A; forbid CH `public_address` scan | **Reject as plan branch now** — Q3 is deferred; baking a source-swap shape past AC is scope creep (#24). Revisit only after Dalton Q3. |
| Method-level `RequiresClickhouse` on CH tests; rebuild `public/build` if blades/JS ship | **Adopt** into t3 test notes when t3 runs. |

## What we adopted into the authoritative plan

1. t1 starts with M0 browser observation, then Cloud CH / PG measure as already specified.
2. t1 adds mirror-freshness when Path B appears empty.
3. t1 may stratify by `hotlist_status` for a Q3 packet only — never as invented cause.
4. Diagnostic: both-column N/A ⇒ not “miles present + blank address text”.
5. t3 test notes: method-level RequiresClickhouse; rebuild assets only if UI branch ships.

## What we rejected

- Pre-planning a Path A controller swap or CH EWKT extract path before Q3.
- Treating Open × `type_id = 2` as proven cause (hypothesis for measure only).
- okf/ edits mid-flight.
- Any product code / provision in this compare commit.

## Verdict

Authoritative `plan.md` / `plan.json` remain the ship plan, with the light t1/t3 amendments above. Claude draft alone is never a done-signal.

## Provision hold (external)

Standing lock from fleet: **do not Provision** while Jeff FAIL / Off GO remains open on DEV-2736 / platform#468. Dual-plan may finish. No fleet ledger request and no `phase=provisioned` until Gandalf clears that park.
