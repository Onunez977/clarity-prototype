# DEV-2929 harness self-check (Plan)

**Verdict:** CLEAN → post checked Jira comment (`DEV-2929.jira-comment.checked.md`)
**Checked:** 2026-10-08 ~7:58am CDT against live Jira DEV-2929 + First Glance 43362 + PM rulings 2026-10-08

| Check | Result |
| --- | --- |
| Status READY (not PARKED) | PASS — L2 |
| Branch path = main→main (FG 43362) | PASS — L2 (base `main`, PR #511) |
| `ch_touch: no` present and unambiguous | PASS — §4 L68, §5 L72 (comment: "Data: none (ch_touch: no)") |
| Scope = live AC only | PASS — §2 copies AC1–AC5 verbatim, none derived; extra rows (Last Month, month edge, cross-year, toggle test) all trace to AC1/AC2/AC4/AC5, FG gate (1) or the PM ruling. §7 out-of-scope matches Jira + FG hard stop |
| #24 smallest change | PASS — two inline closures at L653 / L656 reusing `exportWindow()`; explicitly no new method/helper/constant/trait/class/config (§6 step 4); other chained calls untouched; blade untouched (Q2 contingency gated on a PM decision) |
| No query / window logic change (AC3) | PASS — §4 "Not touched" table: `exportWindow()`, `exportRowsSql()`, `resolveCardRange()`, `LprRepossessionsQuery`, exporter byte-identical; export-window test L1284 unchanged |
| TZ rules exact | PASS — months: full UTC calendar month from `exportWindow()` (K3, This Month = Oct 1–31); Today / Last 7d viewer TZ, Last 7d 8 days (K4); each bound formatted in its own zone, "No `setTimezone()`" (§6 step 2, K6); month-edge rows E1–E4 pin it |
| Ticket copy verbatim | PASS — T1/T3/T4 reproduce all five examples; en dash U+2013 enforced in tests via `"\u{2013}"`; "as CSV" only on month variant (PM ruling) |
| Expected strings hand-verified | PASS — T1 Oct 1–31, T2 Sep 1–30, T3 Sep 30–Oct 7, T4 Oct 7, E1 Aug 1–31, E2 Jul 1–31, E3 Jul 24–31 (Chicago 21:00 Jul 31), E4 Jul 31, Y1 Dec 29–Jan 5 (2027-01-05 18:00 UTC = 12:00 CST) |
| Citations spot-checked | PASS — Dashboard.php L175–180 / L307–310, DashboardWidget.php L107–112, Exporter L64–67, User.php L178–180, PG-015 L122–127, test L1149 / L1184, DEV-2904 review docs exist |
| #9 deviation recorded | PASS — §6 "Accepted provision deviation (#9)" |
| #489 soft collision noted | PASS — §6 "Soft collision" |
| No bot/tool names (Jira comment) | PASS — grep clean; harness edits: added Branch path line, replaced the template footer with the control-plane spec path. Signed "Plan" |
| No bot/tool names (committed spec mirror) | PASS — harness edits: internal role tags → `@Implement` / `@PM`, byline → "Plan". No content change |
| Plan run posted nothing / wrote only `.ai/specs/` | PASS — ALLOW_JIRA_POST absent; raw log has no addComment call; worktree `git status` clean apart from ignored `.ai/` `<agent-config-dir>/` |

**Harness notes (non-blocking, carry to Implement / Review):**
- Q1: confirm with installed Filament v5.6.0 that `cacheAction()` doesn't freeze the tooltip and how the tooltip attribute is encoded; decides whether §5 toggle-test step 5 (heading HTML substring) stays. Steps 2–4 alone satisfy AC2's server side.
- Q2: browser tooltip refresh is Agent QA's (#27) proof; a blade change would need a PM/Dalton decision.
- K9 (`.csv` appended by the downloader) cited from DEV-2904 notes; re-check once `vendor/` is in place.
- PG-015 says "convert on display"; this spec deliberately keeps UTC month labels (matches chart + `getDescription()`). Intentional, not a violation.

**Open questions:** 2 (none block Implement)
**Spec Status:** READY
**ch_touch:** no
