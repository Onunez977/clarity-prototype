---
type: Concept
title: RDN Integration
description: The Recovery Database Network integration — the upstream SOAP source of repossession orders and org/agent/branch metadata, the per-org rate-limited pull jobs, and the hourly RDN-metrics push back to RDN over SQS.
resource: app/Services/RDN.php
tags: [rdn, integration, soap, jobs, sqs, hotlist, clickhouse]
timestamp: 2026-07-06T00:00:00Z
---

# RDN Integration

**RDN (Recovery Database Network)** is the external system that repossession orders originate from. Clarity integrates with it in two directions: it **pulls** order/agent/branch metadata from RDN's SOAP API to enrich local data, and it **pushes** hourly scan/hit counts back to RDN so RDN can show per-org activity. This page covers what the platform's RDN code does; the order → [Hotlist](hotlist.md) row → [Hit](hit.md) matching itself is driven by PostgreSQL triggers in the upstream `laravel-zod-migrations` package (see [Repositories](repositories.md)), not by the RDN service here.

## The RDN client (`app/Services/RDN.php`)

A SOAP client against `config('services.rdn.api_url')` (`RDN_API_URL` + `RDN_API_VERSION`, default `https://api.recoverydatabase.net/2.0`), authenticated **per org** with an `apiKey` SOAP header. Each org carries its own credentials on `zod.public.orgs`: `rdn_id` (the RDN org identifier) and `rdn_api_key` (encrypted). `Org::hasRdnAccount()` = `! empty($rdn_id)`.

Operations: `getClientList()`, `getAgents($type)` / `getAgentInfo($id)`, `getBranchZips($branchId)`, `updateCaseCollateral($caseId, …)`, and a generic paginated `call($method, $args, $page)` (100 items/page) surfaced as a `LazyCollection`. Response shapes are the `app/Dto/Rdn/` DTOs (`ClientDto`, `AgentDto`, `AgentInfoDto` — see [DTOs & Enums](dto-and-enums.md)).

Per-org RDN state lives in `org_meta.properties` (`zod.public.org_meta`): `rdn_rate_limit` (calls/min, detected from API response headers), `rdn_client_type` / `rdn_client_belongs_to`, `rdn_agents`, and the metrics gate `platform_repo_pro_rdn_metrics_push`.

## Pull jobs (`app/Jobs/Rdn/`)

The four **org-metadata pulls** extend the **`RdnJob`** base, which is rate-limited **per org** and runs on the low-priority queue:

- **Rate limiting:** the `RateLimited('rdn-api')` middleware; the limiter is defined in `app/Providers/AppServiceProvider.php` as `Limit::perMinute($org->meta->properties['rdn_rate_limit'])` keyed `rdn-api:{orgId}` (or `Limit::none()` when the org has no per-org limit) — so one org's pulls can't starve another's or trip RDN's own limits.
- **Queue / retries:** `{low}` (Horizon), `$tries = 3`, `$backoff = 10`, `$timeout = 600`. See [Jobs & Queues](jobs-and-queues.md).

| Job (`extends RdnJob`) | Pulls | Lands in |
|-----|-------|----------|
| `GetOrgRdnClients` | client list + type | `org_meta.properties` (`rdn_client_type`, `rdn_client_belongs_to`) |
| `GetOrgBranches` | branch list | `zod.public.branches` (`Branch`, `updateOrCreate`) |
| `GetOrgBranchZips` | per-branch ZIPs | `branches.zip_code` |
| `GetOrgAgents` | company/outside adjusters | `org_meta.properties['rdn_agents']` |

The per-order **case-info** jobs `GetOrgHotlistCases` → `GetOrgHotlistCaseInfo` (the latter fetches `getRdnCaseInfo` by `remote_order_id` and writes `zod.public.hotlist_json.branch_id`) implement `ShouldQueue` **directly**, not via `RdnJob` — though `GetOrgHotlistCaseInfo` reuses the same `RateLimited('rdn-api')` middleware. `GenerateRdnCache` (command) refreshes RDN-derived caches on a schedule (`dailyAt('5:00')`, production + `platform` app only).

## RDN metrics push (`app/Services/Rdn/RdnMetricsPublisher.php`)

Clarity reports per-org, per-hour counts back to RDN via an **AWS SQS** queue (`config('services.rdn_metrics.*')` — `queue_url`, `aws_profile`, `aws_region`). The metric kinds are `RdnMetricType` (`SCANS`, `LIVE_HITS`, `HISTORICAL_HITS`, `STAGING_LIVE`, `STAGING_HISTORICAL`; the values are RDN column aliases like `scans_by_org_by_hour` — see [DTOs & Enums](dto-and-enums.md)).

