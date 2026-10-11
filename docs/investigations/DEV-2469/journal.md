2026-09-11 14:42 CDT · intake · intake: route=feature app=clarity; corrected spec recorded (Oscar ratio redefinition)
2026-09-11 14:47 CDT · implementing · card implemented: ratio = unique_scans / db_repos, both UTC-bucketed
2026-09-11 14:51 CDT · implemented · tests: 13 new pass, 69 across touched surface; expansion-test failures measured as pre-existing on main
2026-09-11 15:04 CDT · implemented · record verification status; trigger CI on real ClickHouse
2026-09-11 15:24 CDT · implemented · review RN2 fixes: share LprRepossessionsQuery cache keys, per-month UTC numerator, drop the two-query closure
2026-09-11 15:59 CDT · implemented · RN3: remove the window-key payload collision, honour the owner's TTL floor, add a cross-card test proven to fail without the fix
2026-09-12 11:07 CDT · implemented · QA against real ClickHouse: card renders 5.0 from real queries, all 4 arms, no cross-card corruption, cold=2/warm=0 round trips
2026-09-12 11:07 CDT · implemented · persist QA report + reproducible CH seed into the tracked investigation dir (.context is gitignored)
2026-09-12 11:19 CDT · implemented · browser QA: all 4 filter arms verified live (6.0/40.0/5.0/0.0), no cross-card corruption, screenshots captured
2026-09-12 11:27 CDT · implemented · revert boost:install docs drift swept in by 25bf951f9 (okf-lint was exiting 1; PG-021 reversed); record RN4 92/76/72
2026-09-12 11:41 CDT · implemented · RN4 fixes: re-share the window key as an int (RN3's un-share was based on a false premise), honour the shared ClickHouse backoff marker
2026-09-14 08:40 CDT · implemented · QA closeout: summary posted to Jira, seeded rows removed by hand (shared CH container), .env restored
2026-09-14 08:51 CDT · handoff · handoff: PR undrafted + retitled, Jira -> In Code Review (pipeline-mcp)
