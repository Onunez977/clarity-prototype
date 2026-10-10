2026-07-13 09:32 CDT · intake · intake complete: route=bug app=clarity base=main
2026-07-13 09:42 CDT · prepped · prep complete: gate=CLEAN, single choke-point orgScoutKits(); perf Q reclassified to verify-check (AC already commits to all active kits); backstop=no genuine missed callers
2026-07-13 09:42 CDT · gated · gate CLEAN — no material open questions; proceeding to plan
2026-07-13 09:44 CDT · planned · plan: 1 node (simple, no blast-radius) — rewrite orgScoutKits() + docblocks + pinning test; TDD
2026-07-13 09:53 CDT · provisioned · provision (Postgres-only, CH deferred): both DBs migrated (public.kits/kit_metadata present), dev DB reseeded, herd site 200; ledger written
2026-07-13 10:04 CDT · implemented · implement: orgScoutKits() lists all active org kits (inline kit_metadata.deleted_at exclusion), drop kit_settings-row filter; 15/15 controller tests (TDD red->green); blast-radius clean
2026-07-13 10:14 CDT · reviewed · review: 3 adversarial lanes 100/100 (gate pass); added stale-settings (user_id NULL) test; 16/16 green; non-scoring notes posted to PR
2026-07-13 10:19 CDT · verified · verify: live /drivers (org 2 admin) returns 49 active kits (pre-fix 1), soft-delete excluded live, non-enrolled render null labels; all 3 ACs SATISFIED; 16/16 tests
2026-07-13 10:21 CDT · verified · handoff: human-QA guide rendered; PARKED awaiting human QA (PR stays draft; env stays up)
2026-07-13 11:06 CDT · verified · cache follow-up: short-TTL per-org cache on orgScoutKits() (3s impersonating / 10s), time-cache not event-bust; RN2 lane 90->fixed (impersonation TTL); 17/17; live re-check ok; still parked awaiting human QA
2026-07-13 12:30 CDT · done · handoff complete: PR #267 ready + In Code Review, Build Record posted (35572), QA env torn down; run done
2026-07-13 12:53 CDT · done · post-QA: align ScoutUsersResource with org map via shared Kit::scopeExcludingDeleted (used in both orgScoutKits + resource); fix pre-existing stale driver test (95da4d7f removed the 20-cap); 92 affected tests green
