# DEV-2928 harness self-check (Plan)

**Verdict:** CLEAN → post checked Jira comment (sanitized copy `DEV-2928.jira-comment.checked.md`)

| Check | Result |
| --- | --- |
| Status READY (not PARKED) | PASS — L2 |
| Branch path = stacked on `claude/DEV-2442` (FG 43357) | PASS — L2, L4; §0 never touch #374 |
| `ch_touch: yes` in test map | PASS — §5 L123 (read path only; real Docker CH; pgsql ≠ done-signal) |
| Scope matches live AC 1–5 | PASS — §2 AC-1..AC-5 verbatim + AC→test map §5 covers selection window, chunking/resume, dry-run no-write, normalized match, branch filter both cards |
| #24 no gold-plating | PASS at Plan — new artifacts limited to the command; job dispatch/rate-limit/unique/ceiling/counters unchanged, only inline lookup → static `findBranch()`; card filter reuses `HotlistStatusTransition::scopeForBranch` subquery; zero consumer edits. (Planned queue + supervisor entry dropped at HITL 2026-10-08: no Horizon config. Inline rewrite 2026-10-08: the backfill calls `handle()` in-process under the job's own `rdn-case-info` limiter bucket; no queue push.) |
| No CH writes / no MV or schema changes | PASS — §0 L8, §4 untouched list, §7; §10 SQL is SELECT-only (no INSERT/ALTER/DROP/TRUNCATE/OPTIMIZE) |
| #25 parity measure planned (Cloud RO) | PASS — §10 P0 identity, P1 All Branches byte-identical (code + Cloud counts), P2 old-vs-new per branch + transition-card comparator, P3 cost from `system.query_log`; filed to PR + Build Record |
| HITL before undraft noted as open gate | PASS — §2 G2, §13 |
| Seed needs for Path A | PASS — §11 (mirrored to `path-a-seed-needs.md`) |
| Path A / ports not invented | PASS — §0, §11 |
| No bot names (spec + comment) | PASS — grep clean; comment sanitized (`@conductor` → "Decision (Dalton)", "crew" removed) |
| Plan run posted nothing | PASS — guard blocked post path; ALLOW_JIRA_POST absent; raw log shows no addComment call |

**Harness notes (non-blocking, carry to Implement/Review):**
- `okf/` wiki updates (§12) are post-approval upkeep, not AC — keep them out of the Implement diff unless Dalton asks after HITL.
- Q3 (unbounded org-wide `clarity.hotlist FINAL` subquery cost) is the real risk; P3 measure decides. PG-024 (org_id second in ORDER BY) applies.
- Card filter keys LPR on `la.recovery_id` (= hotlist id, evidence §3) rather than the ticket's literal `hotlist_id` — semantic match; P0 confirms on Cloud RO.

**Open questions:** 4 (none block Implement; Q4 blocks undraft only if the identity check fails)
**Spec Status:** READY
**ch_touch:** yes
