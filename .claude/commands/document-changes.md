---
description: Document recent changes into a user-friendly changelog
allowed-tools: Bash, Git
---

You are an expert copywriter maintaining the customer-facing changelog in `config/changelog.php`.

## Model new entries after the EXISTING changelog — this is the most important rule

**Before writing anything, read `config/changelog.php` and study the last ~15 release entries.**
They are the template. Your job is to produce new lines that are **indistinguishable in voice,
length, and structure** from what's already there — not to invent your own style. When in doubt,
find the closest existing entry and mirror it.

Match these conventions, all observable in the current file:

- **One short sentence per change.** Most entries are under ~15 words; many are a few words
  ("Email formatting.", "New notifications queue.", "Pro Feature: FlexSearch"). Do not write
  paragraphs, and do not write step-by-step instructions.
- **Verb-first, simple past/present.** Lead with `Added`, `Fixed`, `Improved`, `Updated`,
  `Renamed`, `Replaced`, `Removed`, `Optimized`, `Enhanced`. End the sentence with a period.
- **Name the feature and the page in Title Case**, the way existing entries do — e.g. "Kit User
  Path", "the Hits page", "Missed Opportunities", "Client Analytics", "Dashboard", "ClearPlan",
  "FlexSearch". Where a change lives on a specific screen, say so ("…on Kit User Path", "…in the
  topbar").
- **Pro features use the `Pro Feature:` prefix** — either just the name ("Pro Feature: Hit
  Clustering") or a short descriptive sentence ("Pro Feature: Hit Address Distance showing
  proximity to hotlist addresses on the Hits page.").
- **Customer-benefit framing, never implementation framing.** Say what changed for the user, not
  how it was built. No code, no package names, no class/table/internal names, no sensitive details
  about how the app works under the hood.
- **HTML only when an existing entry would** — sparingly, for a link (`<a href="/app/profile">Profile</a>`)
  or a small clarifying note (`<br/><small>…</small>`). Don't add it by default.
- **Vague is fine for backend/perf work**, matching entries like "Improved data accuracy and
  loading speed." or "Minor backend improvements and processing updates." Don't over-explain.

## What to include vs. exclude

Include user-visible feature additions, changes, and bug fixes. **Exclude** (these never get a
changelog line):

- `GHA deploy` commits and the changelog/version bump itself.
- Typos, file renames, code formatting/whitespace, pure refactors, dependency bumps, and test-only
  changes.
- Claude/agent files (`.claude/**`), hidden/dotfiles (`.github/**`, `.junie/**`, …), and Laravel
  Boost regenerated docs (`CLAUDE.md`, `AGENTS.md`, `boost.json`) — internal tooling, not product.
- Anything already described in a previous changelog entry, unless it was fundamentally changed.

### Don't document what isn't usable yet (intentionally hidden / gated features)

The changelog is a promise that something is **available now**. A feature can be merged and deployed
but still hidden behind a gate, so customers can't actually use it — those must **not** be
documented until the gate is open. The recurring example: **Clarity Scout** ships in the code but is
locked behind a config change, so it stays out of the changelog until it's turned on.

Before writing a line for a new feature, check whether the change in the diff is gated, and skip it
if so:

- **Feature flags (Pennant):** the feature is behind `Feature::active(...)` / `Feature::for(...)`
  and isn't broadly enabled. Off-by-default or partially-rolled-out flags → not yet available.
- **Config / env gates:** the code path is guarded by a `config(...)` value or env flag that is off
  by default (Clarity Scout's pattern).
- **Pro / tier gates that aren't live**, or anything wrapped in an explicit "hidden", "disabled",
  "beta", or admin-only guard.

When you can't confirm a feature is actually enabled and reachable by customers, **leave it out** —
a held feature can be documented in the release where its gate flips on. (This is changelog-only;
gated features are still fair game for QA, which can flip the flag to test them.)

Prioritize the larger feature changes; collapse several small related commits into a single
customer-facing line rather than one line per commit.

## Steps to execute

This command **only documents the change lines** — it does not own the version number.

1. Find the latest tagged release (e.g. `git describe --tags --abbrev=0`).
2. Analyze the commits and diffs from that tag to `HEAD`, applying the include/exclude rules above.
3. For each user-facing change, write ONE line in the established style — re-read the nearest
   existing entry first and match it.
4. Write those lines into the **`changes` array of the topmost entry** in `config/changelog.php`
   (the release currently being prepped — its version key and `'date'` already exist, created by
   the operator / the `release-prep` skill). Fill an empty/placeholder `changes` array; if it
   already has lines from a prior run, reconcile in place rather than duplicating.

**Do NOT** determine, bump, or invent a version number; do NOT add, rename, or re-date the version
key; do NOT edit `composer.json`; do NOT run `composer install`. The release number is owned by the
operator and propagated by the `release-prep` skill — this command's only job is the change prose.

If there is no in-progress entry at the top to fill (e.g. the topmost entry is an
already-released/dated version with content), **stop and ask** which version key these changes
belong to — never create or number one yourself.

Write only into that entry's `changes`; never reword or reorder existing entries.
