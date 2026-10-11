# Monitor loop

How `release` Phases 8–9 watch production for 2 hours after the deploy, alert in-thread on errors the
release introduced, and — on confirmation — turn a real one into a Jira ticket with a session already
working it (§Triage). Two sources: the prod **Laravel log** (source of truth) and **Datadog** (safety net).
Inputs from [`deploy-cycle.md`](deploy-cycle.md): `$T0` (deploy time, UTC ISO — for Datadog `from`),
`$T0_EPOCH` (deploy time, epoch — for elapsed math), `$VPN_WAS` (`up`/`down`), `$DEPLOY_TIP` (the sha prod
is running), and the Phase-1 ticket inventory (to phrase alerts against what shipped).

```bash
TLT="$HOME/Herd/clarity/.claude/skills/terminal-log-triage"
PROD_HOST="10.100.3.142"                        # pull_logs.sh host arg (config.local.sh maps it)
```

## Attribution — new signatures, not clock time

An error "from this release" is one whose **signature** is **new** after the deploy, or was rare before
and now spikes. **One canonical format everywhere** — `SEEN` keys, `alerts[].sig`, and `triage[].sig` all
use exactly this, or the dedupe that every downstream rule leans on silently breaks:

```
<ExceptionClassBasename>@<app-relative path>            e.g.  QueryException@app/Clickhouse/Queries/HitStatsBatchQuery.php
<normalized message, collapsed digits/ids>              when there is no trace
dd:<fingerprint>                                        Datadog issues, namespaced — never mixed into the log-derived keys
```

Basename (not FQCN), app-relative path (not absolute), **no line number** (lines shift between deploys and
would blur spike-matching). Before triaging a Datadog-only `dd:` signature, check whether a log-derived
signature for the same error already exists (same class + frame in the sample event) — one real error seen
by both sources is *one* incident, not two. Attribute by signature, never by comparing the prod log clock to the local clock (they may be
in different timezones; `pull_logs.sh since=` cutoffs are computed on the local clock, so a window may
include extra lines — the signature dedup absorbs that safely). Over-pulling is free; **under-pulling is
the only danger** — if the local clock is *ahead* of the prod-log timezone, a tight `since=` could clip
recent lines and miss a real error. So always pull a generously wide window (below), and if the two
clocks are known to diverge, prefer `day=today` (the whole daily file — no cutoff, tz-immune) when prod
is on the daily channel.

Keep **`SEEN`** as a map of signature → baseline count (not a bare set). Alert on a signature **absent
from `SEEN`**, or a `SEEN` signature whose per-tick count jumps well above its stored baseline. Add every
reported signature to `SEEN` and **persist it with its count in the state file** — so both the
absent-check and the spike-check survive a mid-window context reset (a bare list would lose the rates).

## Datadog — resolve once, query per tick (no VPN)

**Who:** the window coordinator runs every Datadog query in this file (service resolution, the baseline
pre-now query, the per-tick check, and the §Triage queries) from its own box and hands the results to the
release operator, who does only the Mac mini log pulls.

The Datadog MCP (`mcp__7c05626e-…`) reaches Datadog over the internet — **no VPN needed**. Its tools are
deferred: load them via ToolSearch, and per the server's own instructions load the skill guides first:

```
ToolSearch: +datadog search_datadog_logs
ToolSearch: select:mcp__7c05626e-1805-40d0-9326-604a84e44c9b__search_datadog_services,mcp__7c05626e-1805-40d0-9326-604a84e44c9b__search_datadog_logs,mcp__7c05626e-1805-40d0-9326-604a84e44c9b__search_datadog_error_tracking_issues,mcp__7c05626e-1805-40d0-9326-604a84e44c9b__list_datadog_skills,mcp__7c05626e-1805-40d0-9326-604a84e44c9b__load_datadog_skill
```

Then, once, before the loop: `load_datadog_skill('datadog/logs')` (+ `datadog/error_tracking`) for query
syntax, and **resolve the service live** — `search_datadog_services` filtered `env:prod`, confirm
**`platform`** (the repo's metric namespace is `clarity.*` and `APP_ENV=production`; the service tag is
attached by the Datadog agent, not hardcoded in the repo, so verify rather than assume). If resolution
fails, log a caveat and lean on the Laravel-log source of truth for that run.

