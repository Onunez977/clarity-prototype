# Core critical paths (T3 regression battery)

A versioned, curated battery of the platform's **must-always-work** journeys — the existing-feature
safety net release regression runs **every release, regardless of the diff**. This is the
general-platform analogue of the plugin's Scout-only `scout-critical-paths.md`.

> **DRAFT — Dalton to review/trim.** Derived from the App + Client Filament panels
> (`app/Filament/**`). Add/remove journeys so this reflects exactly what you consider
> release-critical. Each item is a smoke check ("does the core path still work"), not a deep test.

## How release-prep uses this

- **Agent QA (`standard`):** a *thin smoke* subset — one item per Smoke-lane surface — woven into the
  matching `### F` lane (per [`regression-strategy.md`](regression-strategy.md)). Never its own lane.
- **Human QA (`standard`):** the **full** battery below, rendered as `### Regression sweep` (`rg-<n>`),
  each framed "should be UNCHANGED."
- **`thorough`:** the full battery runs in *both* passes. **`light`:** Smoke lane only.
- **Pro-gated rows** require the QA persona's org to be on the right tier — the seeder must enable it
  (see `regression-strategy.md` → "Reachability"). Mark them so they're not skipped on an empty screen.
- **Seeded data is mandatory, not optional.** "Loads" only means something against representative
  data — an empty list/dashboard/path can't tell a working empty-state from a broken surface. The
  release `QaSeeder` (Step 5) MUST populate every surface this battery touches: kits + kit users,
  scans, hits, hotlists (with varied statuses incl. terminal/expired), recoveries, and the matching
  **ClickHouse rows** (real local Docker CH) behind the Dashboard stats, Kit User Path, and Client
  Analytics. Each battery item below should reference the seeded record(s) it expects; if the seeder
  couldn't create them, the item is flagged ⚠ "data missing" rather than passing on an empty screen.

## Battery

### Smoke (always — the foundation)
- Dev-login lands and the **Dashboard** renders (stat widgets, hotlist counts; no 5xx/console errors).
- **Hits** list (`HitsResource`) loads, paginates, row click opens detail.
- **Hotlists** index (`HotlistResource`) loads; open one.
- **Scans** list (`ScansResource`) loads; plate search returns results.
- Topbar **"What's New"** (`RecentChanges`) opens and shows the changelog (the entry this release adds).
- **Client portal landing** — log in as a client persona; the **Client panel** (`Providers/Filament/Client.php`)
  loads its landing (search scans / client surfaces visible, no 5xx/console errors).
- Log out / log back in.

### Hits & dispositions
- Filter/sort the Hits table; apply a disposition; bulk disposition via row checkboxes.

### Hotlists
<!-- Hotlists are ingested (CDC), not user-CRUD'd — no create/edit/delete/export regression. -->
- Hotlists index filters/sorts; statuses (incl. terminal/expired) display correctly.
- Hotlist counts reflect correctly on the Dashboard (cross-surface rollup).

### Scans
- Scan clustering / grouping; "copy to clipboard" on a cluster; plate image popup opens.

### Kit users & paths
<!-- The heatmap/density LAYERS (Repo Heatmap / Staging Hotlists / Hit Density) are Scout-domain —
     covered by the plugin's scout-critical-paths.md, folded in when the release touches Scout.
     This core battery only confirms the surfaces load. -->
- **Kit Users** (`KitUsersResource`) list + edit.
- **Kit User Path** (`KitUserPath`) — page + map load; date-range selector works. (Scout layers: see
  the Scout battery.)
- **Kit User Recoveries** (`KitUserRecoveries`) report loads + sorts.

### Missed opportunities & reports
- **Missed Opportunities** (`MissedOpportunityResource`) *(Pro)* — list loads, and the **default
  view** behaves: Invalid dispositions excluded by default, the Historical vs. standard type
  distinction shows, and the Repo Date column is present.
- **Scan Report** (`ScanReportResource`) — loads (core).

### Alerts & notifications
- **Alert Settings** (`AlertSettings`) *(Pro)* — page loads; save a setting; existing channels persist.
- A scan/hit **triggers a notification** and the **Notification Logs** reflect it. (Under
  `QUEUE_CONNECTION=sync` the job fires inline during the request, so this is agent-checkable — no
  worker needed. Real redis/Horizon path is a deploy concern, not exercised here.)

### Client analytics  *(Pro: Client Analytics)*
- **Client Analytics** (`ClientAnalytics` / `IndividualClientAnalytics`) renders; stat drill-down opens.
- **Client panel** search scans (`Client/ClientSearchScansResource`) loads for a client persona.

### Admin & account
- **Users** (`UsersResource`) create/edit/list; **Roles** (`Roles`) loads.
- **Invites** (`InviteResource`) *(Pro)* list/create.
- **Profile** (`Profile`) loads/saves; **Subscription** (`Subscription`) *(Pro mgmt)* loads.
- **Activity** (`ActivityResource`) audit log loads.

## Maintenance

This file is versioned and evolves with the app: when a release **adds** a core surface, add a row;
when one is **removed/renamed**, update it. release-prep reads it each run — keep it the single source
of "what must always work."
