# DEV-2233 — Ultra-Plan

**Route:** bug · **Base:** main · **Blast radius:** none (single Blade file, view-layer JS) · **Sizing:** 1 file — well under the split threshold.

## Decision (Dalton, 2026-07-24)
- **Scope:** Missed Opportunity only — gate the new rowKey behind `this.missedOpportunity`. Scans/Hits unchanged.
- **Refresh behavior:** remove the row **only on the Invalid disposition**, matching the backend default filter (which excludes only Invalid). Valid/Reviewed stay put. Backend filter + its regression test are **unchanged**.

## Subtask t1 (simple)
**File:** `resources/views/components/scans/disposition-popup.blade.php`

In `changeDisposition(value)`, the non-reason branch calls `window.updateScanDisposition(this.modelId, value)` with no rowKey. Change it to:

```js
window.updateScanDisposition(
    this.modelId,
    value,
    'other',
    (this.missedOpportunity && value === '{{ \App\Enums\DispositionEnum::INVALID->value }}')
        ? 'scan-' + (this.rowLoop?.index ?? this.modelId)
        : null
);
```

Why this exact shape:
- **4th-arg placement** (`'other'` explicit in the 3rd slot): `updateScanDisposition(scan_id, disposition, reason='other', rowKey=null)` — putting rowKey in the 3rd slot would 422 the server-validated `reason` and break persistence.
- **`missedOpportunity && value === 'Invalid'` gate**: satisfies the narrow scope + Invalid-only decision. `INVALID_SCAN` ('Invalid Scan') is a different case handled by the reason-button branch (line ~215), untouched.
- **`this.rowLoop?.index ?? this.modelId` null-guard**: mirrors server anchor `$id = $rowLoop?->index ?? $model_id`; avoids a TypeError in cluster-group views that pass no rowLoop.

**app.js:** unchanged (reuses the existing `if (rowKey)` removal). See excluded_sites note on the grouped-DOM 4-hop — QA verifies it; `closest('tr')` is the deferred fallback only if QA shows the hop count fails on the grouped MO table.

## Tests
- No JS runner. Attempt a PHPUnit markup assertion (rendered popup carries the new rowKey expression) as a regression guard; real verification is browser QA of AC-1 against the **grouped** Missed Opportunities table.
- Regression: AC-3 (Scans Invalid-Scan removal) preserved by construction.