Per-tick Datadog query, windowed from the deploy:
- `search_datadog_error_tracking_issues` and/or `search_datadog_logs`, query
  `service:platform env:prod status:error`, `from = $T0` (or the last tick), grouped/deduped by
  issue fingerprint. New fingerprints since `$T0` that aren't in `SEEN` → candidate alerts.

## Baseline — seed SEEN (Phase 6, BEFORE the deploy)

Seed the baseline **before** `app:deploy` runs (Phase 6 preflight, before Gate 3). This is the whole
point: an error the release starts throwing in its first seconds must NOT be pre-loaded into `SEEN` and
silenced for the window. Pulled pre-deploy, the baseline can only hold errors that existed *before* this
release — exactly the reference we want.

```bash
# Read-only prod-log pull over the tunnel the release operator already holds (VPN_WAS=up) — never cycles it.
LOG=$(bash "$TLT/scripts/pull_logs.sh" "$PROD_HOST" since=1d --no-vpn)     # wide pre-deploy window (matches the 24h Datadog baseline)
```

Parse `$LOG` for lines `[YYYY-MM-DD HH:MM:SS] env.LEVEL: …` at `ERROR`+ / exceptions / the
`clarity.query_timeout` marker; compute each signature; add all to `SEEN`. Query Datadog over a
**pre-now** window (`from = now − 24h`, `to = now`) with `service:platform env:prod status:error`
and add those fingerprints to `SEEN` too — same rationale (only pre-release errors). Report a one-line
baseline summary (e.g. "baseline: 3 recurring signatures, all known"), then **write the state file**
(below) so the baseline survives a mid-window context reset. Fill `merge_sha` and `base_tag` (Phase 1's
range base — the *previous* release tag) now; `deploy_tip` lands after 5b's detector. If any were lost to
a reset, re-derive from `origin/deploy` (`rev-parse` for the tip, the merge commit under the `GHA deploy`
tip for `merge_sha`) and from `git describe --tags --abbrev=0 "${REL_VERSION}^"` for the base tag —
never from memory.

> One held tunnel, one owner: the release operator brings the VPN up once with `vpn.sh connect` at the
> start of Phase 6 (before this baseline and Gate 3; if sudo would prompt, stop and report), confirms the
> Mac mini still responds, holds it through the 2-hour monitor, and runs `vpn.sh disconnect` once after the
> final pull (`VPN_WAS=up`, `held_tunnel:true`). Every `pull_logs.sh` call (baseline + ticks) passes
> `--no-vpn`; nothing connects or disconnects mid-window. One short Mac mini call at a time. Never trust
> `vpn.sh status`; reach = whether the pull itself works.

## State — resumable across the 2-hour window

The loop spans ~9 wakes over 2 hours; each Bash call is a fresh shell and the conversation may be
summarized mid-window. Persist everything the loop needs to a scratch file so `SEEN`, `T0`, and the tick
index survive that:

