# DEV-2442 — ticket status

**Phase:** implemented · draft PR #374
**Tip:** `08e43c22e978f4df954d82238b357efd87e475cf` on `claude/DEV-2442` (pushed)
**Product tip:** `d5fefbbb7944bf25b343b05394b178fe6eb52daf` — Act-on rn=14 (Aggregate applyJoin parity + unfiltered handle tests)
**Next:** **Review** (Sam re-review) vs origin/main · escalate 3 lanes · gate 90

**Act-on rn=14 addressed:**
1. #1 blocker — `HotlistStatusAggregate::scopeForBranch` / `scopeForClients` accept ignored `bool $applyJoin = true` (signature parity with transitions) so unfiltered batch/sparkline named `applyJoin: false` does not throw — done
2. #2 should-fix — unfiltered `ClientRankedMetricsBatch` / `BatchMonthlySparklineData` `handle()` tests with `branch_id` null/0 seed aggregate and assert per-client non-zero counts — done

**Soft #18 re-cite (Path A):** 143 passed / 526 assertions · filter=`GetOrgHotlistCaseInfo|GetOrgHotlistCases|HotlistStatusTransitionBranchFilter|HasStatDrillDown|ClientRankedMetricsBatch|ClientTableBranchFilter|BatchMonthlySparkline` · ports :18094/:15446/:18137 LEFT UP · pint dirty=0 · driver=clickhouse
**Soft #27:** PENDING Agent QA — not invent-fail

**Do not:** undraft / QA Ready / Board / force-push / tear down Path A
