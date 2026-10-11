# DEV-2928 First Glance — live re-GET cite

- Comment id: **43357** · author Dalton McCleery · created 2026-10-07 3:57pm CDT (16:57:17 -04:00)
- Re-GET at Prep intake: 2026-10-07 ~4:00pm CDT via Atlassian connector (cloud insightlpr-team)

| Field | Value (verbatim summary) |
| --- | --- |
| Verdict | Ready · Hardness Medium · Sprint 42 · App Clarity · Repo insight-lpr/platform |
| **Branch path** | Stacked on PR #374's head branch `claude/DEV-2442` (base = `claude/DEV-2442`). Once #374 merges to main, retarget this PR's base to main; then main→main and Jeff merges it. Do not push to or edit #374. |
| Surface | (1) `app:rdn-backfill-branches {org}` (`--dry-run=N` across <30d / 30–180d / >180d, activity-window `--since`/`--until`, throttled chunks on dedicated queue + Horizon supervisor entry, resumable per-org cursor, reuse `GetOrgHotlistCaseInfo` unchanged). (2) Normalized branch-name fallback after exact. (3) Query-time branch filter for LPR Assists + Missed Opportunities: `hotlist_id IN (SELECT id FROM clarity.hotlist FINAL WHERE org_id=? AND ifNull(branch_id,0)=?)`, reusing `GetOrgHotlistCaseInfo` + `HotlistStatusSource` pattern from #374. (4) Runbook in PR. CH read-path only (`ch_touch: yes`); no CH writes / MV changes; Agent QA / Verify on real Docker CH. |
| Hard stop | No production backfill or Cloud CH writes; smallest change, no new infra beyond queue/supervisor entry. OOS: alias table; prod backfill run; fleet-wide run; `HotlistStatusAggregate` / All Branches; RDN ingestion outage since 2026-09-02. |
| Forecast gates | (1) Old-vs-new parity: All Branches byte-identical; branch-filtered LPR Assists / MO match transition cards' current-branch semantics; read-only prod-cardinality measure in PR + Build Record. (2) Human QA with Dalton before undraft. (3) Depends on #374 merge for retarget. |
| Collisions | DEV-2442 / PR #374 (QA Ready, tip `42a4310f`, Jeff owns merge) — same files. DEV-2450 / PR #509 separate (draft, held). |
| Next | Prep → Plan → Provision. |