```bash
mkdir -p "$TARGET/.context"                        # a cutover-only run on a fresh main checkout has no .context/
STATE="$TARGET/.context/release-monitor.json"      # gitignored ($TARGET = repo dir, from SKILL.md)
# exclude .context once — resolve the common-dir to an ABSOLUTE path first (on a main checkout
# `--git-common-dir` is the RELATIVE ".git", wrong whenever the shell isn't sitting in $TARGET):
GD="$(git -C "$TARGET" rev-parse --git-common-dir)"; case "$GD" in /*) ;; *) GD="$TARGET/$GD" ;; esac
EX="$GD/info/exclude"
grep -qxF '.context/' "$EX" 2>/dev/null || echo '.context/' >> "$EX"
# Written at baseline (...), t0/t0_epoch filled at deploy, updated every tick. Shape:
# { "version":"vX.Y.Z", "status":"monitoring",      # "monitoring" while live; "stopped" once closed
#   "t0":"<ISO>", "t0_epoch":<n>, "vpn_was":"up",
#   "held_tunnel":true,                             # the release operator holds one tunnel up for the window
#   "dry_run":0,                                    # read from HERE, not just the shell — survives a context reset
#   "merge_sha":"<sha>", "deploy_tip":"<sha>",      # from deploy-cycle.md Phases 5/5b
#   "base_tag":"<vX.Y.Z>",                          # the PREVIOUS release tag (Phase 1's range base) — §Triage
#                                                   # step 3 needs it; naive `git describe` after Phase 7
#                                                   # publishes this Release (and its tag) returns THIS
#                                                   # release's tag → empty range → misattribution
#   "tick":<n>, "seen":{"<sig>":<baseline_count>, …},# counts, so spike-detection survives a context reset
#   "alerts":[{"tick":N,"sig":"…","source":"prod-log|datadog"}],
#   "triage":[{"tick":N,"sig":"…","severity":"S2","attribution":"attributable",
#              "ticket":"DEV-XXXX","action":"created|reopened|comment-only|declined",
#              "spawn":"<slug|null>","gate":"approved|declined|pending"}],
#   "pending":{"sync_main":false,                   # false | "conflict" | "skipped"  (Phase 7b)
#              "jira_closeout":"todo"},             # "todo" → "deferred" → "done". TRI-STATE, initialized
#                                                   # "todo" at this baseline write: a boolean cannot tell
#                                                   # "never ran" from "done", and a context reset in the
#                                                   # tick-0→T+5 gap would silently lose the whole closeout
#   "jira":{...}, "release":"published|skipped",    # 8b's outcome + the GitHub Release result
#   "tag":"vX.Y.Z",                                 # the tag name ($REL_VERSION), recorded even when skipped
#   "outstanding":["..."]  }                        # persisted AS PRODUCED — the Release Report is rebuilt
#                                                   # from $STATE alone, so anything not written here is
#                                                   # lost to a context reset
```

On every wake, **read `$STATE` first** — recover `T0_EPOCH` (→ `elapsed_min`), `SEEN`, and the tick
index — do the tick, then write it back. Never rebuild `SEEN` from memory alone: a summarized context
would lose it and the next tick would re-alert on every ambient error.

## Loop — cadence, ticks, alerts (Phase 9)

**Cadence (elapsed from `$T0`):** the immediate post-deploy check is **tick 0** (Phase 8, at ≈T0). Then
every **5 min for the first 20 min** → T+5, T+10, T+15, T+20; then every **20 min** → T+40, T+60, T+80,
T+100, T+120. Stop after T+120 (2 hours) — tick 0 plus 9 cadenced ticks.

Compute the next delay from elapsed time, so the cadence is correct even across a self-paced wake:

```bash
elapsed_min=$(( ( $(date +%s) - T0_EPOCH ) / 60 ))     # tz-safe: both are plain epochs
if   [ "$elapsed_min" -ge 120 ]; then next=stop
elif [ "$elapsed_min" -lt 20  ]; then next=5
else next=20
fi
```

**Each tick:**
1. **Datadog** (window coordinator, no VPN) — the coordinator runs the per-tick query each slot; collect new fingerprints vs `SEEN`.
2. **Prod log** (`--no-vpn`, over the held tunnel) — `LOG=$(bash "$TLT/scripts/pull_logs.sh" "$PROD_HOST" since=<elapsed+15>m --no-vpn)`
   sized to cover deploy→now with a wide margin (never under-pull); parse ERROR+/exceptions/
   `clarity.query_timeout`; collect new signatures vs `SEEN`. Never connect or disconnect the VPN per tick.
   If the pull fails on reach, stamp the miss and restore the held tunnel **once** in a separate short call
   (one change, then confirm the Mac mini responds) before the next pull; if it drops again, stop and ask.
