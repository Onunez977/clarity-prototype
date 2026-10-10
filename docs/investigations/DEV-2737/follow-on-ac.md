# DEV-2737 follow-on AC

**Status:** STANDING until V1 = 533.

**Source:** `plan.json` `decision.follow_on_ac`. Evidence only. This AC names no code change. `decision.follow_on_filed` stays false: this file is the standing text, not a separate Jira issue.

> Org 14, September 2026 UTC. LprRepossessionsQuery::monthlySeries bucket 2026-09-01 returns db_repos 533 (deduped 630) on production ClickHouse, read-only, 2026-09-28. The question-gate UI impression is ~509 on This Month under admin impersonation. If V1's live headline is 533, withdraw this AC and close DEV-2737 with proof notes — the ~509 was not the live headline. If V1's headline is not 533, this AC stands as the evidence record for a separate fix ticket. This AC names no code change.

Withdraw this text only when V1's live headline integer is 533. Any other headline leaves this AC standing for a separate fix ticket.
