---
ticket: DEV-2469
branch: claude/DEV-2469
base_sha: d85d327e5212ae4c49dc8597dc4f39994123e422
head_sha: 2cd68b6aab41dff8486754fba5adcc04a1cb9875
wip_sha: e69de29bb2d1d6434b8b29ae775ad8c2e48c5391
generated_at: 2026-09-14T12:54:39Z
revision: 1
scope: both
ch_strategy: docker
db: zod_claude_dev_2469
url: https://clarity-claude_dev_2469.test
site: https://clarity-claude_dev_2469.test
caps:
  dev_login: on
  persona_links: on
  herd: on
  clickhouse: docker
---

# Human QA Plan — DEV-2469

A new Company Metrics card, **Unique Scans to LPR Repossession**, at mockup slot row 2 col 4.

It shows **unique scans ÷ LPR repossessions** — "how many unique plates we scanned per LPR
repossession". A **ratio**, not a percentage, and not a count.

> ⚠️ **The Jira ACs on DEV-2469 are obsolete.** Oscar redefined the metric on 2026-09-10; the
> ticket body still describes the old `clarity.recovery_scans` spec, which is structurally 0
> against the current denominator. The corrected spec is Jira comment 39749 and
> `docs/investigations/DEV-2469/decisions.md`. QA against those, not the description.

## Personas

| Role | Email | Login |
| --- | --- | --- |
| Administrator, org 2 "Test Company", Clarity Pro Plus | test@tester.com | https://clarity-claude_dev_2469.test/__qa/login/1 |

The seeded user was granted Administrator **and** Clarity Pro Plus during agent QA so the
Company Metrics cards are unlocked. For **hp-7** you will need to remove one of those.

## What's in the QA database

Seeded into local Docker ClickHouse (`docs/investigations/DEV-2469/qa-ch-seed.sql`, reproducible):

- **132 scan rows carrying only 40 distinct plates** in Sep 2026 — the 3x duplication is
  deliberate, so a `count()`-instead-of-`uniqExact` regression would show as 15.0 not 5.0.
- **8 case-claims**, all recovered inside the claim + 7d window → `db_repos = 8`.
- A **today slice**: 1 claim (reusing an existing VIN so the month totals don't move) and
  12 scans carrying 6 distinct plates.

Expected: **This Month 5.0**, **Today 6.0**, **Last 7d 40.0**, **Last Month 0.0**.

## Happy path

- **hp-1** Log in. You land on **Company Metrics**. Scroll to the card grid. *(covers: AC-1)*
- **hp-2** Find **Unique Scans to LPR Repossession** — row 2, col 4, immediately right of
  *Unique Scans to Case Claims*. It reads **5.0** with This Month selected. *(AC-1; agent: A1)*
- **hp-3** Cross-check it against its own operands on the same screen: **Unique Scan Counts = 40**
  and **LPR Repossessions = 8**. 40 ÷ 8 = 5.0. If the card disagrees with its neighbours, that is
  the bug. *(AC-2, AC-3; agent: A1)*
- **hp-4** Also confirm **Scan Count = 132** beside **Unique Scan Counts = 40**. That gap is the
  plate dedup working on the seeded duplicates. *(AC-2)*
- **hp-5** Cycle the date filter: **Today → 6.0**, **Last 7d → 40.0**, **This Month → 5.0**,
  **Last Month → 0.0**. Each time, confirm LPR Repossessions moves with it (1 / 1 / 8 / 0).
  *(AC-4; agent: A2)*
- **hp-6** Read the card's description line. It should name the unit — "scans per LPR repo" —
  not a bare number and not a %. *(AC-1)*
- **hp-7** **Pro gate.** Remove the org's Clarity Pro Plus entitlement (or the user's
  Administrator role) and reload. The card must show the locked placeholder with **no value and
  no sparkline**. Restore it afterwards. *(AC-6)*

## Edge cases

- **hp-8** The card sits directly beside **Unique Scans to Case Claims**, which renders a
  **percentage** (37.5%). Confirm the two read as visibly different kinds of number. This is the
  whole reason this card is a plain ratio — if they look interchangeable, say so.
- **hp-9** **Unique Scans to Repo** (row 2, col 2) still reads **0** — it is a stub and DEV-2470
  is blocked. Confirm it is untouched.
- **hp-10** The sparkline: 13 bars, month labels ending at the current month. Labels are
  **UTC-anchored on purpose** even though you are on America/Chicago — if the last label looks
  "off by one" near a month boundary, that is the intended behaviour, not a bug.

## Not covered by agent QA — please do these by hand

- **hp-11** **Expand the card.** Agent QA never exercised the expansion interaction. Click it,
  confirm the detail view opens with the chart, and that opening a different card replaces it.
- **hp-12** **Browser console.** Agent QA read the DOM after load rather than installing an error
  collector. Open devtools, reload, and confirm no JS errors on the dashboard.

## Known behaviour changes — please sanity-check these against expectations

- **The denominator is the case-claim funnel**, the same number DEV-2464's *LPR Repossessions*
  card shows — **not** "repos our LPR contributed to". That is Oscar's definition, twice stated.
  If the resulting number doesn't read the way the business expects, that is a product finding,
  not a code bug.
- **The window applies to the CASE-CLAIM date**, not the repossession date. The recovered
  transition is unbounded forward, so a bar means "claims made in this month that went on to a
  repo". A scan may predate its repo by months.
- **Two cards now share cache keys.** *LPR Repossessions* and this card read one cached funnel
  result. If you ever see those two disagree in a way arithmetic can't explain, capture it — that
  interaction has been wrong twice in this ticket's history and is now under test.

## Open from code review — context, not steps

Review scored **92 / 76 / 72** against a threshold of 90, so the gate has **not** passed. Nothing
outstanding is a correctness defect; the notable one is that the numerator query has no cold
mutex, so under many simultaneous viewers it can run several concurrent copies (~2.44 GB server
peak each). You will not reproduce that by hand on a seeded box — it is recorded for the merge
decision, in `docs/investigations/DEV-2469/decisions.md`.

**Scale is unvalidated**: this box holds 132 scan rows; production holds ~60.7M.