3. **Alert or heartbeat, then triage** — if there are new signatures from either source, **post the
   plain-text alert in the thread now** (never batch to the end, and never wait for triage to finish),
   then run §Triage on each new signature. Otherwise post a quiet one-liner. Add everything reported to
   `SEEN`, bump the tick index, and **rewrite `$STATE`** before ending the tick.

   **Triage must not distort the cadence.** It can take minutes, but the next delay is always recomputed
   from `elapsed_min` against `T0_EPOCH` — so a long triage consumes (or skips) a slot rather than sliding
   the whole schedule. If the next tick comes due mid-triage, finish triage, write `$STATE`, then take the
   tick immediately.

**Scheduling.** Each tick is triggered by the window coordinator's slot ping at the cadence above — the
release operator arms no wakes of its own (no `/loop`, `ScheduleWakeup`, routine, or `sleep` re-arm). Use
`next` only to label the slot; once `next=stop`, run §Stop. Keep the run in the current thread so alerts
land where the user is.

**Alert format** (per new signature):

```
🔴 New error since deploy of vX.Y.Z  ·  <source: prod log | Datadog>  (tick T+NN)
   <exception class / normalized message>
   at <first app frame or route/service>
   count this window: <n>   first seen: <ts>
   likely area: <touched surface from the release inventory, if it maps>
```

Map the signature's file/frame back to the Phase-1 inventory's `files[]` when you can, so the alert
points at the suspect ticket (e.g. "likely area: Hits page — DEV-XXXX"). If it maps to nothing shipped,
say "not obviously from this release" — still report it (better safe than sorry, per the request).

## Triage — diagnose, decide, escalate (Gate T)

Fires **per new signature**, inside the tick that found it, **after** the 🔴 alert is already posted.
Steps 1–4 are entirely read-only. Only Step 5 writes, and only behind a confirm.

**Skip immediately if this signature has already been triaged.** Check `$STATE.triage[]` for a matching
`sig` — if one exists with any `gate` other than `pending`, just count the occurrence and move on. A
signature that persists across all nine ticks must be gated, filed, and spawned **exactly once**; this
check is what prevents nine duplicate tickets, and it lives in the state file so it survives a context
reset.

### 1. Evidence from the log you already have

`pull_logs.sh` wrote to a **local** scratch path — reuse `$LOG`. **Do not re-pull.** Each pull is another
Mac mini call, and a bare re-pull fights the held tunnel (pass `--no-vpn` if you truly
must).

**Never SSH for diagnosis.** Evidence comes from exactly two sources: the already-pulled `$LOG` and
Datadog. If the pulled window turns out to be too small, re-pull **via `pull_logs.sh`** (it is the only
sanctioned log read — see `deploy-cycle.md` §Prod SSH contract); never a hand-rolled remote `grep`/`tail`/
`cat`. `prod-ssh.sh verify` exists for deploy-*state* reconciliation, not for investigating errors — an
incident is exactly the moment the temptation to "just check something on the box" appears, and exactly
the moment it is out of bounds.

**Match fixed strings, never regex.** The signature *is* the search key, and it is full of regex
metacharacters — `\` in namespaces, `/` in frames, `()` in messages. Interpolated into `grep -E` or an awk
`/re/` literal, `App\Filament` matches nothing (silently) and any `/` is a syntax error — empty evidence
during a live incident, feeding a wrong severity call. So: `grep -F`, and `awk -v` + `index()`:

```bash
SIG='<the raw exception class or normalized message — used VERBATIM as a fixed string>'
# Full entry + trace: from the matching line to the next log timestamp. Saved for the frame extraction.
awk -v s="$SIG" 'index($0,s){f=1} f{print} f && /^\[[0-9]{4}-[0-9]{2}-[0-9]{2} / && !index($0,s){exit}' "$LOG" \
  | head -n 120 | tee /tmp/triage-entry.txt
