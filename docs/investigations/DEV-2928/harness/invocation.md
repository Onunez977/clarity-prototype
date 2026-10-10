# DEV-2928 Plan harness — invocation

**Label:** Prep → Plan (Sprint 42)
**When:** 2026-10-07 4:03:09pm CDT → 4:36:56pm CDT (wall **2027 s ≈ 33 min 47 s**)

## Worktree
- Path: `<mac-run-dir>/platform-wt`
- Created via: `git -C /Users/daltonmccleery/Herd/platform worktree add -b cursor/dev-2928-rdn-backfill-branches … origin/claude/DEV-2442` (after fetch)
- Base SHA: `42a4310fc9b76768f51caa3dbc42099b02331704` (`origin/claude/DEV-2442`)
- Auto-upstream to `claude/DEV-2442` **unset** immediately (`git branch --unset-upstream`) — no bare `git push` can reach #374
- Prep tip: `4ac00200cc2a30f66833259919d7e62531fbf30b` (ff'd into wt before launch)
- Shared `/Users/daltonmccleery/Herd/platform` never used as the Plan run cwd

## Harness-local agent
1. Copied global agent → the worktree-local agent config (planner agent, gitignored)
2. `toolsSettings.fs_write.allowedPaths` → `["<wt>/.ai/specs/**"]`
3. trust ⊆ allowedTools verified (fs_read, fs_write, grep, glob, code, thinking ∈ tools ∩ allowedTools)
4. Guard copied → the worktree-local hooks dir (`planner-guard.py`, gitignored), `ALLOWED_ROOTS = realpath(<wt>/.ai/specs)`; preToolUse hooks (fs_write, code, addCommentToJiraIssue) repointed
5. Code-index `--project` → worktree
6. Guard smoke: outside write exit 2 · inside write exit 0 · Jira post exit 2 (ALLOW_JIRA_POST absent, not created)
7. Global agent + guard untouched (mtimes Oct 5)

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
Never `--trust-all-tools`. Trust expanded: none. Exit 0. CLI 2.27.1.

## Outputs
- `.ai/specs/DEV-2928.md` (READY · `ch_touch: yes`) — 52 KB
- `.ai/specs/DEV-2928.jira-comment.md` (draft; sanitized checked copy posted by Plan)
- Worktree `git status` clean after run (no product writes)

## Fences held
- No Provision / qa-prep · no Implement baton
- No product code · `.ai/` / harness config dirs not committed
- #374 / `claude/DEV-2442` untouched (remote still `42a4310f`)
- Cloud CH not contacted

## Act-on Review rn=4 — 2026-10-08 9:07–9:08am CDT (Implement agent)
- Worktree-local agent card rebuilt from the trust-scope template: writes are a declared tool but neither trusted nor in `allowedTools`, so the write path limits apply. `allowedPaths = ["<wt>/**"]`. `deniedPaths` = `<wt>/{.git,<harness-config-dir>,.ai,.context,vendor,node_modules}/**` and `<wt>/.env*`. No shell, no MCP servers, no web tools.
- Write guard (preToolUse): the command, its test, plus one probe path `<wt>/<harness-config-dir>/probe-denied.txt`, so the probe could only be stopped by the card's `deniedPaths`.
- Pre-launch check: PASS. The write tool and the shell are not in `allowedTools`, and the trust list derived from `allowedTools` is `fs_read,grep,glob,code,thinking`.
- Command: `<cli> chat --no-interactive --agent <worker-agent> --model claude-sonnet-5.5 --effort xhigh --trust-tools=fs_read,grep,glob,code,thinking "<prompt>"`. Exit 0, ~49s. CLI 2.27.1.
- Fence probe result: the single write to `<wt>/<harness-config-dir>/probe-denied.txt` was **refused by `deniedPaths`**. The response was "Tool use was rejected because the arguments supplied are forbidden: <wt>/<harness-config-dir>/**", the tool status was Failed, and no file was created. The non-interactive log has no literal `[denied]` tag; the refusal appears as the rejection text above.
- The agent changed only the command and its test. The harness wrote the docs, ran the tests and Pint, committed and pushed from the box.
