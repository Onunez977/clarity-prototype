# Changelog & email

The documentation half of `release`, absorbed from `release-prep`'s inventory logic and the
`/document-changes` command so this skill is self-contained. Covers Phase 1 (inventory), Phase 2
(version + changelog write), and Phase 10 (PM email). Shared vars: `$TARGET`, `$REL_VERSION`
(`vX.Y.Z`), `$BARE_VERSION` (`X.Y.Z`).

Jira base URL (for links everywhere): **`https://insightlpr-team.atlassian.net/browse/DEV-XXXX`**.

---

## Inventory (Phase 1)

### Range

```bash
TAG=$(git -C "$TARGET" describe --tags --abbrev=0)
[ "$TAG" = "$REL_VERSION" ] && TAG=$(git -C "$TARGET" describe --tags --abbrev=0 "${REL_VERSION}^" 2>/dev/null)
COMMITS=$(git -C "$TARGET" rev-list --count "$TAG"..HEAD)
echo "range=$TAG..HEAD commits=$COMMITS"
```

The base is **always the last released tag**, never `merge-base` (on `main` a merge-base collapses to
HEAD → empty diff). If `git describe` finds no tag, ask for the baseline and stop. If `$COMMITS` is 0,
there's nothing to release since `$TAG` — stop.

### The release-noise filter (apply to every file list and commit walk)

```bash
rel_filter() { grep -vE '(^|/)\.' | grep -vxE 'CLAUDE\.md|AGENTS\.md|boost\.json'; }
```

Drops hidden/dotfiles (`.claude/**`, `.github/**`, `.junie/**`, `.context/**`, …), the Boost-regenerated
docs (`CLAUDE.md`/`AGENTS.md`/`boost.json`), and release plumbing (`GHA deploy` commits, prior `RC v*`
release commits, and version/changelog-only commits). A commit whose entire filtered diff is empty drops
out of the inventory.

### Per-ticket grouping

Walk the range NUL-delimited (multi-line bodies stay intact) and group by the first `DEV-####` key:

```bash
git -C "$TARGET" log --no-merges --format='%H%x1f%s%x1f%b%x1e' "$TAG"..HEAD
```

- **Ticketed:** `key → { commits[], files[] }`, where `files` = the `rel_filter`ed union of each
  commit's `git diff --name-only <sha>^ <sha>`. Dedupe. First `DEV-####` per commit via
  `grep -oE 'DEV-[0-9]+' | head -1`.
- **Unticketed:** keyless commits collapse into one `Unticketed` bucket. Drop release plumbing: `GHA
  deploy` commits, prior `RC v*` release commits (subject matches `^RC v`), and version/changelog-only
  commits whose filtered files ⊆ {`composer.json`, `config/changelog.php`} — a warm re-run's own
  `RC vX.Y.Z` commit is exactly this shape and must not resurface as a "feature."
- Drop tickets left with zero product files after filtering (note them in the "ignored" count).

Carry `files[]` per ticket forward — it drives the changelog coverage map and the monitor's
"likely area" attribution.

### Jira enrichment (Atlassian MCP — deferred tools)

```
ToolSearch: select:mcp__0d6dde38-d850-410d-8efa-bbdae27e848c__getJiraIssue
ToolSearch: +jira searchJiraIssuesUsingJql
```

Preferred: one batch `searchJiraIssuesUsingJql` with `jql = "key in (DEV-1898, DEV-1933, …)"` requesting
`summary` + acceptance-criteria/description; fallback `getJiraIssue` per key. From each issue pull the
**title** (the human-readable "what") and enough of the description/AC to write a one-line **"why"**. On
any MCP failure for a key: note "couldn't enrich <key>", fall back to the cleaned commit subject as the
title, and derive the "what" from `files[]`. Don't block.

**Output** (handed to Phase 2 + Phase 10): `[{ key, title, why, files[], enriched:bool }]` + the
`Unticketed` bucket.

---

## Changelog (Phase 2)

Two tracked files change: `composer.json` `"version"` and a new `config/changelog.php` entry. Read the
existing `config/changelog.php` first — the last ~15 entries are the template.

### Write the version + entry