# The app-frame chain — FROM THE EXTRACTED ENTRY, never from $LOG: the whole window's log contains other
# errors' frames, and "the first frame in the file" maps the wrong file to the wrong ticket.
grep -oE '/(app|routes|config|database)/[A-Za-z0-9_/.]+\.php:[0-9]+' /tmp/triage-entry.txt | head -n 20
# Rate, plus first/last inside this window.
grep -Fc "$SIG" "$LOG"
grep -F "$SIG" "$LOG" | sed -n '1p;$p' | cut -c1-21
# Blast radius from Laravel's exception context, when present.
grep -F "$SIG" "$LOG" | grep -oE '"(userId|user_id|organization_id|org_id)":[0-9]+' | sort -u | wc -l
grep -F "$SIG" "$LOG" | grep -oE '"url":"[^"]+"' | sort | uniq -c | sort -rn | head
```

### 2. Datadog — and the pre-release control window

The window coordinator runs these from its box (§Datadog). Run **three** queries, not one:

1. **Post-`T0` rate** — `search_datadog_logs`, `service:platform env:prod status:error <fragment>`,
   `from = $T0`, `to = now`. Count, per-minute shape, whether it is accelerating.
2. **The pre-release control** — the **same** query with `from = now-7d`, `to = $T0`. **This is the single
   most decisive check in the whole section.** Zero hits ⇒ release-attributable. Non-zero ⇒ pre-existing,
   and the 24-hour baseline simply missed it because the signature is rarer than daily — downgrade it and
   say so rather than blaming the release.
3. **The Error Tracking issue** — `search_datadog_error_tracking_issues` for the fingerprint →
   `firstSeen` / `lastSeen` / total occurrences / affected users. A `firstSeen ≈ $T0` corroborates 1 + 2.

If Datadog is unreachable, say so and lean on the prod log; note in the ticket that the control window
could not be checked.

### 3. Map it to the release inventory

```bash
FRAME=app/Filament/Resources/Hits/HitResource.php     # the first app frame from Step 1
BASE_TAG=$(jq -r .base_tag "$STATE")                  # from $STATE — NEVER `git describe` here: Phase 7
                                                      # already published THIS release (and its tag), so a
                                                      # naive describe returns it → empty range → everything
                                                      # reads "unrelated" → nothing gets filed
git -C "$TARGET" log --oneline "$BASE_TAG"..HEAD -- "$FRAME"   # which release commit last touched it?
```

Then read that code locally — the working tree is at the deployed sha, so what you read is what is running.
Land on one verdict:

- **`attributable`** — the frame is in an inventory ticket's `files[]` **and** the control window is empty.
  Name the ticket.
- **`plausible`** — no direct file hit, but the failing surface is downstream of something shipped (same
  model, same page, same queue). Say why.
- **`unrelated`** — the control window is non-empty, or nothing shipped is anywhere near it.

### 4. Severity and warrant

| Sev | Priority | Shape |
| --- | --- | --- |
| **S1** | `Highest` | Hard 500 on a core route; data loss or corruption; auth/tenancy bypass; a queue in a retry storm; errors on **every** request to a surface |
| **S2** | `High` | A feature shipped in *this* release is broken for everyone who uses it; silent notification/alert delivery failure; a scheduled command throwing on every run. Recurring: ≥3 events across ≥2 ticks |
| **S3** | `Medium` | Degraded but survivable — one widget errors, `clarity.query_timeout` on a heavy report, a single-org edge case; a workaround exists |
| **S4** | `Low` | Single occurrence; third-party or transient (SQS, HTTP, ClickHouse connect timeout); bot/scanner-triggered 500; a known ambient signature that spiked mildly |

Before assigning, run the signature past `terminal-log-triage`'s noise list
(`$TLT/ignore-patterns.txt`) — a substring match there is 🔇 known noise and never gets a ticket.

**Does it warrant a ticket?**

- **S1 → always, and lead with rollback.** A ticket is not the response to an S1; it accompanies one.
  Recommend reverting the RC merge or an immediate hotfix **first**, then the ticket.
- **S2 → always.**
- **S3 → only if** it recurs in ≥2 ticks **or** affects more than one organization. Otherwise Release
  Report only.
- **S4 → never.** Say "logged, not filing" so the operator knows it was a decision, not an oversight.
- **`unrelated` at any severity → do not file from this skill.** It is not this release's ticket; note it
  and let normal triage own it.

**When a ticket is warranted, run the existing-ticket JQL probes now, before the gate** —
[`jira-writeback.md`](jira-writeback.md) §Find an existing ticket first, the *read-only* searches only.
The Gate T prompt must state whether an open ticket already matches (its `Existing` line and its
create-vs-re-open proposal both depend on it); the *writes* in that section stay behind the confirm.

### 5. Gate T — one confirm per incident

Post as plain text and wait. This single yes covers **both** the Jira write and the spawn:

```
🔎 Triage — new error since the v3.9.1 deploy (tick T+15)

  Signature    TypeError: App\Filament\…::table(): Argument #1 must be of type Table
  Where        app/Filament/Resources/Hits/HitResource.php:88
  Severity     S2 (High) — the Hits page 500s for every user who opens it
  Attribution  attributable — 41 occurrences in 15 min across 7 orgs;
               ZERO occurrences in the 7 days before T0; file last touched by DEV-1933 in this release
  Existing     no open DEV ticket matches this class + frame

