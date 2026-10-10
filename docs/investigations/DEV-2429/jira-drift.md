# Jira drift journal — DEV-2429 sprint field

**Journal only** — no Jira write from this measure run.

| Source | Sprint |
|--------|--------|
| Live Jira `customfield_10020` (re-GET 2026-09-24 ~11:30 CDT) | **Sprint 42** (`state=future`, boardId=1, id=909; start 2026-09-28 → end 2026-10-12, UTC) |
| `/workspace/first-glance/sprint-41.md` | Sprint **41** (row DEV-2429) |
| `/workspace/first-glance/DEV-2429-judgment.md` | Sprint **41 (active)** |

**Drift:** live Jira sprint field is **Sprint 42 (future)** while glance/judgment say **Sprint 41**. Measure proceeds read-only regardless; do not mutate sprint from the measure runner.
