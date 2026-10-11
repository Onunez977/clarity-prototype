# Handoff — DEV-2600 Verify / Build Record done → Undraft / CI / HITL / QA Ready

## Verdict
**PASS · phase=done.** Verify/Build Record complete after Agent QA PASS on Option B five-band Orders density legend filter. Both gates PASS. PR #476 stays **DRAFT**. Holding base **`scout`**.

## Build Record
- **BR comment id:** `42210`
- Marker: `Insight AI — Build Record`
- Scope: WHAT=five-band getCombinedTier* + visibleOrderTiers paint-time filter + watch→updateOverlayLayers only; WHY=Kit parity / Premier demo / Q1 Option B; HOW=Agent QA 4/23 on real Docker CH `:18131` + Review 94/93/95 + live matrix PASS; Live Hit Density OOS DEV-2601

## Tips
| Role | SHA |
|------|-----|
| **Product tip / reviewed_sha** | `4d618fa8153f7b9b5c0c190f6b8407a5330d0f95` |
| **Branch tip (PR head at Verify)** | `f10883019f71ff4fb701e73fd7407ad8c5a39818` |
| Living ticket-status | rewritten via GitHub API on PR #476 (no undraft) |

Worktree `/workspace/dev-2600-wt`. `PRODUCT_TIP_ANCESTOR_OK`. Base `scout` @ `f52b5d85…`.

## Review
- rn=1 · scores **94 / 93 / 95** · min 93 ≥ 85 · real Sam lanes (NOT mechanical [85])
- `rerun_required=false` · `act_on=NONE` · `reviewed_sha=4d618fa8…`
- Thin conventions #16: conventions **93** — refuse redesign

## #18 / #25 / #16 / #27 / #26
- **#18 PASS (required):** Agent QA real fleet Docker CH `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18131` / from_app `172.21.0.1:18131` · **4 passed / 23 asserts** · seed_used=true · `cloud_touched=false` · **pgsql does not clear** · cite `agent-qa-pins.log`
- **#25 N/A (`does_not_fire`):** paint-time legend filter only — no old-vs-new path rewrite / no Cloud parity
- **#16 PASS:** conventions lane 93 ≥ 85
- **Soft #27 PASS (URL present):** https://github.com/insight-lpr/platform/pull/476#issuecomment-5876854396 (legend hide/show/all-off/all-on). Do not wait for another post.
- **#26 holding `scout`:** final scout→main is a separate merge — do not undraft as if main→main

## AC (record.ac length 17)
Jira description ACs + Option B live matrix + preflights — all in-scope PASS; Live Hit Density **OOS**.

## Gates
```
BUILD_RECORD_GATE: pass id=42210 (route=feature, ac=17, review_rounds=3, rerun_required=false)
REWORK_GATE: pass (only investigation artifacts changed since review — no code rework)
```

## PR
- https://github.com/insight-lpr/platform/pull/476 — **DRAFT** (Verify did **not** undraft)
- Living `<!-- ticket-status -->` rewritten to Phase Verify/Build Record **done** · Next Undraft/CI → HITL before QA Ready
- Gate CLEAN

## Fleet (LEFT UP — do not tear down from Verify)
- Ledger: `docs/investigations/DEV-2600/qa-prep-ledger.json`
- Site `:18088` · PG `:15440` · Docker CH `:18131`
- `cloud_touched=false` · `teardown_done=false`
- Soft peer ports untouched
- **Teardown ownership:** next lane at QA Ready exit / closeout

## Soft flags (non-blocking)
- Live Hit Density **OOS** (DEV-2601)
- Deploy recolor 10–24 → `#7B1FA2` documented in PR body
- okf wiki queued
- Docs tip may be `[skip ci]` — CI trigger is next lane
- Soft #27 evidence comment already live

## State
- `phase=done` · `comment_ids.build_record=42210` · gate CLEAN · `record.ac` length 17 · review scores `[94,93,95]` · `rerun_required=false`
- Flags: #18 PASS · #25 does_not_fire · #26 holding_scout · #27 SOFT_PASS · HITL_before_QA_Ready=true
- Mirrors: worktree `docs/investigations/DEV-2600/` · `/workspace/DEV-2600/` · `/workspace/docs/investigations/DEV-2600/`

## Cite (disk; do not git-add evidence folders)
- `/workspace/docs/investigations/DEV-2600/qa-agent-report.md`
- `/workspace/docs/investigations/DEV-2600/qa-ac-results.json`
- `/workspace/docs/investigations/DEV-2600/agent-qa-pins.log`
- `/workspace/docs/investigations/DEV-2600/live-qa-evidence/` (do not commit)
- `/workspace/docs/investigations/DEV-2600/build-record.md`
- `/workspace/DEV-2600/handoff/galadriel.md`

## Next (PM owns — Verify does not undraft / QA Ready / merge / Status Board)
1. **Undraft** PR #476 (against holding `scout`).
2. **CI** green (docs tips may be `[skip ci]` — ensure checks cover reality).
3. **HITL with Dalton before QA Ready** — this is a **UI** change; PM holds after Undraft/CI and gets Dalton eyes on the five-band legend filter before flipping QA Ready. Soft #27 evidence comment **is present**.
4. **QA Ready** flip only after HITL.
5. **Merge-lane** after QA Ready (into `scout`). Final **scout→main** is a separate Jeff merge — not this PR's undraft story.
6. Fleet env **UP** (`:18088` / `:15440` / `:18131`). Teardown on closeout / QA Ready exit is PM.

## SendToAgent
Executor has **no** SendToAgent tool — **Merry must baton Galadriel** for Undraft/CI then HITL-before-QA-Ready. Never Status Board / Fleet Board from this handoff.