With your go-ahead I'd:
  1. CREATE a DEV Bug — "Hits page 500s for all users after v3.9.1 — TypeError in HitResource::table"
     priority High, Application=Clarity, linked Relates → DEV-1933
  2. SPAWN a separate Claude Code session to work it

Monitoring continues either way — this does not pause the 2-hour watch.
Reply "yes" to both, "ticket only", or "neither".
```

Rules: **one confirm per incident, never blanket.** A yes on tick 3 does not authorize tick 5.
**Append the `triage[]` entry with `gate: "pending"` at the moment you POST this prompt** — not when the
next tick comes due — so a context reset before the reply cannot lose the open question. `neither` →
update it to `action: "declined"`. If the user does not answer before the next tick, **keep monitoring**
and re-surface the decision once, setting `resurfaced: true` on the entry so a post-reset agent neither
re-asks twice nor never. A reply of "yes" / "ticket only" / "neither" arriving mid-window (including after
a summarization) keys off the `gate:"pending"` entries in `$STATE.triage[]` — if more than one is pending,
ask which incident the reply means; never guess. Never auto-proceed on silence. For an S1, lead the gate
with the rollback recommendation and put the ticket and spawn second.

### 6. Act, record, keep ticking

**This section owns every `triage[]` write** — the `pending` entry at post time (step 5), `declined` on a
no, and the final entry on approval. [`jira-writeback.md`](jira-writeback.md) §Triage tickets performs the
Jira writes and the spawn and *returns* the values (`key`, `action`, `spawn` slug); it writes nothing to
`$STATE` itself — two writers would double-count every incident in the Release Report.

On approval: hand off to that section for the create/re-open; when the ticket key comes back, **update the
pending entry to its final shape before the spawn runs** (so a crash between the create and the spawn is
recoverable — the ticket exists and the state says so), then let it spawn, fill in the slug, rewrite
`$STATE`, and arm the next wake on the normal cadence. The spawned session is fully independent — its own
worktree and `handoff/<slug>` branch — so it never touches `$TARGET` or the cadence.

## Stop — manual ("stop monitoring") or T+120

The loop ends **either** at T+120 **or** the moment the user says **"stop monitoring"** (/ "stop the
monitor" / "end monitoring" / "halt monitoring") — a manual interjection mid-window. On either trigger:

1. **Halt the loop.** Tell the window coordinator to stop the slot pings. No further ticks run.
2. **One final check** (skip only if the user said "stop *now*"): run a last tick — Datadog + a
   `pull_logs.sh --no-vpn` pull from `T0` — so the report reflects the latest state; fold any new signatures into
   `$STATE`.
3. **Take the held VPN down** (the final check was the window's last pull): `vpn.sh disconnect` once, in
   its own call. Decide from `$STATE` (`held_tunnel:true`), not a live `vpn.sh status`; then set
   `held_tunnel:false`.
4. **Drain `pending` — all three kinds.**
   - `pending.jira_closeout` is `"todo"` or `"deferred"` → Phase 8b never completed (deferred on a 🔴, or
     the window closed before its slot). Run [`jira-writeback.md`](jira-writeback.md) §Release write-back
     **now**, with the window's outcome in hand — unless 🔴 signatures are still open and unexplained, in
     which case recommend holding it, say so, and add "Jira closeout NOT run" to the report's
     `Outstanding` line. Mark it `"done"` only when the batch actually ran.
   - `pending.sync_main` is `"conflict"` or `"skipped"` → carry it into the report as an operator to-do
     with the exact commands.
   - **`triage[]` entries with `gate:"pending"`** → an assembled diagnosis nobody answered. Do not drop
     them: re-present each once, and whatever is still unanswered goes into the report as an explicit
     **undecided incident** (severity, signature, the proposed action) — an S2 with a finished diagnosis
     must not evaporate because the window ended.
5. **Write the Release Report** (below) and hand its one-line outcome to
   [`changelog-and-email.md`](changelog-and-email.md) §Email for the PM note.
6. **Mark `$STATE` `"status":"stopped"`** — only now, after the report exists. Marking earlier orphans the
   run: a context reset between the mark and the report leaves a `stopped` file the trigger recognizer
   refuses to act on, silently losing the drained pendings and the report itself.
7. **Only then remove the state file.** The report and Phase 10 are both reconstructed *from* `$STATE` —
   deleting it before they are written loses the run if the context resets in between. Exception: when
   step 4 *held* the Jira closeout, keep the file (it is the only machine record that a closeout is still
   owed) and say so.

> **Recognising the trigger** (incl. after a context reset): "stop monitoring" means *this* release's
> monitor. Read `$STATE` (`.context/release-monitor.json`); if it exists with `"status":"monitoring"`,
> run this Stop procedure against it. `"status":"stopped"` but the file still present → the run closed but
> something is still owed (a held Jira closeout, per step 7's exception) — resume at step 4, don't re-run
> the loop. No state file at all → nothing to stop — say so.

### Release Report

The end-of-run artifact for the operator. Reconstruct it entirely from `$STATE` + the Phase-1 inventory
(so it's correct even mid-context-reset):

```
# Release Report — vX.Y.Z
Deployed:   <T0>   ·   deploy tip <DEPLOY_TIP>   (release <published|skipped> · tag vX.Y.Z)
Monitored:  T0 → T+<NN>m   (<stopped manually | full 2h>)   ·   <k> ticks
Result:     <✅ clean — no new errors in the window>  |  <🔴 N new signature(s)>
  - <sig> ×<count>   first seen T+<mm>   ·   <prod log|Datadog>   ·   likely area: <ticket/surface>
  …
