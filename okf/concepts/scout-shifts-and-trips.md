---
type: Concept
title: Scout Shifts & Trips
description: The Scout shift lifecycle in depth — lazily materialized from scan activity, closed by the driver or a 30-min inactivity sweep, and summarized in an end-of-shift email with per-zone breakdown and a Mapbox map. Plus the automations (scheduled sweep, queued mail job), analytics/audit writes, and the parked (feature-gated) Trips views.
resource: app/Services/Scout/BuildShiftSummary.php
tags: [scout, postgres, shifts, jobs, automation, email, feature-flag]
timestamp: 2026-07-09T13:00:00Z
---

# Scout Shifts & Trips

A **shift** is a single continuous activity window for a [Kit](kit.md)/driver in [Scout](scout.md) — it opens when a driver starts scanning, closes when the driver ends it (or the system assumes it's over after inactivity), and produces an **end-of-shift summary email**. **Trips** are a separate, finer drive breakdown that is currently **parked behind a feature flag** (see the short section at the end).

Everything below is on the PostgreSQL `platform` connection except the ClickHouse reads noted in the summary builder. The whole subsystem is gated by `config('app.experimental.clarity_scout')`.

## Shift lifecycle

```
scan activity ──▶ (status poll opens) ──▶ OPEN shift
                                            │
                    driver taps End Shift ──┤──▶ CLOSED end_type=confirmed
             30-min inactivity sweep closes ┘     CLOSED end_type=assumed
                                            │
                                            ▼
                          build summary ──▶ queue email ──▶ driver (CC admins)
```

### Lazily materialized — nothing triggers on scan ingestion

There is no observer or trigger that opens a shift when a scan arrives. A `kit_shifts` row is created **on demand** by one of three callers, all via `app/Services/Scout/EnsureOpenShift.php`:

- **`currentOpenForKit(Kit)`** — what `GET /api/scout/shift/status` polls. Returns the existing open shift, or lazily opens one **only if** the kit has a Postgres `zod.scans` row newer than the last closed shift's `ended_at` **and** within the last **180 minutes** (`INACTIVITY_GAP_MINUTES`). No recent activity → returns `null`, so the driver's "End Shift" button stays hidden until they actually scan. This is the "multiple shifts per day" behavior (a fresh burst of activity after a long gap opens a new shift).
- **`forKit(Kit)`** — force a row to exist even with zero activity (used by the End-Shift endpoint and the inactivity sweep, which both need a row to close). Runs in a `platform` transaction with `lockForUpdate`.
- Both compute the start time via **`inferStartedAt()`**: pull up to `MAX_SCAN_ROWS = 5000` scan timestamps from the last `LOOKBACK_HOURS = 24`, walk them ascending, and take the earliest scan of the current continuous run (splitting whenever the gap between consecutive scans exceeds 180 min). The result is clamped so a shift can never start before the previous shift ended.

### One open shift per kit (DB-enforced)

A partial unique index guarantees the invariant:

```sql
CREATE UNIQUE INDEX kit_shifts_one_open_per_kit ON kit_shifts (kit_id) WHERE ended_at IS NULL
```

`forKit()` can't `lockForUpdate` a row that doesn't exist yet, so two concurrent callers can both attempt the INSERT; the loser hits this index, and the `catch (QueryException)` re-reads and returns the now-existing open row. Created via raw SQL in `database/migrations/2026_05_26_000000_create_kit_shifts_table.php` (Blueprint can't express partial indexes).

### Two ways a shift closes

| Close path | Trigger | `end_type` | Analytics | Activity log |
|------------|---------|-----------|-----------|--------------|
| **Confirmed** | Driver taps End Shift → `POST /api/scout/shift/end` (throttle 10/min) | `confirmed` | `ShiftEnd` fires (unconditionally, even on an idempotent double-tap) | `scout-shift` / `end` written |
| **Assumed** | `scout:close-inactive-shifts` sweep, ≥180 min inactive | `assumed` | **none** | **none** |

`end()` is idempotent: it locks the latest shift and, if it's already closed, returns it as-is (a double-tap doesn't reopen or re-email). The summary job is dispatched and the activity row written **only when the shift is newly closed**. `end_type` is the `ShiftEndTypeEnum` (`Confirmed` / `Assumed`), which carries `#[Title]` metadata ("Confirmed" / "Assumed") used in the email.

## `kit_shifts` table (`platform.kit_shifts`, PostgreSQL `platform` connection)

`KitShift` (`app/Models/KitShift.php`, `$connection = 'platform'`). Relationships: `kit()`, `org()` (no `user()` — the driver is resolved from `KitSettings` at summary time). Helpers `isOpen()` / `isClosed()` key off `ended_at`.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `kit_id` | bigint | The [Kit](kit.md) on shift |
| `org_id` | int | Owning [Organization](organization.md) — app-layer scoped (no cross-schema FK) |
| `started_at` | timestamptz | Shift start (inferred by gap-walking scans) |
| `ended_at` | timestamptz, nullable | Shift end; `NULL` = still open |
| `end_type` | string(16), nullable, cast `ShiftEndTypeEnum` | `confirmed` or `assumed` |
| `summary_sent_at` | timestamptz, nullable | When the end-of-shift email went out (also the idempotency flag) |
| `summary_skipped_reason` | string(64), nullable | Why no email was sent — `no_recipients` or `mail_failed` |
| `created_at` / `updated_at` | timestamps | |

Indexes: composite `(org_id, started_at)` and `(kit_id, started_at)`, plus the partial unique index above.

## Automation 1 — the inactivity sweep (scheduled command)

`app/Console/Commands/CloseInactiveShiftsCommand.php` — `scout:close-inactive-shifts {--dry-run}`.

- **Schedule** (`routes/console.php`): `everyThirtyMinutes()`, `withoutOverlapping()`, `runInBackground()`, `onOneServer()`, and **double-gated** on the flag (both the scheduler's `->when(...)` and the command's own first-line check `config('app.experimental.clarity_scout')`), so it's a hard no-op until Scout ships for an environment.
- **Threshold:** `INACTIVITY_THRESHOLD_MINUTES = 180`. For each open shift it computes the latest activity as **the later of** (a) the last Postgres `zod.scans` `scanned_at` for the kit and (b) the last ClickHouse `clarity.sc_positions` GPS `timestamp_utc` (`lower(kit_mapping)` match; a ClickHouse failure degrades to scans-only rather than erroring). If that reference time is ≥180 min ago, the shift closes as `assumed` with `ended_at` = the last activity.
- **Zero-activity shift:** closed at its own `started_at` (zero duration) so it doesn't linger open forever.
- **`--dry-run`** prints what it *would* close (kit, shift id, inferred `ended_at`) and writes nothing.
- On a real close it dispatches `SendEndOfShiftSummary` per shift — the same email path as a confirmed end. It writes **no** analytics event and **no** activity-log row (unlike a driver-confirmed end).

## Automation 2 — the end-of-shift summary email (queued job)

`app/Jobs/Scout/SendEndOfShiftSummary.php` — dispatched on both close paths (only when a shift is newly closed).

- **Queue** `{low}`, `tries = 5` (retry delay is the worker default — no custom backoff).
- **Idempotency:** the whole `handle()` runs in a `platform` transaction with `lockForUpdate` on the shift row, spanning the `summary_sent_at` read → send → stamp. So a confirmed end racing the sweep can only send once — the second holder sees `summary_sent_at` set and bails. It also refuses to send if the shift is missing or still open.
- **Recipients:** driver email (resolved from `KitSettings.user_id` → `User`) as **To**, org admins as **CC**.
  - Admin CC list = `User` where `org_id` matches and role is `ADMIN` (Spatie team-scoped — the job saves/restores `PermissionsTeamId` around the query so a long-lived worker doesn't inherit the org as its default team).
  - **No driver email on file** → the summary goes **to the admins only**, and the email shows a "sent to admins only" footer.
  - **No driver and no admins** → nothing sent; `summary_skipped_reason = 'no_recipients'`.
- **Terminal failure:** after 5 tries, `failed()` stamps `summary_skipped_reason = 'mail_failed'` (unless already sent).

### What the email contains

Built by `app/Services/Scout/BuildShiftSummary.php` into a `ShiftSummaryDto`, rendered by `app/Mail/Scout/EndOfShiftSummary.php` + `resources/views/mail/scout/end-of-shift-summary.blade.php`.

- **Subject:** `Shift Summary — {Confirmed|Assumed} — {Kit name} — {YYYY-MM-DD}`.
- **Colored banner:** green "Driver tapped End Shift" for confirmed; amber "Automatically closed after 3 hours of inactivity" for assumed.
- **Header block:** Kit, Driver (only if a name is on file), Started, Ended, Duration (minutes).
- **No-activity branch:** if the shift had zero scans, hits, and miles (`hasActivity()` is false), the email shows a single "No scans or GPS activity were recorded during this shift" panel and **skips** the stats/map/zones. (The email is still sent — the `summary_skipped_reason` field is for *no-recipient*/*failure* cases, not empty shifts.)
- **Stats panel** (activity present): four numbers — **Total Miles**, **Plates Scanned**, **Hits**, **Zones Covered**.
- **Map image:** a Mapbox Static Images map (zone polygons as translucent blue fills, the driven track as a polyline) — **hotlinked, not embedded**, and **only present when `services.mapbox.token` (`MAPBOX_TOKEN`) is set**. The builder drops overlays if the URL would exceed ~7,500 chars.
- **Zones Covered table:** one row per zone the driver scanned in — Zone / Plates / Hits / Time in Zone — sorted by scans descending.

### How the summary numbers are computed (`BuildShiftSummary`)

| Metric | Source | Notes |
|--------|--------|-------|
| Total miles + track segments | ClickHouse `sc_positions` via `KitHistoricPositionsQuery` → `HistoricPathBuilder` | GPS decimated to 1 point / 3s; track split on the hour and on >500 ft gaps; Haversine miles summed per segment |
| Plates scanned | ClickHouse `scans_hot` / `scans_cold` with `FINAL` | 90-day hot/cold split, scoped `org_id` + `kit_id`, window-bounded |
| Hits | ClickHouse `hits_cold` | scoped by **`kit_org_id`**, `hit_type = 'L'` (Live only), window-bounded |
| Per-zone breakdown | **PostgreSQL/PostGIS** — `public.ST_Contains(zones.geom, scans.geo)` | one row per (scan, zone), so overlapping zones each count; **time-in-zone capped at 30 min** between consecutive scans; zone hits from the Postgres `Hit` model (`hit_type = 'L'`) |

> Note: the per-zone shift breakdown is a **PostGIS query in `BuildShiftSummary`**, distinct from `ScoutCheckInRollupQuery` (which powers the *dashboard* check-in tile). See [Scout dashboard stats & map counting](scout-dashboard-stats.md).

## Observability — analytics vs audit

Two independent trails, and they behave differently under impersonation:

- **Analytics** (`ScoutAnalyticsEvent`, → ClickHouse `clarity.analytics`): `ShiftStart` fires from `/shift/status` **only** the first time a shift is observed (`wasRecentlyCreated`); `ShiftEnd` fires from `/shift/end` on every call (action `confirm-end`). **Assumed/auto ends emit no analytics.** All analytics are **suppressed while impersonating**.
- **Audit** (`activity('scout-shift')`, Spatie `activity_log` in Postgres): written on shift start and confirmed end only, capturing `action`, `kit_id`, `shift_id`, `started_at`, `ended_at`, client IP, user agent, and the impersonator when present. The audit row **is** written during impersonation (recording who impersonated). The assumed-end sweep writes no audit row.

See [Scout analytics taxonomy](scout-analytics-taxonomy.md) for the analytics-vs-`activity_log` responsibility split.

## Trips (parked — feature-gated)

Trips are a day-segmented driving breakdown, served by `ClarityScoutTripsController` and gated behind **`FeatureEnum::CLARITY_SCOUT_TRIPS`** (`platform_repo_pro_clarity_scout_trips`) — a **config-only** flag (`app.experimental.clarity_scout_trips`, env `CLARITY_SCOUT_TRIPS_ENABLED`) with **no** per-user check. It is **kept off during rollout** while trip calculation is finalized, and is de-prioritized relative to shifts. When the flag is off the PWA hides the Trips UI and the stat bar's trip count is skipped.

Backing data is the `clarity.kit_trips` ClickHouse table (`app/Clickhouse/Models/KitTrip.php`) — a `ReplacingMergeTree` queried globally with `FINAL`, **refresh-built rather than CDC-fed** (no `_peerdb_is_deleted` column), with a **90-day TTL**. Endpoints (`trips/current`, `trips/browse`, `trips/day/{date}`, `trips/{date}/{tripIndex}`) remain in the codebase behind the flag. A trip is distinct from a shift: a shift is the activity-bounded period that produces the summary email; trips are the finer drive breakdown.

## Where it lives in code

- Model / migration: `app/Models/KitShift.php`, `database/migrations/2026_05_26_000000_create_kit_shifts_table.php`
- Open/close logic: `app/Services/Scout/EnsureOpenShift.php`
- Controller: `app/Http/Controllers/Api/ClarityScoutShiftController.php` (`status()`, `end()`, `logShiftActivity()`)
- Inactivity sweep: `app/Console/Commands/CloseInactiveShiftsCommand.php` (scheduled in `routes/console.php`)
- Summary job / builder: `app/Jobs/Scout/SendEndOfShiftSummary.php`, `app/Services/Scout/BuildShiftSummary.php`
- DTOs: `app/Dto/Scout/ShiftSummaryDto.php`, `app/Dto/Scout/ZoneSummaryDto.php`
- Enum: `app/Enums/ShiftEndTypeEnum.php`
- Mailable / view / map: `app/Mail/Scout/EndOfShiftSummary.php`, `resources/views/mail/scout/end-of-shift-summary.blade.php`, `app/Support/Scout/MapboxStaticMapBuilder.php`
- Trips (parked): `app/Http/Controllers/Api/ClarityScoutTripsController.php`, `app/Clickhouse/Models/KitTrip.php`

## See also

- [Scout](scout.md) — feature hub and gating (incl. `CLARITY_SCOUT_TRIPS`)
- [Scout dashboard stats & map counting](scout-dashboard-stats.md) — how scan/hit counts and the check-in tile are computed
- [Scout API](scout-api.md) — the `shift/*` and `trips/*` endpoints
- [Scout real-time tracking](scout-real-time-tracking.md) — `sc_positions`, `KitHistoricPositionsQuery`, `HistoricPathBuilder`
- [Scout analytics taxonomy](scout-analytics-taxonomy.md) — analytics vs `activity_log`
- [Jobs & Queues](jobs-and-queues.md) — how `SendEndOfShiftSummary` is queued
