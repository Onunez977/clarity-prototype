# Jira write-back

The two places `release` **writes** to Jira. Everywhere else in this skill Jira is read-only (the
Phase-1 inventory enrichment in [`changelog-and-email.md`](changelog-and-email.md) §Jira enrichment).

- **§Release write-back (Phase 8b)** — close the tickets this release shipped: `Done` + resolution, the
  `Clarity vX.Y.Z` fix version, and a follow-up comment on the ones whose effect is *deferred*.
- **§Triage tickets (Phase 9)** — file or re-open a ticket for an error the post-deploy monitor caught,
  then spawn a background session to work it. Reached only from [`monitor-loop.md`](monitor-loop.md)
  §Triage; the **writes** run only after that section's per-incident confirm (Gate T) — the read-only
  existing-ticket JQL probes run *before* the gate, so the prompt can state what already exists.

---

## Setup — tools, constants, invariants

The Atlassian MCP tools are **deferred**; load them once before either section — with **exact names**
(verified live: `select:` does NOT glob — `select:mcp__*__getJiraIssue` returns "No matching deferred
tools found" and the first write call then fails schema validation):

```
ToolSearch: select:mcp__Jira__getJiraIssue,mcp__Jira__getTransitionsForJiraIssue,mcp__Jira__transitionJiraIssue,mcp__Jira__editJiraIssue,mcp__Jira__addCommentToJiraIssue,mcp__Jira__searchJiraIssuesUsingJql,mcp__Jira__createJiraIssue,mcp__Jira__createIssueLink,mcp__Jira__getJiraIssueTypeMetaWithFields,mcp__Jira__atlassianUserInfo
```

If the `mcp__Jira__*` names return nothing on this host, the same server may be mounted under a UUID
prefix instead (e.g. `mcp__0d6dde38-…__getJiraIssue` — see `changelog-and-email.md` §Jira enrichment);
fall back to a keyword query (`ToolSearch: +jira transitionJiraIssue`) and use whatever prefix it returns.

Constants, verified against the live DEV project — do not re-derive:

```
CLOUD_ID = "150e0ba0-1d8e-4ac4-82da-ee78a512c3cc"    # hardcoded; never getAccessibleAtlassianResources
PROJECT  = "DEV"        (team-managed / simplified)
Task = 10001  ·  Bug = 10002
Transitions (all isGlobal, all hasScreen:false, all reachable from any status):
  Done = 31  ·  To Do = 11  ·  In Progress = 21  ·  In Code Review = 4  ·  QA Ready = 2  ·  QA Complete = 3
Resolution*  = customfield_10302 → {"id":"10093"} is "Done"
Application  = customfield_10037 → {"value":"Clarity"}  (option id 10021 is equivalent) — REQUIRED on create
Priority     = Highest | High | Medium | Low   (the legacy Critical/Major/Minor/Show-stopper options
               exist in the field but are not what real DEV bugs use)
```

**Five invariants for every call below:**

1. **Resolve transitions by NAME, never by the id in that table.** Call `getTransitionsForJiraIssue`,
   match on `transitions[].name`, use the returned `id`. The ids above are today's values and are project
   config that can move. A target name that isn't offered is an **idempotent no-op**, not an error.
2. **Send a transition BARE — no `fields` alongside it.** Every DEV transition has `hasScreen: false`, so
   Jira rejects fields that aren't on a transition screen. Set `customfield_10302` in a *separate*
   `editJiraIssue` call, never bundled into `transitionJiraIssue`.
3. **`resolution` (the system field) is NOT editable in DEV.** It is absent from the project's
   create/edit field metadata; transition `Done` sets it to `{"id":"10000"}` on its own. Do not try to
   `editJiraIssue` it as a primary path — verify it, and if it's still null after the transition, record
   `resolution: MANUAL` and move on. Never let one field block the batch.
4. **Markdown, never wiki markup.** Every description and comment passes `contentFormat: "markdown"` and
   uses real GFM — `###` headings and `|` tables, not `h3.` or `||`. Wiki markup renders literally.
5. **Read before you write, and a Jira failure never aborts the release.** Each ticket gets a
   `getJiraIssue` first so a warm re-run skips what's done. On failure, note it against that ticket,
   continue the batch, surface the whole list at the end. Prod is already live by the time either section
   runs.

---

## Release write-back (Phase 8b)

Runs **after tick 0** (Phase 8) and before the T+5 tick. Two reasons for that slot: tick 0 is
time-critical and this is not, and the operator is demonstrably still at the keyboard two minutes after a
deploy they just authorized — which matters, because this section needs a human yes.

> **State first.** `pending.jira_closeout` is a **tri-state** initialized to `"todo"` at the Phase-6
> baseline write: `"todo"` → `"deferred"` → `"done"`. A boolean cannot distinguish "never ran" from
> "done" — with one, a context reset in the tick-0 → T+5 gap silently loses the entire closeout. Set
> `"done"` only after the batch actually runs; §Stop drains `"todo"` and `"deferred"` alike.
>
> **If tick 0 already flagged a 🔴 signature, do not run this yet.** Say so and set
> `pending.jira_closeout: "deferred"` — [`monitor-loop.md`](monitor-loop.md) §Stop runs it once the window
> has resolved. Marking a dozen tickets `Done` minutes before recommending a rollback is the one failure
> mode worth avoiding here.
>
> **Silence is not consent, and it must not stall the watch.** Gate 4 sits before the T+5 tick; if the
> operator hasn't answered by the time that tick comes due, set `"deferred"`, take the tick, keep the
> cadence, and re-surface the batch once — §Stop drains it either way. Never block the monitor on an
> unanswered prompt, and never proceed without the yes.

Input: the Phase-1 inventory (`[{key, title, why, files[], enriched}]`). Skip the `Unticketed` bucket
entirely — no key, nothing to write.

### Resolve the fix version once

```
getJiraIssueTypeMetaWithFields(cloudId, projectIdOrKey: "DEV", issueTypeId: "10001",
                               requiredFieldsOnly: false, maxResults: 200)
```

⚠️ **This response is large and will usually spill to a tool-results file** rather than returning inline
(~23 fields, and `fixVersions.allowedValues` alone runs ~50 entries). Be ready to read the saved path with
`jq`/`python3` instead of re-requesting it:

```bash
jq -r '.fields[] | select(.fieldId=="fixVersions") | .allowedValues[] | "\(.id)\t\(.name)\t\(.released)"' <file>
```

Match `name == "Clarity $REL_VERSION"` **exactly** and keep its `id` as `FIXVER_ID`. The site convention is
**`Clarity v3.9.0`** — the `Clarity ` prefix and the `v` both matter. `G vX.Y.Z` is a *different*
application's version line; never use it.

**If no exact match exists, set `FIXVER_ID=""` and skip fix versions for the entire release.** There is no
MCP tool that creates a Jira version (`fetch` is ARI-read-only; nothing else writes versions), and
`editJiraIssue` cannot conjure one — an unknown name just fails. JQL cannot probe this either
(`fixVersion = "Clarity v99.0.0"` returns zero results, not an error), so `allowedValues` is the only
source of truth.

**Expect this to be the common case for patch releases.** The project carries only `.0` minors
(`Clarity v3.4.0` … `v3.9.0`), so a `vX.Y.1` release will not have a version. Record exactly one line for
the Release Report, naming the nearest existing minor line so the operator has what they need:

```
fix version "Clarity v3.9.1" not found in Jira — fix versions skipped this release.
Nearest existing: "Clarity v3.9.0" (id 10708). To set them next time, create the version under
Jira → Project settings → Releases before running /release.
```

Then carry on: the transitions and comments are independent and still run.

### Gate 4 — one confirm for the batch

This writes to a shared system across N tickets and Jira has no undo, so it takes its own confirm rather
than riding Gate 3's deploy approval. Print the plan as a table and wait for an explicit yes:

```
Jira write-back for vX.Y.Z — 6 tickets

key        current status   → status   fix version              deferred-effect comment
DEV-2383   QA Complete      → Done     set Clarity v3.9.1       —
DEV-2385   In Code Review   → Done     set Clarity v3.9.1       yes (app/Jobs/RebuildKitTrips.php)
DEV-2386   Done             (skip)     already Clarity v3.9.0 ⚠ —
DEV-2390   In Progress ⚠⚠   EXCLUDED   —                        yes (migration — MANUAL step outstanding)
…
⚠  = fix version already set to something else; left alone, flagged in the report
⚠⚠ = not in a QA-terminal status (To Do / In Progress) — the commit's DEV-#### may be only part of the
     ticket's work, or not its main deliverable. EXCLUDED from the batch by default; the operator opts a
     ⚠⚠ ticket in by naming it in the yes ("yes, including DEV-2390").

Write these to Jira?
```

One yes covers the batch — do not prompt per ticket. If `FIXVER_ID` is empty, replace that column with
`skipped (no Clarity vX.Y.Z in Jira)` so the gate shows the truth.

### Per ticket, in order

1. **Read.**
   ```
   getJiraIssue(cloudId, issueIdOrKey: <KEY>, fields: ["status","fixVersions","resolution","customfield_10302","summary"])
   ```
   **Check `status.name` before transitioning.** `Done` is `isGlobal` with `isAvailable: true` even on an
   already-`Done` issue, so a naive re-run re-transitions it and churns the resolution date. Already
   `Done` → skip steps 3–4, log "already Done", still consider steps 2 and 5.

2. **Fix version — only ever fill an EMPTY field.** Requires `FIXVER_ID` non-empty.
   - `fixVersions` empty →
     `editJiraIssue(cloudId, issueIdOrKey: <KEY>, fields: {fixVersions: [{id: FIXVER_ID}]})`
   - already contains the target → nothing to do.
   - holds a **different** version → **change nothing.** Record a mismatch line for the report:
     `DEV-2386 carries Clarity v3.9.0, shipped in v3.9.1 — review`. Tickets get a fix version at planning
     time (DEV-2345 sat `In Progress` with `Clarity v3.9.0`), and that is the team's data, not ours to
     rewrite.

   > **Do not "simplify" this into an unconditional set.** `editJiraIssue` exposes only `fields`, which is
   > **set** semantics — a blind write wipes whatever was there. The empty-only rule is what makes it safe;
   > if you ever do need to add alongside an existing value, read the current list and write the union.

3. **Transition to Done.**
   ```
   getTransitionsForJiraIssue(cloudId, issueIdOrKey: <KEY>)   → find name == "Done"
   transitionJiraIssue(cloudId, issueIdOrKey: <KEY>, transition: {id: "<RESOLVED_ID>"})
   ```
   Bare — no `fields` (invariant 2).

4. **Resolution.** Re-read `["status","resolution","customfield_10302"]`. Expect `status.name == "Done"`
   and `resolution` populated by the transition. If `customfield_10302` ("Resolution\*") is still empty →
   `editJiraIssue(cloudId, issueIdOrKey: <KEY>, fields: {customfield_10302: {id: "10093"}})`. Every
   already-`Done` ticket in DEV carries **both** fields set to `Done`, so a ticket with only the status
   moved reads as half-finished in the board's filters. If `resolution` is null and cannot be set
   (invariant 3), record `resolution: MANUAL` — do not retry.

5. **Deferred-effect comment** — only when the ticket classifies as such (below), and **only after the
   double-post check**: re-read the issue with `fields: ["comment"]` and skip if any existing comment
   contains the literal heading `Shipped in Clarity $REL_VERSION`. Match on that **visible** heading, not
   the HTML marker — Jira's markdown→ADF ingestion has no comment node and may strip `<!-- … -->` from the
   stored body, in which case an invisible-marker check never matches and every warm re-run re-posts.

### Deferred-effect classification

`app:deploy` runs `git pull` + `composer install --no-dev` + `composer post-deploy`. Nothing else. So a
ticket whose change lives behind a queue, a scheduler, an ingest cycle, or a migration is **shipped but
not yet in effect**, and marking it `Done` with no note is misleading.

Classify from the inventory's `files[]`. A ticket is **deferred-effect** when any file matches:

| Pattern | Why the effect is not immediate |
| --- | --- |
| `app/Jobs/` | queued — and workers only run new code after the deploy's `horizon:terminate` restart |
| `app/Console/Commands/` (except `Dev/`), `routes/console.php` | waits on the next scheduled run |
| `database/migrations/` | **not applied at all** — see the migration note below |
| `app/Clickhouse/**/Views/`, `database/clickhouse/`, any `*Mv*` | waits on the next ingest, or a backfill |
| `app/Observers/`, `app/Listeners/`, `app/Events/`, `app/Pipelines/` | fires on the next domain event |
| `app/Notifications/`, `app/Mail/`, `app/Channels/` | delivery is queued |
| `app/Services/*Import*`, `*Sync*`, `*Backfill*` | waits on the next run |

Plus: any ticket whose changelog line was withheld by [`changelog-and-email.md`](changelog-and-email.md)
§Gated-feature exclusion — its effect waits on the flag flip, the same "shipped, not live" shape.

Everything else — `app/Filament/`, `app/Livewire/`, `app/Http/`, `resources/views|js|css`, policies, query
classes read by a page — is visible on the next page load. **No comment.** Do not comment on every ticket;
the signal is the point. A ticket touching both kinds is *mixed*: comment, but open by saying which half
is already visible.

**Resolve the real cadence** before writing the comment — a vague "runs on a schedule" is worth less than
the actual time:

```bash
CLASS=$(basename "$f" .php)                       # e.g. GenerateRdnCache
grep -n "$CLASS" routes/console.php -A 8
# real cadences in this repo: ->everyMinute() ->everyThirtyMinutes() ->hourlyAt(5)
#                             ->everyTwoHours() ->everyFourHours() ->dailyAt('5:00') ->dailyAt('6:00')
```

> **Migrations are the sharp case.** `app:deploy` runs no migrations, and prod `artisan migrate` is
> blocked outright (`graphqluser` lacks `CREATE` on database `zod`, so `ensureAppSchemaExists()` throws
> even on a no-op). A migration ships as an **outstanding manual step**: say so in the comment, and put
> the same line in the Release Report so it cannot be lost.

### Comment template

`addCommentToJiraIssue(cloudId, issueIdOrKey: <KEY>, contentFormat: "markdown", body: …)`.

The dedupe key is the **heading line itself** (`Shipped in Clarity $REL_VERSION` — per-ticket step 5
checks for it before posting); the HTML marker below is belt-and-braces only, since ADF ingestion may
strip it:

```
<!-- release-closeout:v3.9.1 -->
### Shipped in Clarity v3.9.1 — effect is not immediate

Deployed to production **August 20th, 2026**. This ticket's change runs through
`App\Jobs\RebuildKitTrips` (queued), so there is nothing to look at in the UI right now — the
effect appears only after the job next runs.

| What changed | Where | When you'll see it |
| --- | --- | --- |
| Trip segment rebuild | `app/Jobs/RebuildKitTrips.php` | next dispatch; workers restarted at deploy (`horizon:terminate`) |

**Verify with:** Kit → User Path for a kit with recent GPS — the trip segments should rebuild.
**Outstanding:** none.

Post-deploy monitoring: clean at time of writing.

_Posted by the `/release` skill during the v3.9.1 production cutover._
```

Swap the mechanism sentence and the **When you'll see it** column for the ticket's actual path — the
scheduled command *and its resolved cadence*, the materialized view and what refreshes it, the flag that
still has to flip. Where there is a manual step, **Outstanding** must name it concretely:

```
**Outstanding:** the migration in `database/migrations/2026_08_18_…_add_zone_to_kits.php` is
**not applied** — `app:deploy` runs no migrations and prod `artisan migrate` is blocked
(`graphqluser` lacks `CREATE` on db `zod`). It must be applied by hand, then
`ALTER … OWNER TO graphqluser`. Until then this ticket's change is inert in production.
```

If the monitor has already flagged a 🔴 signature, say so instead of "clean" and name the ticket it was
filed under.

### Report back — persist it, don't just print it

The Release Report is reconstructed **from `$STATE` alone**, and §Stop can run up to two hours after this
batch — a context reset in between loses anything that only went to the transcript. So as the batch
completes, write the outcome into the state file, then mark the tri-state done:

```
"jira": { "done": [...], "already_done": [...], "fixversioned": [...], "mismatched": ["DEV-2386: carries v3.9.0"],
          "manual": ["DEV-2391: resolution"], "comments": [...], "fixver_skipped": "Clarity v3.9.1 not in Jira",
          "failures": [] },
"outstanding": ["DEV-2390 migration 2026_08_18_…_add_zone_to_kits.php NOT applied — apply by hand + ALTER OWNER"],
"pending": { "jira_closeout": "done" }
```

The `outstanding` entries — above all the *migration-not-applied* lines — are exactly what must survive to
the report; the mismatch flags and this list are what a reset would otherwise erase. The report's `Jira:`
line renders from `jira`, its `Outstanding:` line from `outstanding`.

---

## Triage tickets (Phase 9)

Entered from [`monitor-loop.md`](monitor-loop.md) §Triage, per error signature, **after** Gate T's
confirm. Inputs from that section: the signature, the assembled evidence, the grounded root cause, the
attribution verdict, the suspect release ticket (or none), and the severity → priority mapping.

### Find an existing ticket first

Two probes, widest first:

```
# A — free text across summary + description
project = DEV AND text ~ "<ExceptionClassShortName>" AND text ~ "<frame basename, no .php>"
  AND created >= -365d ORDER BY updated DESC
# B — narrower, summary only (catches house-style titles)
project = DEV AND summary ~ "<ExceptionClassShortName>" AND created >= -365d ORDER BY created DESC
```

`fields: ["key","summary","status","resolution","priority","fixVersions","issuetype"]`, `maxResults: 10`.
Keep the terms **short** — `~` is a fuzzy text match, so the class basename (`QueryException`, not the
FQCN) plus one distinctive fragment works; a full message with punctuation and IDs finds nothing. A hit
counts as a match only if its body names **both** the same exception class **and** the same app frame — a
same-class ticket about a different call site is not a match.

| Match state | Action |
| --- | --- |
| No match | **create** (below) |
| Match, **not** `Done` | **do not create.** `addCommentToJiraIssue`: "recurred in production after `$REL_VERSION`", with the evidence. Link to the release ticket if one exists. Raise `priority` only if the new severity is higher |
| Match, `Done` | **re-open:** transition by name to `To Do` (this workflow has no literal "Reopen"; `To Do` is the open-backlog state and is globally available) → best-effort `editJiraIssue(fields: {customfield_10302: null})` to clear the stale custom resolution (if it rejects the null, note that it needs clearing by hand) → **re-read the system `resolution`**: the Done transition set it and `editJiraIssue` cannot clear it (invariant 3) — if the To Do transition left it populated, the ticket still reads *resolved* in every `resolution is not EMPTY` filter, so record `resolution: MANUAL (clear by hand)` and say so in the recurrence comment → comment the recurrence → raise `priority` if warranted |
| Multiple credible matches | **present them and let the user pick.** Never guess |

### Create

```
atlassianUserInfo()                     # once — Dalton's accountId for the assignee
createJiraIssue(
  cloudId,
  projectKey: "DEV",
  issueTypeName: "Bug",
  summary: "<one line, surface-first — see template>",
  contentFormat: "markdown",
  description: "<the body below>",
  additional_fields: {
    priority:          {name: "<Highest|High|Medium|Low, from §Triage's rubric>"},
    customfield_10037: {value: "Clarity"},            # Application — REQUIRED on DEV
    assignee:          {id: "<accountId>"},
    fixVersions:       [{id: "<FIXVER_ID>"}]          # ONLY if §Release write-back resolved one; omit otherwise
  }
)
```

**No labels** — the repo's convention for machine-filed bugs. When a suspect release ticket maps, link
them (the `issuelinks` field's operations are `add|copy`, never `set`, so use the dedicated tool):

```
createIssueLink(cloudId, type: "Relates", inwardIssue: "<NEW_KEY>", outwardIssue: "<SUSPECT_KEY>")
```

Call `getIssueLinkTypes` first if `"Relates"` is rejected.

### Ticket body template

Mirrors the DEV house style (`## TLDR` → `## Context` → `## Acceptance Criteria` → `## How to verify` →
`## Related` → italic attribution). **Indent the stack block rather than nesting a fence** — a fenced
block inside this description collides with the outer fence when the skill copies it:

```
## TLDR

`<ExceptionClass>` started throwing in production **<mm> minutes after the v3.9.1 deploy** at
`app/Path/File.php:123`. <n> occurrences in <k> minutes across <m> organizations. Datadog shows
**zero** occurrences of this signature in the 7 days before the deploy, and the file was last
touched by DEV-XXXX in this release — so this is a v3.9.1 regression.

## Context

Detected by the `/release` post-deploy monitor, tick T+<NN>. Sources: prod Laravel log (primary)
+ Datadog Error Tracking (`service:clarity env:production`).

| | |
| --- | --- |
| Release | v3.9.1, deployed `<T0>` |
| Deployed commit | `<DEPLOY_TIP>` |
| First seen | `<ts>` (T+<mm>) |
| Occurrences | <n> this window · Datadog total <N> |
| Affected orgs / users | <m> orgs · <u> users |
| Pre-release control (7d before T0) | **0 occurrences** |
| Suspect ticket | DEV-XXXX — <title> |
| Severity | S<k> → priority `High` |

### Stack (first app frames)

    <ExceptionClass>
    at app/Clickhouse/Queries/HitStatsBatchQuery.php:69
    at app/Filament/Widgets/LiveHits.php:41

### Failing surface

`<route / page / job / command>` — <n> distinct URLs, top: `<url>`

### Suggested fix

1. <the concrete change, naming file and line>
2. <regression test: red before, green after>
3. <a guard, if the failure mode was silent>

### Data findings

<counts, rates, org/user breakdown, the pre-vs-post control numbers>

## Acceptance Criteria

* `<ExceptionClass>` no longer thrown on `<surface>` for the reproducing input
* Regression test covering the branch that failed — red before the fix, green after
* Datadog: zero new occurrences of this signature for 24h after the fix deploys

## How to verify after the fix

* Datadog: `service:clarity env:production status:error <fragment>`, from the fix's deploy time → 0 results
* Prod log: no `<ExceptionClass>` entries after the fix deploy

## Related

* **DEV-XXXX** — the v3.9.1 ticket whose change touched `<file>`
* Release v3.9.1 — deployed `<T0>`, commit `<DEPLOY_TIP>`

---

_Filed by the `/release` post-deploy monitor during the v3.9.1 production watch (tick T+<NN>).
Read-only diagnosis; no code changed._
```

For a **re-opened** ticket, post the same content as a comment under a
`### Recurred after Clarity v3.9.1 — <date>` heading, and say what is different this time (volume, a new
call site, a new org).

### Spawn a session on it

The ticket is filed; now put an agent on it. `$NEW_KEY` is the ticket the work should land on: the
newly-created key, or on the **re-open** path the matched existing ticket's key. A **comment-only**
outcome spawns nothing — the ticket is already owned and in flight. The launcher is the global `handoff`
spawner:

```bash
# Positional contract: <project> <slug> <model> <effort> <prompt-file> <worktree|inplace> <perm-mode>
SLUG="$(printf '%s' "$NEW_KEY" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9-' '-' | sed 's/-*$//')-hotfix"
PDIR=$(mktemp -d)
cat > "$PDIR/INITIAL_PROMPT.md" <<PROMPT
/ticket $NEW_KEY

Filed by the $REL_VERSION post-deploy monitor. Production is serving $REL_VERSION right now
(deployed $T0, commit $DEPLOY_TIP) and this is a live regression from that cutover, not routine
backlog work — treat it as a hotfix candidate and confirm the root cause against the deployed
code before changing anything.

Do NOT: run /release, touch production, SSH anywhere, bring up the VPN, or push to main or deploy.
The release session owns all of that and is still monitoring.
PROMPT
# spawn.sh ships with the insightlpr plugin. Bind it or fail loud — never run a
# half-resolved path (an unresolved plugin path is what silently skipped a whole
# script layer in DEV-2104/2105).
PLUGIN_DIR=""
for c in "${CLAUDE_PLUGIN_ROOT:-}" "$HOME/.claude/skills/insightlpr" "$HOME/Herd/claude-plugins"; do
  [ -n "$c" ] && [ -x "$c/scripts/spawn.sh" ] && { PLUGIN_DIR="$c"; break; }
done
[ -n "$PLUGIN_DIR" ] || { echo "FATAL: insightlpr plugin not found — cannot spawn the follow-up session."; exit 1; }
"$PLUGIN_DIR"/scripts/spawn.sh "$HOME/Herd/platform" "$SLUG" opus high "$PDIR/INITIAL_PROMPT.md" worktree auto
```

- **`worktree`, never `inplace`.** `$TARGET` is mid-release: it holds the monitor state file and its HEAD
  is the deployed sha. A spawned session gets its own worktree
  (`$HOME/Herd/platform/.claude/worktrees/$SLUG`, branch `handoff/$SLUG`) so it cannot disturb either.
- **`auto` perm mode.** Classifier-gated: safe actions proceed, risky ones pause. Never
  `bypassPermissions` for a session nobody is watching.
- **Slug must match `^[a-z0-9][a-z0-9-]*$`** or `spawn.sh` rejects it — `DEV-2400` → `dev-2400-hotfix`.
- **`spawn.sh` lives in the insightlpr plugin**, not `~/.claude/handoff/`. It moved there so it
  ships with the tooling instead of living unversioned on one machine; the old path is gone.
  Its ledger and logs still live under `~/.claude/handoff/` — that part is per-machine state.
- **Fire and forget.** The spawn is detached; never block a tick on it. The monitor owns this thread and
  keeps its cadence; the child reports in its own session.
- **Keep the prompt short but bounded.** `/ticket` reads the Jira ticket, which carries the full evidence;
  the prompt supplies only what `/ticket` cannot know — that prod is live on this version, and the
  hands-off list above.
- Report the slug in-thread so the operator can find the session.

### Return the outcome — the monitor owns the state write

**Do not write `$STATE` from this section.** [`monitor-loop.md`](monitor-loop.md) §Triage step 6 owns
every `triage[]` write (it already holds the `pending` entry from the gate, and only it can record
`declined`) — two writers would double-count every incident in the Release Report. Return these values to
it, ticket-first so the entry can be finalized **before** the spawn runs:

```
key:    "DEV-2400"                                    the created key, or the matched key on re-open
action: created | reopened | comment-only
spawn:  "dev-2400-hotfix" | null                      null for comment-only
```

The finished entry (written by §Triage step 6) looks like:

```
{"tick": 3, "sig": "QueryException@app/Clickhouse/Queries/HitStatsBatchQuery.php",
 "severity": "S2", "attribution": "attributable",
 "ticket": "DEV-2400", "action": "created", "spawn": "dev-2400-hotfix", "gate": "approved"}
```

The one-ticket-per-signature rule lives in §Triage's preamble ("skip immediately if this signature has
already been triaged") — it depends on `declined` and `pending` entries existing, which is exactly why the
monitor, not this section, holds the pen.