- `composer.json` `"version"` → `$BARE_VERSION` (e.g. `3.10.0`). No `composer install` (the field
  doesn't affect the lock).
- Prepend to the top of the `'releases'` array, mirroring the existing shape exactly:
  ```php
  '$REL_VERSION' => [
      'date' => '<today — "Month Nth, Year", e.g. August 13th, 2026>',
      'changes' => [
          // filled below
      ],
  ],
  ```
- Idempotent: on a warm re-run where `$REL_VERSION` already exists, re-fill its `changes` in place — do
  not add a second entry.

### Fill `changes` — customer-voice, from the inventory

One short line per user-facing change, indistinguishable in voice/length from existing entries:

- **One short sentence** (most are < ~15 words). Verb-first (`Added`/`Fixed`/`Improved`/`Updated`/
  `Removed`/`Optimized`/…), ending with a period. Collapse several small related commits into one line.
- **Name the feature + page in Title Case** ("Kit User Path", "the Hits page", "Missed Opportunities",
  "Client Analytics", "Dashboard"). Say the screen where it lives.
- **`Pro Feature:` prefix** for Pro items.
- **Customer-benefit framing** — what changed for the user, never how it was built. No code/class/table/
  package names. HTML only where an existing entry uses it. Vague is fine for backend/perf ("Improved
  data accuracy and loading speed.").

**Exclude:** `GHA deploy` + the changelog/version bump itself; typos/renames/formatting/refactors/
dep-bumps/test-only; `.claude/**` + hidden/dotfiles + Boost docs; anything already documented.

### Gated-feature exclusion — do not announce what isn't usable yet

The changelog promises a feature is **available now**. A feature can be merged + deployed but still
locked behind a gate — those must NOT be documented until the gate opens. Before writing a line, check
the diff for:

- **Pennant flags** — `Feature::active(...)` / `Feature::for(...)` not broadly enabled.
- **Config / env gates** off by default (the recurring **Clarity Scout** example — shipped but locked
  behind a config toggle).
- **Pro/tier gates that aren't live**, or explicit "hidden"/"disabled"/"beta"/admin-only guards.

When you can't confirm a feature is enabled and reachable, **leave it out**. (Gated features are still
QA'd — this is changelog-only.)

### Coverage cross-check

After filling, assert every inventory ticket is accounted for: each `DEV-####` either appears in the new
entry's `changes` (or a recent prior entry) OR is deliberately excluded — gated, internal/non-user-
facing, or noise. List any **uncovered** ticket with a one-line "why excluded" (or "MISSING — should
this be documented?"). This flags gaps; it never edits the changelog on its own — the user decides
whether to add a line. Surface it in the SKILL.md summary.

Then commit + push per SKILL.md Phase 2 (message `RC $REL_VERSION`, **no `[skip ci]`**).

---

## Email (Phase 10) — draft for Yolanda

**Draft only.** No real send path exists here (the mailer is a Mailtrap sandbox; no Gmail/Outlook/Slack
connector is authorized). Assemble the draft from data already on hand — the version + date from
`config/changelog.php`, the per-ticket what/why + links from the Phase-1 inventory, and the one-line
monitor outcome from [`monitor-loop.md`](monitor-loop.md) — and present it in-thread to copy-paste.

**Recipient:** Yolanda is InsightLPR **user id 35** (the QA/PM owner). Her email isn't stored in the
repo — resolve it from the user, or leave a `To:` placeholder for them to fill. Don't guess an address.

### Template

```
To: Yolanda <…>
Subject: Clarity Release vX.Y.Z — <today's date>

Hi Yolanda,

Clarity vX.Y.Z is live in production as of <date>. Here's what shipped:

• <DEV-XXXX title> — <one-line why it changed>
  https://insightlpr-team.atlassian.net/browse/DEV-XXXX
• <DEV-YYYY title> — <why>
  https://insightlpr-team.atlassian.net/browse/DEV-YYYY
  …

Release: vX.Y.Z   ·   Released: <Month Nth, Year>
Post-release monitoring: <clean — no new errors in the 2h window  |  N issue(s) flagged: …>
Still to come: <what shipped but isn't live yet — a nightly job's next run, a hand-applied migration — or omit>

Thanks,
<sender>
```

- One bullet per **enriched** ticket (title = "what", the "why" from Jira). Unticketed but user-facing
  changes can fold into a short "Also included" line; skip pure noise.
- Keep it customer-/PM-readable — same voice as the changelog, no internal jargon.
- End with the **monitor outcome** so Yolanda knows the release's health, not just its contents.
- **Fold in the Release Report's `Jira:` and `Outstanding:` lines.** Say how many tickets were closed under
  `Clarity vX.Y.Z`, and name anything that shipped but isn't live yet (a queued job awaiting its next run, a
  migration still to be applied by hand). Yolanda reading "Done" as "usable" is exactly the confusion the
  Phase-8b follow-up comments exist to prevent — the email must not undo it.
- **If the watch filed follow-up tickets**, name them in one short line ("one issue found and ticketed:
  DEV-XXXX") rather than letting them surface later.
- If the user later authorizes a Gmail/Outlook/Slack connector, offer to send it then; until then it's
  a copy-paste draft.
