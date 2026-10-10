---
type: Concept
title: RDN Metrics — Provider Integration Spec
description: Provider-facing specification of the RDN Statistics metrics Clarity publishes to RDN over SQS — the shared message envelope, every published metric type with its payload keys/types/units/examples, and the rdn_org_identifier / metric_range semantics.
resource: app/Services/Rdn/RdnMetricsPublisher.php
tags: [rdn, integration, sqs, metrics, provider, spec]
timestamp: 2026-07-21T00:00:00Z
---

# RDN Metrics — Provider Integration Spec

This page specifies the **RDN Statistics metrics** that Clarity (InsightLPR) publishes to RDN
(Recovery Database Network) over an **AWS SQS** queue. It is written for RDN's integration
engineers: it documents exactly what Clarity emits today so RDN can consume and store it.

It is the **provider-facing** companion to the internal [RDN Integration](rdn.md) page (which
covers the SOAP pull side and Clarity-internal mechanics). Everything here is derived from the
publishing code; example values are **illustrative only** (no real customer data).

> **Scope:** Clarity currently publishes **five hour-grain, per-org metrics**. Each is sent as a
> separate SQS message. There is no other metric family, granularity, or dimension in production
> today.

## Delivery model

| Aspect | Behavior |
|--------|----------|
| **Cadence** | Once per hour, shortly after the top of the hour. Each run covers the **previous completed UTC hour**. |
| **Message granularity** | **One SQS message per (org, metric type, hour)** — up to **5 messages per participating org per hour** (one for each metric type). |
| **Which orgs emit** | Only orgs that RDN has provisioned (they carry a non-empty `rdn_org_identifier`) and that have the RDN-metrics push enabled. Orgs without an identifier never produce any message. |
| **Window per message** | Exactly one hour: `[metric_range_start, metric_range_end)` — see [metric_range semantics](#metric_range_start--metric_range_end). |
| **Delivery guarantee** | **At-most-once.** Clarity does not deliberately re-send, and does not retry a message after a worker failure, to avoid inflating RDN's counts on a non-FIFO queue. A message may therefore occasionally be *missing*, but a given (org, metric, hour) is never *intentionally* sent twice. Use `idempotency_key` to de-duplicate defensively. |
| **Partial sets are possible** | The four hit counts are computed together *before* any message is sent — if that computation fails, Clarity drops the whole org for that hour rather than emitting zeroed or partial hit data. The five messages are then delivered **independently** (the scan count is computed separately, at send time), so — combined with the at-most-once guarantee above — any individual message may be missing. **Receiving one message for an org-hour does not imply all five arrived.** |
| **Transport** | AWS SQS (standard, non-FIFO). |

## Message envelope

Every message is a JSON object with the following keys. All keys are **always present and
non-null** on every message.

| Key | Type | Units | Nullable | Example | Semantics |
|-----|------|-------|----------|---------|-----------|
| `metric_type` | string (enum) | — | no | `scans_by_org_by_hour` | Identifies which metric this message carries. One of the five values in [Metric types](#metric-types). |
| `metric_range_start` | string — ISO 8601, UTC (`Z`) | timestamp | no | `2026-05-01T00:00:00Z` | **Inclusive** start of the counted window. Always the start of a completed UTC hour. |
| `metric_range_end` | string — ISO 8601, UTC (`Z`) | timestamp | no | `2026-05-01T01:00:00Z` | **Exclusive** end of the counted window. Always exactly one hour after `metric_range_start`. |
| `rdn_org_identifier` | string (≤ 64 chars) | — | no | `AZ5209991234` | The RDN-assigned identifier for the org (see [rdn_org_identifier](#rdn_org_identifier)). |
| `idempotency_key` | string — SHA-1 hex (40 chars) | — | no | `1f9c2a7e4b8d5f0a3c6e9b2d5f8a1c4e7b0d3f6a` | Stable de-duplication key for this exact (org, metric, window). See [idempotency_key](#idempotency_key). |
| `data` | array of objects (exactly 1 element) | — | no | `[{"count": 12871}]` | The metric payload. For every metric type today this is a **single-element array** with one integer field, `count`. |

### `data[].count`

| Field | Type | Units | Nullable | Example |
|-------|------|-------|----------|---------|
| `count` | integer (≥ 0) | events (scans or hits, per metric) | no | `12871` |

`count` is the whole-number total for the metric over the window. It is `0` when there was no
activity (a zero-activity hour still produces a message for a participating org).

## Metric types

Clarity publishes exactly these five `metric_type` values. All are **per-org** and **hourly**.

| `metric_type` | Meaning | What `count` measures |
|---------------|---------|-----------------------|
| `scans_by_org_by_hour` | Scans | Distinct scans recorded for the org during the hour (a de-duplicated count). |
| `lives_by_org_by_hour` | Live Hits | Count of **Live** hits (a scan matching one of the org's own active hotlist records) during the hour. |
| `hh_by_org_by_hour` | Historical Hits | Count of **Historical** hits during the hour. |
| `staging_lives_by_org_by_hour` | Staging Live Hits | Count of **Staging Live** hits (live hits against a staged hotlist) for the org during the hour. |
| `aa_by_org_by_hour` | Staging Historical Hits | Count of **Staging Historical** hits (historical hits against a staged hotlist) for the org during the hour. |

> The four hit metrics (`lives`, `hh`, `staging_lives`, `aa`) are **independent counts**, each in
> its own message — they are *not* a single breakdown message keyed by hit type.

### Example payloads

Each block is a complete message body. Values are illustrative.

**`scans_by_org_by_hour`**
```json
{
  "metric_type": "scans_by_org_by_hour",
  "metric_range_start": "2026-05-01T00:00:00Z",
  "metric_range_end": "2026-05-01T01:00:00Z",
  "rdn_org_identifier": "AZ5209991234",
  "idempotency_key": "1f9c2a7e4b8d5f0a3c6e9b2d5f8a1c4e7b0d3f6a",
  "data": [{ "count": 12871 }]
}
```

**`lives_by_org_by_hour`**
```json
{
  "metric_type": "lives_by_org_by_hour",
  "metric_range_start": "2026-05-01T00:00:00Z",
  "metric_range_end": "2026-05-01T01:00:00Z",
  "rdn_org_identifier": "AZ5209991234",
  "idempotency_key": "9c0e77a1b3d2f4e6098a5c7b1d3f5a7c9e1b3d5f",
  "data": [{ "count": 47 }]
}
```

**`hh_by_org_by_hour`**
```json
{
  "metric_type": "hh_by_org_by_hour",
  "metric_range_start": "2026-05-01T00:00:00Z",
  "metric_range_end": "2026-05-01T01:00:00Z",
  "rdn_org_identifier": "AZ5209991234",
  "idempotency_key": "7ae0d1c2b4a6f8e0d2c4b6a8f0e2d4c6b8a0f2e4",
  "data": [{ "count": 8 }]
}
```

**`staging_lives_by_org_by_hour`**
```json
{
  "metric_type": "staging_lives_by_org_by_hour",
  "metric_range_start": "2026-05-01T00:00:00Z",
  "metric_range_end": "2026-05-01T01:00:00Z",
  "rdn_org_identifier": "AZ5209991234",
  "idempotency_key": "55f8ab0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a",
  "data": [{ "count": 3 }]
}
```

**`aa_by_org_by_hour`**
```json
{
  "metric_type": "aa_by_org_by_hour",
  "metric_range_start": "2026-05-01T00:00:00Z",
  "metric_range_end": "2026-05-01T01:00:00Z",
  "rdn_org_identifier": "AZ5209991234",
  "idempotency_key": "3bd2140a5c6e7f8091a2b3c4d5e6f7081920a3b4",
  "data": [{ "count": 1 }]
}
```

## Field reference

### `rdn_org_identifier`

An **opaque identifier that RDN assigns** to the org (typically at onboarding). Clarity stores it
verbatim and echoes it back on every message.

- **Type:** string, up to **64 characters**.
- **Format:** none enforced by Clarity. The `AZ5209991234`-style value above is only an example —
  do not rely on a fixed pattern. RDN is the authority on the identifier's format and meaning.
- **Uniqueness:** one identifier per org.
- **Absence:** an org with no identifier is never included in any push, so `rdn_org_identifier` is
  never empty on a delivered message.

### `metric_range_start` / `metric_range_end`

Together these define the **half-open time window** the `count` covers:

- Both are **ISO 8601 timestamps in UTC**, with a trailing `Z` (e.g. `2026-05-01T00:00:00Z`).
- **`metric_range_start` is inclusive; `metric_range_end` is exclusive** — i.e. the window is
  `start ≤ t < end`. An event exactly at `metric_range_end` belongs to the *next* window.
- The window is always **one completed clock hour**: `metric_range_start` is the top of an hour and
  `metric_range_end` is exactly one hour later.
- Clarity only reports **completed** hours — it never sends counts for the in-progress hour.

### `idempotency_key`

A stable de-duplication key for the message, so RDN can guard against processing the same
(org, metric, window) twice.

- **Type:** string — **SHA-1 hex digest** (40 lowercase hex characters).
- **Derivation:** `SHA1( rdn_org_identifier + "|" + metric_type + "|" + metric_range_start + "|" + metric_range_end )`
  — the four values joined by a `|` separator, using the exact `metric_range_*` strings from the
  message.
- **Stability:** identical across any resend of the same (org, metric, window), and unique across
  different ones. Clarity's own delivery is at-most-once and it does not deliberately resend, but
  RDN can safely treat this key as the primary de-dup identity.

## Implementation reference (internal)

For Clarity engineers — the source of every fact above:

- Metric types: `app/Enums/RdnMetricType.php`
- Envelope construction: `app/Services/Rdn/RdnMetricsPublisher.php`
- Hourly dispatch, windowing, eligibility: `app/Jobs/Rdn/DispatchRdnMetricsHourly.php`
- Per-message job (at-most-once): `app/Jobs/Rdn/PublishRdnMetric.php`
- Count sources: `app/Clickhouse/Queries/RdnHitHourCountQuery.php` (hits, from `clarity.hits_hot`),
  `app/Clickhouse/Queries/RdnScanHourCountQuery.php` (scans, from `clarity.scan_report_hourly`)
- Schedule + SQS config: `routes/console.php`, `config/services.php` (`services.rdn_metrics.*`)

See [RDN Integration](rdn.md) for the full internal picture.
