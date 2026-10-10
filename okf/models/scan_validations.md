---
type: DataModel
title: scan_validations (ScanValidation)
description: Human/inferred corrections and QA verdicts for a scan's OCR and plate status, in PostgreSQL zod database, public schema.
resource: app/Models/ScanValidation.php
tags: [model, postgres, public, lpr, scan]
timestamp: 2026-07-06T00:00:00Z
---

# scan_validations

A **scan_validations** record captures a validation verdict for a single [Scan](../concepts/scan.md) — the corrected OCR text and US state, or flags that the image is not a plate / not readable. Validations are produced by human reviewers (the backyard and standalone validation apps) or inferred automatically, and are keyed 1:1 to a scan by `scan_id` (the primary key is `scan_id`, non-incrementing). They feed OCR quality assurance and model-training feedback loops.

The record lives in the **PostgreSQL** `zod` database, `public` schema, on the `zod` connection — alongside the core LPR `scans` table.

## Location

- **Database:** `zod` (PostgreSQL)
- **Schema:** `public`
- **Connection:** `zod`
- **Table:** `scan_validations`
- **Model class:** `App\Models\ScanValidation` — `app/Models/ScanValidation.php`
- **Primary key:** `scan_id` (non-incrementing, int)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `scan_id` | bigint | no | Primary key and FK → `public.scans.id`. The [Scan](../concepts/scan.md) being validated. |
| `correct_ocr` | character varying(12) | yes | Corrected plate OCR text. |
| `correct_us_state` | character varying(2) | yes | Corrected US state two-letter code. |
| `not_a_plate` | boolean | yes | True when the image does not show a license plate. |
| `not_readable` | boolean | yes | True when the plate is present but unreadable. |
| `validated_by_user_id` | bigint | yes | FK → `public.users.id`. User who submitted the validation. |
| `validated_at` | timestamp without time zone | yes | When the validation was completed. Cast to `datetime`. |
| `created_at` | timestamp without time zone | no | Row creation timestamp (default `now()`). |
| `updated_at` | timestamp without time zone | yes | Row update timestamp. |
| `parent_scan_id` | bigint | yes | FK → `public.scans.id`. Related parent scan, when the validation was derived from another scan. |
| `source` | integer | yes | App where the validation occurred: `1` = backyard, `2` = validation standalone. |
| `batch` | character varying(12) | yes | Origination notes / batch tag; not required. |
| `in_progress_by_user_id` | bigint | yes | FK → `public.users.id`. User who currently has the validation in progress (soft lock). |
| `inferred` | boolean | yes | True when the validation was generated automatically rather than by a human. |
| `origin` | character(26) | yes | Origin identifier (likely a ULID-style source reference). |

## Casts & enums

- `validated_at` → `datetime`

## Relationships

None declared on the model. `scan_id` and `parent_scan_id` reference [Scan](../concepts/scan.md); `validated_by_user_id` and `in_progress_by_user_id` reference `User`.

## Data mapping (PostgreSQL ↔ ClickHouse)

Mapping not established — no known dedicated ClickHouse replica. As a `public`-schema table it is eligible for PeerDB CDC to `zod.public_scan_validations`, but no denormalized `clarity.*` counterpart is documented here. Treat any CH usage as unconfirmed.

## Notable scopes & behavior

- Non-incrementing primary key: `$incrementing = false`, `$primaryKey = 'scan_id'` — one validation row per scan.
- Uses `HasTimestamps`; `created_at` is DB-defaulted to `now()`.
- No soft deletes, no global scopes, no query scopes.

## See also

- [Scan](../concepts/scan.md)
- [scans_metadata](scans_metadata.md)
- [Data Architecture](../concepts/data-architecture.md)
