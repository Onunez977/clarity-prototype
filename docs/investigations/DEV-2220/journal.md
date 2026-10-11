2026-07-23 09:37 CDT · intake · intake complete: route=bug app=clarity base=main; DEV-1898 precedent (query-rewrite already shipped), index is cross-repo (laravel-zod-migrations)

- 2026-07-23T14:55:38Z — prep: ESCALATED (best-of-N=3 + backstop). Triggers: cross-repo vendor index dependency, potential migration/blast-radius, material unanswerable questions, recurrence of DEV-1898, competing triage options. Gate=BLOCKED.
2026-07-23 09:55 CDT · prepped · prep complete (best-of-N reconciled): platform=resilience-only, durable index cross-repo; gate=BLOCKED on scope/approach/UX/DBA questions
2026-07-23 09:57 CDT · prepped · gate BLOCKED — parked awaiting human answers (scope/approach/negative-cache/UX/DBA); PR + Jira mirror updated

- 2026-07-23T15:02:46Z — gate interview (Dalton, normal mode) — answers folded, gate BLOCKED → CLEAN:
  - Q1 Scope → "We cannot change any database schema." Resilience-only; index/migration/cross-repo companion all OUT. Accept: affected orgs' dropdowns degrade to empty.
  - Q2 Approach → Catch-only + graceful degrade (catch SQLSTATE 57014 around remember(); NO bounded SET LOCAL statement_timeout).
  - Q3 Negative cache → Leave cache unset on failure (catch around remember; not persisted).
  - Q4 Degraded UX → Silently empty (empty Collection → empty dropdown; no visible indicator).
  - Q5 DBA/infra → Moot given the above (no index deploy, no bounded-timeout value needed).
2026-07-23 10:03 CDT · gated · gate CLEAN — Dalton: resilience-only, NO schema change, catch-only + graceful degrade, leave cache unset, silently-empty UX
2026-07-23 10:09 CDT · planned · plan: single node t1 (resilience in Org.php + TDD tests); no schema change, no fan-out; perf-gate N/A
2026-07-23 10:12 CDT · provisioned · provision Postgres (CH off): branch+testing DBs already warm (orgs/hotlist migrated); dev-login on; CH host neutralized to 127.0.0.1; ledger written
2026-07-23 10:27 CDT · implemented · implement node t1: Org getters catch 57014 → log + empty Collection (uncached); 17 tests red->green; Pint clean; no schema change

- 2026-07-23T15:49:16Z — APPROACH PIVOT (dev-requested, mid-review): Dalton reconsidered "catch-only is covering up". Investigated ClickHouse-source; chose **pure-CH**. Getters will read DISTINCT client/lienholder id+name from clarity.hotlist (CH) instead of the slow Postgres read-replica whereIn(subquery). Rationale: 42M-row CH table, columnar DISTINCT is fast, no PG schema change needed, denormalized names present.
  - Live CH verify (read-only, aggregates only via readonly_user): client_org_name populated 42,130,410/42,203,826 (~99.83%; 73,416 missing); lienholder_org_name 42,176,891/42,177,185 (~99.9993%; 294 missing). Query will filter null/empty names so no blank dropdown options.
  - Consequence: change now TOUCHES ClickHouse (re-provision CH for QA); resilience catch broadens from PG 57014 → LaravelClickhouseEloquent\Exceptions\ClickhouseException; keep cache()->flexible() wrapper.
  - Jeff Cobb composite-index Jira comment is now MOOT (pure-CH removes the PG index need) — NOT posted.
  - rework_pivot=true → re-plan, re-implement, re-review (RN 2), re-verify.
2026-07-23 11:53 CDT · reviewed · review: RN2 3-lane caught access-guard blast radius; fixed (guard->Postgres hasHotlistClient, drilldown->CH); RN3 100/100 gate pass; non-scoring follow-ups posted
2026-07-23 12:04 CDT · verified · verify: agent-QA PASS (real CH + seeded PG) — CH getters + dropdowns, Postgres guard allow/deny, warm jobs clean; 7/7 ACs SATISFIED
2026-07-23 12:06 CDT · verified · handoff parked — awaiting human QA; env up, PR draft; human-QA guide written

- 2026-07-23T19:22:07Z — human QA complete (dev signalled done). Perf proven at prod scale: the new CH getter query ran ~336ms for org 165 (6.0M hotlist rows, the timeout suspect), read-only vs prod ClickHouse. Residual noted: CH-vs-Postgres real-data equivalence (CDC completeness/lag) was surfaced; dev accepted. Finalizing: undraft PR, Build Record, transition, teardown.
2026-07-23 14:26 CDT · done · done: pure-CH hotlist getters + Postgres access guard; RN3 100/100; agent-QA PASS + prod-scale perf proof (336ms vs statement_timeout); Build Record posted; In Code Review; QA env torn down