Triage:     <none needed>  |  <n incident(s)>
  - <sig>  S2 attributable  →  DEV-XXXX <created|reopened|comment-only>  ·  session dev-xxxx-hotfix
  - <sig>  S4 unrelated     →  logged, not filed
  - <sig>  S2 attributable  →  ⏸ UNDECIDED — gate never answered; proposed: <action>. Decide before closing out.
Shipped:    <n> tickets — DEV-XXXX <title>, DEV-YYYY <title>, …
Jira:       <n> → Done · <n> already Done · <n> fix-versioned · <n> mismatched · <n> deferred-effect comments
            <fix version "Clarity vX.Y.Z" not found — skipped; nearest existing "Clarity vX.Y.0">
Outstanding: <hand-applied migrations, deferred-effect work awaiting a run, pending.sync_main, …>
Branches:   main…deploy = <0 0 | 1 0>   <in sync | sync deferred: see Outstanding>
Sources:    prod log (primary) + Datadog (service:platform env:prod)
Next:       <PM email draft below>  |  <🔴 hold the announcement — triage the flagged errors first>
```

`Outstanding` is the line that must never be dropped: a migration that `app:deploy` could not apply, a
deferred-effect ticket waiting on its next scheduled run, or a `deploy → main` sync that conflicted are all
invisible unless they are written down here.

If any 🔴 signatures are still open, lead with that and recommend holding the Phase-10 PM email — and the
Phase-8b Jira closeout, if it has not already run — until they're triaged.
