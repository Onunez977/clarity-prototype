# Release inventory

How release-prep Steps 1–3 turn `vX.Y.Z..HEAD` into a Jira-enriched feature/ticket inventory. The
inventory feeds three things: the scope classification, the lane pre-grouping
([`plan-authoring.md`](plan-authoring.md)), and the seeder scenario matrix.

## 1. Range

```bash
TAG=${OVERRIDE_TAG:-$(git -C "$TARGET" describe --tags --abbrev=0)}
BASE_SHA=$(git -C "$TARGET" rev-list -n1 "$TAG")
```

The base is **always the tag** — never `merge-base`. On `main`, a merge-base against `main` is HEAD
itself (empty diff); the tag anchor is well-defined wherever the skill runs.

## 1.5. The release-noise ignore filter (apply everywhere)

A release range carries non-product churn that must be excluded **completely** from the inventory,
scope, seeder, plans, and summary — it generates no feature, no QA lane, and no changelog line.
Ignore three categories:

- **Hidden / dotfiles** — any path with a dot-prefixed segment: `.claude/**` (Claude agents, skills,
  hooks, commands, worktrees, settings), `.github/**`, `.junie/**`, `.cursor/**`, `.vscode/**`,
  `.context/**`, `.workday/**`, and root dotfiles.
- **Laravel Boost regenerated docs** — `CLAUDE.md`, `AGENTS.md`, `boost.json` (not hidden, but agent
  artifacts the bootstrap/boost step rewrites). The `.github/copilot-instructions.md` and
  `.junie/guidelines.md` siblings are already covered by the hidden filter.
- **Release plumbing** — `GHA deploy` commits and `config/changelog.php`-only commits (the changelog
  bump this skill itself makes).

Apply this filter to **every** file list and commit walk below:

```bash
# Drop hidden/dot paths + the boost-regenerated docs.
rel_filter() { grep -vE '(^|/)\.' | grep -vxE 'CLAUDE\.md|AGENTS\.md|boost\.json'; }
```

So the Step 1 file count and the per-ticket `files[]` are always piped through `rel_filter`, e.g.
`git -C "$TARGET" diff --name-only "$TAG"..HEAD | rel_filter`. A commit whose entire diff is filtered
away (boost-doc regen, an agent-config tweak, a `GHA deploy`) drops out of the inventory entirely.

## 2. Per-ticket grouping

Walk every commit in the range, NUL-delimited so multi-line bodies stay intact:

```bash
git -C "$TARGET" log --no-merges --format='%H%x1f%s%x1f%b%x1e' "$TAG"..HEAD
```

For each commit, extract the first `DEV-####` key from subject+body
(`grep -oE 'DEV-[0-9]+' | head -1`). Group (every `files[]` piped through `rel_filter` from §1.5):

- **Ticketed:** key → { commits[], files[] }, where `files` = union of
  `git -C "$TARGET" diff --name-only <commit>^ <commit> | rel_filter` across the ticket's commits.
  Dedupe.
- **Unticketed:** commits with no key collapse into a single `Unticketed` bucket (its files are the
  filtered union of those commits' diffs). Drop `GHA deploy` commits and commits that touch only
  `config/changelog.php` — they're release plumbing.
- **Drop empty commits/tickets:** a commit whose filtered `files[]` is empty contributes nothing; a
  ticket left with zero product files after filtering is omitted from the inventory (note it in the
  summary's "ignored" count rather than giving it a lane).

Carry `files[]` per ticket forward; it determines which surfaces each lane tests and what the
seeder must populate.

## 3. Jira enrichment (Atlassian MCP)

The Jira tools are deferred — load schemas first:

```
ToolSearch: select:mcp__0d6dde38-d850-410d-8efa-bbdae27e848c__getJiraIssue
ToolSearch: +jira searchJiraIssuesUsingJql
```

Two viable shapes:

- **Batch (preferred):** one `searchJiraIssuesUsingJql` with
  `jql = "key in (DEV-1898, DEV-1933, …)"`, requesting `summary` + the acceptance-criteria field.
- **Per key:** `getJiraIssue` for each.

From each issue pull:

- **Title** → the lane's feature name.
- **Acceptance criteria / Definition of Done** (verbatim where possible) — number them
  `DEV-XXXX/AC-1`, `DEV-XXXX/AC-2`, …. If there's no explicit AC field, derive must-haves from the
  description's checklist / "should" statements and label them the same way, noting they're
  inferred.

### Fallback when the MCP is unavailable

Don't block. For each unresolved key: `add_caveat "couldn't enrich <key> (<reason>)"`, set the
lane's title to the cleaned commit subject, and derive items purely from `files[]` ("(no Jira ACs —
diff-anchored)"). The plan stays valid and the agent-qa gate still passes — only AC coverage is
degraded. If *all* enrichment fails, the whole release plan is diff-anchored, which is still a
useful smoke pass.

## 4. Scope classification (for the plan frontmatter)

Classify the **union** of all tickets' `files[]` exactly as qa-prep Step 6b does (UI vs backend vs
none). A release almost always lands in **both**. Record the single `scope:` value in both plan
frontmatters.

## Output handed to plan-authoring

A list of tickets, each: `{ key, title, files[], acs[] (numbered or "diff-anchored"), enriched: bool }`,
plus the `Unticketed` bucket and the overall `scope`.
