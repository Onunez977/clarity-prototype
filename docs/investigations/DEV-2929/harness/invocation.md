# DEV-2929 Planner harness — invocation

**Label:** Prep → Plan (Sprint 42) · resumed Thu 2026-10-08 after Mac mini offline 2026-10-07 6:20–6:40pm CDT
**When:** 2026-10-08 7:36:16am CDT → 7:52:28am CDT (wall **972 s ≈ 16 min 12 s**, exit 0)
**Aborted starts (not counted):** 7:29:33am (stale prompt copy — parallel copy raced the PM-rulings edit; killed at once, no output) · 7:30:06am (died ~7:30 when its parent shell session was killed; log 200 B, no spec written). Relaunched detached via `start_new_session=True`.

## Worktree
- Path: `<mac-run-dir>/platform-wt`
- Created via: `git -C /Users/daltonmccleery/Herd/platform fetch` → `worktree add --no-track -b cursor/dev-2929-export-window-filename <wt> origin/main` (`0e5ae042`) → `merge --ff-only origin/cursor/dev-2929-export-window-filename` → `fb48a40e`
- No upstream configured. Shared `Herd/platform` never used as cwd.

## Harness-local agent
1. Copied global agent → `<wt>/<agent-config-dir>/agents/<planner-agent>.json` (`.plan-cli` gitignored via `/.plan-cli`)
2. `toolsSettings.fs_write.allowedPaths` → `["<wt>/.ai/specs/**"]`; prompt default repo → wt
3. `fs_write` added to allowedTools; trust ⊆ allowedTools ∩ tools verified (fs_read, fs_write, grep, glob, code, thinking)
4. Guard copied → `<wt>/<agent-config-dir>/hooks/planner-guard.py`, `ALLOWED_ROOTS = realpath(<wt>/.ai/specs)`; all 3 preToolUse hooks repointed
5. Serena `--project` → wt
6. Guard smoke: outside write exit 2 · inside write exit 0 · Jira post exit 2 (ALLOW_JIRA_POST absent, not created)
7. Global `global agent config` agent + guard untouched (mtimes Oct 5)

## Exact command
```bash
cd <mac-run-dir>/platform-wt
<plan-cli> chat --no-interactive \
  --agent <planner-agent> \
  --model claude-opus-5.5 \
  --effort high \
  --trust-tools=fs_read,fs_write,grep,glob,code,thinking \
  "$(cat <mac-run-dir>/out/prompt.txt)"
```
(launched by `out/launch.py` via subprocess; prompt sha256 `bdced001…adb48` = box `harness/prompt.txt`). Never `--trust-all-tools`. Trust expanded: none. <plan-cli> 2.27.1.

## Outputs
- `.ai/specs/DEV-2929.md` (READY · `ch_touch: no`) — 23 KB
- `.ai/specs/DEV-2929.jira-comment.md` (draft; checked copy posted by Plan)
- Worktree `git status` clean after run (no product writes)

## Fences held
- No Implement · no product code · `.ai/` / `<agent-config-dir>/` not committed
- #374 / #509 / #510 untouched · Cloud CH not contacted
