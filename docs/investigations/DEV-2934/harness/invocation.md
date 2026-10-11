# DEV-2934 Planner harness: invocation

**Label:** Prep → Plan (Sprint 42)
**When:** 2026-10-08 12:30:08pm CDT → 12:54:17pm CDT. Wall **1450 s ≈ 24 min 10 s**, exit 0. One launch, no aborted starts.
**Model hiccup (not trust-related):** plan-cli-chat log shows `StreamTimeout 300s (IdleWatchdog)` at 12:51:25pm CDT. The agent recovered on its own and wrote the spec at 12:53pm.

## Worktree
- Path: `<mac-run-dir>/platform-wt`
- Created via: `git -C /Users/daltonmccleery/Herd/platform fetch` → `worktree add --no-track -b cursor/dev-2934-rdn-unauthorized-case-miss <wt> origin/main` (`43255f51`) → `merge --ff-only origin/cursor/dev-2934-rdn-unauthorized-case-miss` → `03a45aef`
- No upstream configured. The shared `Herd/platform` checkout was never used as cwd.

## Harness-local agent (LAUNCH-TEMPLATES 2026-10-08, restated #30)
1. Copied the global agent to `<wt>/<agent-config-dir>/agents/<planner-agent>.json` (`.plan-cli` is gitignored via `/.plan-cli`). Every `Herd/platform` path in the card was rewritten to the wt (prompt, Serena `--project`).
2. `toolsSettings.fs_write.allowedPaths` → `["<wt>/.ai/specs/**"]`.
3. **`fs_write` NOT in allowedTools** (asserted). `fs_write` is still in `tools`, so writes go through the path limit + guard, not trust.
4. Guard copied to `<wt>/<agent-config-dir>/hooks/planner-guard.py` with `ALLOWED_ROOTS = realpath(<wt>/.ai/specs)`. All 3 preToolUse hooks repointed.
5. `ALLOW_JIRA_POST` absent (not created). `@atlassian/addCommentToJiraIssue` is in `tools` only: untrusted and guard-blocked.
6. Guard smoke: outside write exit 2 · inside `.ai/specs/` exit 0 · product file exit 2 · Jira post exit 2.
7. Global `global agent config` agent + guard untouched.

## Exact command (launch.py derives trust from the card; no hand-typed list)
```bash
cd <mac-run-dir>/platform-wt
<plan-cli> chat --no-interactive \
  --agent <planner-agent> \
  --model <planner-model> \
  --effort high \
  --trust-tools=fs_read,grep,glob,code,thinking,@atlassian/getAccessibleAtlassianResources,@atlassian/getJiraIssue,@atlassian/searchJiraIssuesUsingJql,@atlassian/getJiraIssueRemoteIssueLinks,@serena/find_symbol,@serena/find_referencing_symbols,@serena/get_symbols_overview,@serena/search_for_pattern,@serena/find_file,@serena/list_dir,@<agent-mcp>-core/memory_recall,@<agent-mcp>-work/work_brief,@<agent-mcp>-work/work_report \
  "$(cat <mac-run-dir>/out/prompt.txt)"
```
Launched by `out/launch.py full` (detached, `start_new_session=True`). Prompt sha256 `bc9a5509…4b35ac` = box `harness/prompt.txt`. <plan-cli> 2.27.1. Never `--trust-all-tools`.

## #30 trust result (first launch under the 2026-10-08 trust fix)
- `--trust-tools` == card `allowedTools`: **yes**, the same 18 entries in both directions.
- **MCP names in `--trust-tools`: accepted.** <plan-cli> 2.27.1 took `@server/tool` names, ran `@atlassian/getJiraIssue` and `@<agent-mcp>-work/work_brief`, and exited 0. The built-in-only fallback (`launch.py builtins`) was not needed.
- **Spec written without `fs_write` trust: yes.** `.ai/specs/DEV-2934.md` and `.ai/specs/DEV-2934.jira-comment.md` were written through the untrusted, path-limited `fs_write` and auto-approved in-limit under `--no-interactive`. The raw log has no denial or permission error.
- Side note: `@<agent-mcp>-work/work_brief` and `@<agent-mcp>-core/memory_recall` returned "no session identity" (no ledger identity for a headless run), so the Planner relied on the prompt + a read-only Jira re-fetch. Harmless.

## Outputs
- `.ai/specs/DEV-2934.md` (READY · `ch_touch: no`), 22 KB
- `.ai/specs/DEV-2934.jira-comment.md` (draft; Plan posts the checked copy)
- Worktree `git status` clean after the run (no product writes)

## Fences held
No Implement · no product code · `.ai/` / `<agent-config-dir>/` not committed · Cloud CH not contacted · no Board / no messages.

## Implement — 2026-10-08 12:59–1:03pm CDT
- Worktree-local worker card rebuilt from the trust-scope template: the write tool is declared but neither trusted nor in `allowedTools`, so `allowedPaths = ["<wt>/**"]` and `deniedPaths` (`.git`, harness config dir, `.ai`, `.context`, `.env*`, `vendor`, `node_modules`) apply. No shell, no MCP servers, no web tools.
- Write guard (preToolUse): only `app/Services/RDN.php`, `app/Jobs/Rdn/GetOrgHotlistCaseInfo.php`, `app/Console/Commands/RdnBackfillBranches.php` and their three test files. Guard smoke: in-scope 0, `config/services.php` 2, harness dir 2.
- Pre-launch check PASS: write tool and shell not in `allowedTools`; trust list derived from `allowedTools` = `fs_read,grep,glob,code,thinking`.
- Command: `<cli> chat --no-interactive --agent <worker-agent> --model <implement-model> --effort xhigh --trust-tools=fs_read,grep,glob,code,thinking "<prompt>"`. Exit 0, ~3m13s. CLI 2.27.1. No write was refused; no harness-dir probe this run (deniedPaths was proven on the DEV-2928 act-on run).
- Agent changed only the six in-scope files. Harness ran tests (PHP 8.4 and 8.3), Pint and static analysis, committed and pushed.
