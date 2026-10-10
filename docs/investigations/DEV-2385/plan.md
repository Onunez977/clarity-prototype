# DEV-2385 plan

Single node (complex only because it spans 4 files). No blast-radius tags. No ticket split.

## Policy (Dalton 2026-08-18)

- Bounded last **8 hours** of the shift for all summary reads (not skip, not full 17-day window).
- `$tries = 2`.
- Skip path N/A.

## t1

Clamp `BuildShiftSummary` to `max(started_at, ended_at - 8h)` for CH + PG reads. Keep DTO shift timestamps as the real shift. Replace `whereIn('scan_id')` with `whereIntegerInRaw`. Job `$tries = 2`.

Window math: a 3h shift is unchanged; a 17-day shift only reads the last 8h, which bounds the zone CTE and the scan-id list.

`whereIntegerInRaw` stays as defense in depth if a busy 8h still exceeds 65,535 scans (~2.3/s).
