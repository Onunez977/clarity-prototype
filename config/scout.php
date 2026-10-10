<?php

// config for Clarity Scout check-in recording. These are the only defaults —
// call sites read them without a fallback so a value can never disagree with
// itself (DEV-2342 shipped one knob with three different defaults).
return [
    'check_ins' => [
        // GPS lookback per cycle. On a 5-minute cadence this gives each fix
        // ~3 overlapping chances, so a transiently missed cycle self-heals.
        'lookback_minutes' => (int) env('SCOUT_CHECK_INS_LOOKBACK_MINUTES', 15),

        // Hard ceiling of concurrent recorder processes. Must be >= 1;
        // zero/negative is a misconfig (warning + skip), not "unlimited".
        'max_concurrent_slots' => (int) env('SCOUT_CHECK_INS_MAX_CONCURRENT_SLOTS', 2),

        // Crash-recovery TTL for a held slot, refreshed by heartbeat while
        // the process runs so the ceiling is independent of cycle duration
        // (AC4). Must be <= lookback_minutes so a crashed holder auto-expires
        // inside the overlapping lookback (DEV-2348). 10 < 15 today.
        'slot_lease_minutes' => (int) env('SCOUT_CHECK_INS_SLOT_LEASE_MINUTES', 10),

        // A cycle at or over this logs a warning and report()s to the error
        // tracker. Non-positive disables it.
        //
        // Set above the 2026-08-12 incident plateau (36-148 min, avg 104 —
        // PHP address round-trip, DEV-2342). That is not post-rewrite cycle
        // time. The cadence-derived target would be 240_000 ms; using it
        // against those incident hours would have been ~102 exceptions/day
        // and no signal. RATCHET THIS DOWN once a live book-matcher cycle
        // is measured (W4).
        'overrun_ms' => (int) env('SCOUT_CHECK_INS_OVERRUN_MS', 9_000_000),
    ],
];
