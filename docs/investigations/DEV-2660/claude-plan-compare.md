# DEV-2660 — Claude dual-plan compare (preflight #23)

**When:** 2026-09-28 ~8:55 CT  
**Authoritative plan:** `plan.md` / `plan.json` on PR 470  
**Draft:** `claude-plan-draft.md` (headless Claude, Mac worktree, read-only tools Read/Grep/Glob)

First Glance **42078** re-confirmed live (Branch path **main→main**). Gate CLEAN @ `d68a331c`.

## Convergences (adopted / already in primary)

- Replace only the recoveries-leg status-log LEFT JOIN; keep `zod.public_hotlist FINAL` (org, vin) match byte-identical.
- Read `clarity.hotlist.first_repossessed_at` (`minIf` status 3). Predicate `toDate(..., 'UTC') >= d.claim_date - 7`.
- Adopt `min()` knowingly; residue forecast 0.187%; directionally downward.
- Carry `h.id != 0` and `d.agent_org_id != 0`; do not set `join_use_nulls = 1`. VIN un-normalised. Class off `ClickhouseQuery`. No `hotlist_status_aggregate`.
- Do not collapse both joins into one `clarity.hotlist` rewrite.
- Preflight #25 Cloud READ-ONLY before swap; 9137/9149 not live baseline; `X-ClickHouse-Summary` fields; FINAL measure decides.
- Preflight #18 real fleet Docker later; no DSN invent; `provisioned=false`.
- Preflight #24: no new helper / abstraction.
- AC-7 docblock rewrite; keep the cache-scope test's asserted docblock tokens (hotlist-match key and this ship key); wiki edits queued; UNPROVEN gaps marked.
- Out of scope list matches locked_shape.

## Divergences — judgment

| # | Draft proposal | Judgment |
| --- | --- | --- |
| 1 | `{floor}` **KEEP** — still shrinks clarity.hotlist hash build side; never was sort-key prune on the log (`ORDER BY id`); answer-neutral; M2c may overturn | **Adopt as primary.** Initial Aragorn draft had DROP from prep's "no log to pre-filter" framing. Claude is right that the role transfers. Default keep; m2c may drop. |
| 2 | Packets M4 (exact min-vs-max residue), M5 (MV backfill NULL coverage), M6 (same-id duplicate check), M2c (floor off) | **Adopt into #25.** M5 is a real stop condition prep missed. M6 reframes FINAL as possible correctness, not cost-only. |
| 3 | `IS NOT NULL` on `first_repossessed_at` in the subquery | **Soft adopt** — explicit; harmless. Implement may omit if redundant with `uniqExactIf` NULL semantics. |
| 4 | Alias `r` / `hotlist_id` rename vs `ch.id` | **Cosmetic.** Primary keeps `ch.id = h.id` for clarity. Either fine. |
| 5 | New feature test for two status-3 transitions (min below / max inside → `db_repos === 0`) | **Adopt** into t3 — pins the one genuine behaviour change so it is not "fixed" later. |
| 6 | Interleave old/new Cloud runs; state summary expectations before running | **Adopt** as measure discipline in Implement notes. |
| 7 | Keep `BillingClaimDedupTest` floor literal while `{floor}` stays | **Adopt** (follows KEEP). |
| 8 | Prefer removing `seedStatusLog` once unused | **Soft adopt** — Implement cleanup preference, not a gate. |

## What was rejected

- Treating the Claude draft as the authoritative plan (Implement takes `plan.json` / `plan.md` only).
- Scope inflation beyond the locked recoveries swap (neither plan proposed collapsing joins or touching aggregates).
- Inventing Mac Herd / fleet DSN or Cloud CH writes.
- Dropping `{floor}` without m2c evidence.
- Reopening Question Gate / the billing-leg change / Cohort queries.

## Done-signal for #23

Draft + this compare exist. Primary plan is the authority after convergence on `{floor}=keep` and the expanded #25 packets. Provision is next; do not mark provisioned here.