```
DispatchRdnMetricsHourly (scheduled)  →  Bus::batch(PublishRdnMetric × metric × org)  →  RdnMetricsPublisher → SQS
                                          counts read from ClickHouse (clarity)
```

- **Schedule:** `DispatchRdnMetricsHourly` runs `hourlyAt(5)`, guarded to **production + `platform` app + a filled `services.rdn_metrics.queue_url`**, `onOneServer()`. It fans out one `PublishRdnMetric` job (`{low}`, `tries = 1` — at-most-once) per metric type per eligible org.
- **Counts come from ClickHouse:** `RdnHitHourCountQuery` (hit types `L`/`H`/`S`/`Z` from `clarity.hits_hot FINAL`) and `RdnScanHourCountQuery` (`uniqExactMerge` on `clarity.scan_report_hourly`). See [ClickHouse Integration](clickhouse-integration.md).
- **Eligibility:** the per-org `platform_repo_pro_rdn_metrics_push` meta flag.
- **Idempotency:** each SQS envelope carries an `idempotency_key` = SHA1 of `rdn_org_identifier + metric_type + range`, stable across resends.
- **Manual/backfill:** the `app:rdn-metrics-publish` command (`app/Console/Commands/PublishRdnMetrics.php`) publishes metrics for one org over a chosen range (`--start`/`--end`/`--metric`/`--count`/`--force`), resolving hourly counts from ClickHouse.

## Where RDN data lands (quick map)

| Table | DB / schema | Written by |
|-------|-------------|------------|
| `hotlist`, `hotlist_json` | PostgreSQL `zod.public` | orders upstream; `hotlist_json.branch_id` by `GetOrgHotlistCaseInfo` |
| `branches` | PostgreSQL `zod.public` | `GetOrgBranches` / `GetOrgBranchZips` |
| `org_meta.properties` (`rdn_*`) | PostgreSQL `zod.public` | the pull jobs |
| [`aggregate_rdn_hotlist_count_by_hour`](../models/aggregate_rdn_hotlist_count_by_hour.md) | PostgreSQL `platform` (default connection) | hourly order-count aggregate |

## Where it lives in code

- Client: `app/Services/RDN.php`; DTOs `app/Dto/Rdn/*.php`
- Pull jobs + base: `app/Jobs/Rdn/` (`RdnJob`, `GetOrg*`); rate limiter in `app/Providers/AppServiceProvider.php`
- Metrics: `app/Services/Rdn/RdnMetricsPublisher.php`, `app/Jobs/Rdn/{DispatchRdnMetricsHourly,PublishRdnMetric}.php`, `app/Console/Commands/PublishRdnMetrics.php`, `app/Enums/RdnMetricType.php`
- Count queries: `app/Clickhouse/Queries/{RdnHitHourCountQuery,RdnScanHourCountQuery}.php`
- Schedule: `routes/console.php` (`DispatchRdnMetricsHourly` hourly, `GenerateRdnCache` daily)
- Per-org config: `app/Models/Org.php` (`rdn_id`, `rdn_api_key`, `hasRdnAccount()`), `app/Models/OrgMeta.php`

## See also

- [Violent Debtor](rdn-violent-debtor.md) — RDN webhook of violent-debtor records, stored by plaintext VIN in `zod.public` and flagged on hotlist, hit, and scan. Separate from the SOAP pulls and the metrics push on this page. Table and endpoint are not in this repo yet.
- [RDN Metrics — Provider Integration Spec](rdn-provider-integration.md) — provider-facing spec of the SQS metrics push: the message envelope, every published metric type, and `rdn_org_identifier` / `metric_range` semantics
- [Hotlist](hotlist.md) — the order records RDN is the upstream source of; trigger-driven hit creation
- [Jobs & Queues](jobs-and-queues.md) — `{low}` queue, `RateLimited`, `Bus::batch()`
- [DTOs & Enums](dto-and-enums.md) — `RdnMetricType`, the `Rdn\*Dto` shapes
- [Organization](organization.md) — `org_meta`, per-org RDN credentials
- [ClickHouse Integration](clickhouse-integration.md) — where the pushed metric counts are read from
- [Repositories](repositories.md) — `laravel-zod-migrations` (order→hotlist→hit triggers) and Black Adam
