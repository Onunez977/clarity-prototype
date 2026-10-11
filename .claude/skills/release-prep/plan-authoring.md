# Release-scoped plan authoring

How release-prep Step 7 turns the ticket inventory into the two plan artifacts. The markdown
*shapes* are qa-prep's — read its templates from the plugin and do not duplicate them here:

`$PLUGIN_DIR/skills/qa-prep/plan-templates.md`

`$PLUGIN_DIR` is resolved once in `SKILL.md` Step 0. **Do not glob the plugin cache** — the
`insightlpr` marketplace is a *directory source*, so no cache copy exists and the old
`~/.claude/plugins/cache/insightlpr/insightlpr/*/…` path matched nothing (fixed 2026-08-13).

This file holds only the **release-specific deltas** from that template.

## The gate contract (read first — it constrains everything)

agent-qa consumes our plan only if its prep gate passes. The gate (agent-qa SKILL.md Step 0b +
Step 1) checks the **agent plan** for:

1. `head_sha:` == `git -C "$TARGET" rev-parse HEAD`
2. `wip_sha:` == `{ git -C "$TARGET" diff HEAD; git -C "$TARGET" ls-files --others --exclude-standard; } | git hash-object --stdin`
3. `url:` == `.env` `APP_URL`, and `db:` == `.env` `DB_DATABASE`
4. Frontmatter parseable — `head_sha:` / `wip_sha:` / `url:` / `db:` each at column 0, single space.

Therefore:

- **Author plans AFTER Step 6 has fully run** — the version write (`composer.json` `"version"` + the
  new `config/changelog.php` entry) and `/document-changes` filling that entry's `changes`. Those
  edits change the working tree; compute the anchors only once it has settled, or the gate trips the
  instant agent-qa runs. (No `composer install` — the `version` field doesn't affect the lock.)
- **Compute `head_sha`/`wip_sha` last**, right before writing the plan.
- Do **not** commit between release-prep and agent-qa — the auto-chain runs immediately, so this is
  normally moot, but a commit would move `head_sha` and re-trip the gate.

Step 9 self-verifies `GATE=pass` before chaining. If it fails, rewrite the anchors and re-check.

## Frontmatter — release deltas from the shared template

Populate the shared frontmatter normally, with these release-specific values:

| Field | Release value |
|---|---|
| `base_branch` | the **tag** (e.g. `v3.6.0`) — this is the deliberate divergence from qa-prep, which puts a branch here. It tells any reader the scope is release-wide. |
| `base_sha` | `git rev-list -n1 <tag>` |
| `head_sha` / `wip_sha` | computed last (gate anchors) |
| `scope` | almost always `both` (from the union diff, per [`release-inventory.md`](release-inventory.md) §4) |
| `ticket` | the release ticket if one was passed, else omit. Per-ticket `DEV-####` keys live in the body, not here. |
| `mode` | `worktree` (or `checkout` if run on the main checkout) |
| `ch_strategy` | `docker` \| `pgsql-bridge` \| `none` per provisioning |
| `caps` / `login` | platform defaults: `dev_login: on`, `login.type: dev-login`, `url_pattern: /__qa/login/{id}`, `postgis_patch: applied`, `herd: on` |
| `seeded:` | backfilled after the seeder runs |

## Release brief (both plans)

Open both plans with a short brief so the pass is purposeful, not a blind click-through: the release
number, what's shipping (the feature/ticket list with one-line what+why from Jira), the shared
surfaces it touches, the regression-risk areas, and the **dual goal — verify new (T1) AND confirm
existing unaffected (T2/T3)**. human-qa renders this; agent-qa reads it into its report but does
**not** pass it to the browser subagents — which is why the per-item prose must carry its own why
(see [`regression-strategy.md`](regression-strategy.md)).

## Lanes — one Jira ticket per feature, pre-grouped into ≤3

The agent plan's `### F<n>:` sections **are** agent-qa's browser lanes. agent-qa caps at 3 lanes and
folds the smallest together **blindly** — so with ~18 release tickets, release-prep must do the
grouping itself or agent-qa will merge 15 lanes meaninglessly.

**Pre-group the tickets into ≤3 lanes by surface affinity** before writing. Group by the area each
ticket's `files[]` touches — e.g.:

- `### F1: Hotlists & alerts` — tickets touching `app/Filament/**Hotlist*`, hit-alert logic.
- `### F2: Hits, Scout & dashboard` — scan/hit surfaces, dashboard widgets, ClickHouse-backed views.
- `### F3: Settings, billing & misc` — org settings, Pro/tier, webhooks, everything else.

Pick the groupings that fit the actual release; name them for their surface. Inside each lane:

```markdown
### F1: Hotlists & alerts

_Tickets: DEV-1898, DEV-1948_

- [ ] A1. DEV-1898 — <where-to-click + what-to-look-for> (covers: DEV-1898/AC-1; human: hp-2)
- [ ] A2. DEV-1898 — … (covers: DEV-1898/AC-2; human: hp-3)
- [ ] A3. DEV-1948 — … (covers: DEV-1948/AC-1; human: hp-5)
```

- **AC → item rule** (qa-prep's): each acceptance criterion that maps to a file in the range becomes
  an item; an AC with **no** corresponding diff in the range is a 🟡 note ("ticket asks for X —
  nothing in this range touches it"), not an item. A diff-anchored (unenriched) ticket derives its
  items from `files[]` directly.
- Item ids (`A1`, `A2`, …) are **global and sequential across all lanes**, so screenshot filenames
  never collide.
- The `Unticketed` bucket folds into the most-related lane (or its own lane if there's room within
  the ≤3 cap).
- **Regression rides in these lanes too.** Weave the T2 blast-radius + thin-T3 smoke items into the
  matching surface lane (a Hotlists regression check goes in the Hotlists lane) — never a 4th lane —
  with the what/why baked into the item prose. Full mechanics in
  [`regression-strategy.md`](regression-strategy.md).

### `### Ticket passing requirements`

A per-ticket AC roll-up — list each enriched ticket's ACs verbatim under its key
(`DEV-1898/AC-1: …`). This is what agent-qa reads as the coverage spec.

### `### B: Backend verification`

Aggregate the touched backend classes across the **whole range** (ClickHouse query classes,
services, jobs, commands). Same `B<n>` item shapes as the template (boot integrity; construct +
read-only-execute). One section, not per-ticket.

### `### What's in the QA database`

One-line counts (Postgres + ClickHouse) reflecting the release seeder's world. Flag any lane item
whose data the seeder couldn't create.

## Human plan — a feature-by-feature release tour

Shape per the template's human skeleton, but the **Happy path** is a walkthrough across the
release's headline features, ordered as a tour starting at the dev-login landing, grouped by
feature/ticket so the human can sign off feature-by-feature. Keep `(covers: DEV-XXXX/AC-n; agent:
A2)` cross-refs. **Edge cases** carry per-persona checks for the riskier tickets. Personas + Seeded
data are backfilled after the seeder runs.

Add a dedicated **`### Regression sweep`** section (`rg-<n>` ids) after Happy path / Edge cases,
carrying the full T2 + T3 battery per `$INTENSITY`, each step framed *"should be UNCHANGED"* — see
[`regression-strategy.md`](regression-strategy.md). human-qa renders any section verbatim, so this
just works with persistent checkboxes.

## Sticky ids

If `.context/qa-plan-*.md` already exist (a warm re-prep), read them first and reuse the ids of
unchanged items (`A<n>`, `hp-<n>`, `ec-<n>`, `rg-<n>`) so human-qa's checkbox state survives
regeneration.
