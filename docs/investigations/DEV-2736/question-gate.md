# DEV-2736 question gate

**Gate:** CLEAN.

Dalton answered Q1, Q2, and Q3 on 2026-09-25. The OKF cite still proves only the fractional stay-constraint. These answers are the product rules for the strings and the sparkline.

## Q1. Chart chrome

**Answer:** B. `RecoveryLeader` copies `UniqueScansLeaderboard` `getOptions` and `makeDataset`. Interaction mode is `nearest`. The chart has a subtitle and an axis title. The max bar uses the Scans leader color. The field uses the Scans zinc colors.

`UniqueScansLeaderboard` constants are `LEADER_FILL` `rgba(34, 197, 94, 0.6)`, `LEADER_BORDER` `rgba(34, 197, 94, 1)`, `FIELD_FILL` `oklch(0.552 0.016 285.938)`, and `FIELD_BORDER` `oklch(0.705 0.015 286.067)`.

## Q2. Series label

**Answer:** The dataset label is `Distinct Recoveries`. The subtitle is `Top 10 kits by recoveries`. The axis title is `Distinct recoveries`.

Every new `RecoveryLeader` chrome string uses Distinct. The word Unique stays out of those strings. The heading `LPR Recovery Leaderboard` and the tooltip `Your top kit by LPR recoveries in this period. Click to see the full ranking.` stay as written.

## Q3. Sparkline series

**Answer:** A. The compact sparkline stays `BuildsDateFilterChartSeries::chartSeries`, the sum of each monthly top-10 map from `RecoveryLeaderQuery`. This plan adds no recovery org-total cache.

Preflight 25 stays closed. There is no second query.

## Settled

The card is `RecoveryLeader`, key `recovery-leader`. `RecoveryLeaderQuery::handleSql` stays fractional kit credit on `clarity.recovery_scans FINAL` and `clarity.recoveries FINAL`, with `LIMIT 10`. The kit-user table stays out. This phase does no Cloud ClickHouse read or write. There is no third leaderboard class.

## Open questions

None.
