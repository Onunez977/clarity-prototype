# DEV-2659 — Claude dual-plan compare (preflight #23)

**When:** 2026-09-24 ~19:05 CT  
**Authoritative plan:** `plan.md` / `plan.json` on PR 465 @ `cdb90f8b`  
**Draft:** `claude-plan-draft.md` (headless Claude, Mac worktree, read-only)

## Convergences (adopted / already in primary)

- Read surface `clarity.billing_claim_events_v` with `is_internal = 0`; alias `org_id` → `forwarder_org_id`.
- Delete orgs CTE + insightlpr needle joins + `kept`; no `clarity.orgs` adopt.
- Keep `ts BETWEEN`; add `event_date` prune bound; keep three sentinels; `join_use_nulls` stays 0.
- Single-pass; pre-claim `oc` still referenced twice; neither class extends `ClickhouseQuery`.
- Item 5 / AC-7 out; no cache retune; hotlist legs stay zod; Docker CH is QA done-signal (not pgsql); Cloud read-only.
- Reconciliation targets 15128 / 14288 / 11975 cited, not re-measured here.

## Divergences — judgment

| # | Draft proposal | Judgment |
|---|----------------|----------|
| 1 | t0: add `clarity.billing_claim_events` to `RequiresClickhouse::CLICKHOUSE_TABLES_TO_TRUNCATE` | **Adopt into Implement notes** — real order-dependence risk; primary plan omitted it. Record in journal; Frodo must do before seeding tests. Does **not** reopen Question Gate. |
| 2 | Update `UniqueScansToCaseClaimsQueryTest` needle + no `billing_claim_events_v` in SQL comments | **Adopt** — primary missed this class; silent CI fail otherwise. |
| 3 | Helper = `BillingClaimSource` (triple + shared WHERE) vs primary `BillingClaimDedup` (triple + repos CTE) | **Keep primary helper name/shape for ship**, but **adopt draft’s warning**: shared WHERE/`is_internal` must not drift cross-class. Prefer asserting both rendered SQLs contain `is_internal = 0`. Optional: fold `whereSql()` into helper later without renaming if Implement prefers draft cut — not a plan rewrite. |
| 4 | Docblock MV semantics (frozen `is_internal`, 8-day api-key window, tombstone/`_peerdb_is_deleted` unexpressible on `_v`) | **Adopt as Implement docblock / journal risk trail** — raise on ticket in progress comment if missing; do not change ship ACs. |
| 5 | Provision hazard: literal `clarity.`/`zod.` inside view/MV bodies vs keyed `clarity_{wt}` | **Adopt as Provision prove step** — `SHOW CREATE VIEW` on keyed DB must resolve keyed names (or document rewrite). Soft-block Agent QA if broken. |
| 6 | AC-3 wording “delete clarity.orgs” vs never-read | **Journal note only** — decision table already “do not adopt”; leave plan.md unless a one-line scrub is cheap mid-flight. |
| 7 | t5 schema-contract test + coverage-loss framing for needle tests | **Soft adopt** — recommended Implement extras; not gate. |
| 8 | PHP `toDate('Y-m-d')` literals for `event_date` bound (cite `{floor}` idiom) | **Adopt preference** into Implement — primary already requires the bound; draft pins how. |

## What was rejected

- Treating Claude draft as the authoritative plan (Frodo takes `plan.json`/`plan.md` only).
- Reopening Question Gate / changing AC-7 deferred lock.
- Inventing Mac Herd env or Cloud CH writes.
- Sharing repos `deduped` CTE into a helper as a hard requirement of the draft’s alternate cut (single caller — leave with repos unless Implement consolidates).

## Done-signal for #23

Draft + this compare exist. Primary plan unchanged as authority. Provision may proceed citing Galadriel fleet ledger.
