# DEV-2460 — mockup layout confirmation (the ticket's ⚠️ first task)

**Question (from DEV-2460 and the DEV-2409 epic):** row 2's left-to-right order was
*inferred* from the layout docblock written during DEV-2409 prep, not read off the mockup.
Confirm it against the mockup before freezing, because every sibling ticket cites it.

**Answer: the inferred order is CORRECT in every cell. Freeze it.**

## Source

Jira attachment on **DEV-2409**: `Screenshot 2026-08-21 at 7.29.09 AM.png`
(attachment id `24421`, 157 KB, uploaded by Oscar Nunez 2026-08-21 10:31 ET).
Read directly off the image in the Jira UI on 2026-09-01.

The *other* DEV-2409 attachment — `Screenshot 2026-08-27 at 9.12.32 AM.png` (id `24459`) —
is **not** a dashboard mockup. Per Oscar's own comment it is the Backyard **Repo Report**
screenshot supporting the case-claim redefinition. Do not confuse the two.

## The grid, as read off the mockup

| Row | Col 1 | Col 2 | Col 3 | Col 4 |
| --- | --- | --- | --- | --- |
| **1** | Scan Count | **Total Repossessions** | *Unique LPR Case Claims* | *LPR Repossessions* |
| **2** | *Uniqe scan counts* | *Unique scans to Repo* | *Unique scans to Case Claims* | *Unique scans to LPR Repossession* |
| **3** | *Unique Scans Leaderboard* | *Staging Live Hit Leaderboard* | *Missed Opportunities* | **LPR Recovery Leaderboard** |
| **4** | Live Hits | Direct Historical Hits | Staging Live Hits | Staging Historical Hits |

*Italics = card not built yet (an empty slot after this ticket).*
**Bold = one of this ticket's two relabels.**

Row 2 matches the ticket's inferred order exactly:
`Unique Scan Counts → Unique Scans to Repo → Unique Scans to Case Claims → Unique Scans to LPR Repossession`.

### Note — a typo in the mockup

The mockup renders row 2 col 1 as **"Uniqe scan counts"** (missing the `u`). The epic and
DEV-2467 both call it *Unique Scan Counts*. Treat the mockup's spelling as a mockup typo;
the ticket text wins on the label. Recorded here so a sibling does not "fix" the ticket to
match the image.

## Header row (the Kit Users destination)

The mockup's header row above the grid — the `HotlistCount::getStats()` row — reads
left-to-right:

1. Total Open Assignments — 2,865
2. Open Assignments with Plates — 2,683 · *Direct Hotlist Size*
3. Open Assignments Missing Plates — 182
4. Missing Plates Percentage — 6% · *Missing Plates / Total Open Assignments*
5. **Active Kit Users — 36 · *202 camera kits*** ← red-annotated in the mockup as the new one
6. Staging — 390,376 · *Approximate Staging Hotlist Size*

So **Active Kit Users lands at header position 5 of 6**, between *Missing Plates Percentage*
and *Staging* — not appended to the end. The mockup also keeps a description line under the
value (`202 camera kits`), so the move must carry the existing description, not just the count.

The ticket says only "becomes a header-row stat inside `HotlistCount::getStats()`"; the exact
slot is read off the mockup and recorded here.

## What the mockup does NOT settle

- Nothing about the Pro-gate locked state — the mockup shows a fully-entitled org, so it has
  no locked-card rendering to copy. That design is this ticket's own call.
- The red outlines are the mockup author's change annotations, not a UI treatment to build.
