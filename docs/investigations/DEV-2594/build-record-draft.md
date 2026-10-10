Insight AI — Build Record
<!-- build-record: DEV-2594 -->

**TL;DR**

The newest Live Hits Pending Repossession row on Scout could not be tapped because desktop controls (z-index 25) sat over the Live Hits rail (z-index 20) whenever the published controls height CSS variable was stale or wrong. The fix keeps that height in sync with the visible controls, stacks Live Hits above controls, collapses Live Hits when Hit Details opens from a list row or map marker, and raises the Hit Details sheet so it stays on top.

Verified with in-session review (gate score 100), agent QA stacking checks on desktop and compact, then human QA against three seeded Live Hits (QA2594A/B/C) on the worktree Herd site. Blast radius is Scout Vue overlays only; Day/Trip overlay z-parity was left out of scope and is still residual.

**Route:** bug · **Base:** main · **Mode:** normal · **no_spawn**
**PR:** https://github.com/insight-lpr/platform/pull/423
**Branch:** `claude/DEV-2594`
**Reviewed SHA:** `efa6ed875887be1382b79ac205fd71e58479b038`

## What changed (and why)
- **t1 — Make newest Live Hit open details on list row and map marker (desktop + compact).** Root cause was overlay stacking + a flaky `--scout-controls-h` publisher, not a broken click handler. `ScoutControlsWidget` now watches `rootEl` and republishes height via ResizeObserver; `DashboardOverlay` uses `z-[26]`, a larger gap under controls, stable `scan_id` keys, and collapses when `selectedHit` is set; `MapShell`/`BottomSheet` elevate the sheet host to `z-[45]` and re-animate on `contentKey`. Built Scout assets committed.

## Resolved questions
- Was the Premier demo on desktop or compact? → Cover both · human:Dalton 2026-09-18
- Was the dead click on the Live Hits list row or a map logo marker? → Cover both · human:Dalton 2026-09-18

## What this ticket did NOT do
- DayOverlay / TripOverlay stacking parity (still z-20 + 24px gap) — out of AC; no follow-up ticket filed yet.
- nothing else excluded from the ticket ACs.

## Acceptance criteria
- **SATISFIED** — Reproduced in a Scout user session — force-login kit tad03 on https://clarity-claude_dev_2594.test/scout
- **SATISFIED** — Root cause identified, not worked around — stale `--scout-controls-h` + z-25 over z-20; fixed publisher + stacking (not hit-target enlargement)
- **SATISFIED** — Every card in the hit stack opens its detail view on click, including the most recent one — human QA on seeded QA2594A/B/C (newest = QA2594A)
- **SATISFIED** — Verified on desktop and on the in-vehicle form factor — agent CDP stacking on 1920×1080 and 390×844; human QA covered click paths

## Review
- RN1 · gate score **100** (arbitration) · sources=in-session-agent
- Findings fixed: none scoring after RN1
- Residual (non-scoring): Day/Trip overlays still z-20+24px (out of AC); no automated UI click pin in CI

## Tests
- No JS unit runner for clarity-scout Vue (N/A)
- Agent QA: stacking CDP PASS; click ACs initially N/A then human-satisfied after CH seed
- Human QA: completed by Dalton 2026-09-18

## QA performed
- **Mode:** worktree · **Env:** real local ClickHouse Docker (`clarity-qa-clickhouse` → 127.0.0.1:18123)
- **Provisioning:** Herd site `clarity-claude_dev_2594.test`; Postgres `zod_claude_dev_2594`; CH DBs `clarity_claude_dev_2594` / `zod_claude_dev_2594`; frontend `npm run build`; kit 1 assigned to test@tester.com
- **Agent QA:** PASS — Live Hits z-26 over controls z-25; `--scout-controls-h` matched controls height; compact hides controls
- **Human QA:** guide `.context/qa-human-guide.html` — signed off (“I've completed QA”)
- **CH seed:** `.context/qa-ch-seed.sql` — open hotlists + Live hits QA2594A/B/C for kit_id=1, kit_org_id=2; verified via `ClickhouseHit::hot()->…->liveActionable()` returning 3 rows and Scout UI listing all three
- **What changed because of QA:** none (no qa-loop code edits); CH re-seed only so human could exercise clicks
- **Open findings:** Day/Trip z-parity residual only

## How numbers were derived
- none (no derivation_log aggregates for this frontend bug)
