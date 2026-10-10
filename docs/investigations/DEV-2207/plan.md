# DEV-2207 — Plan

**Route:** feature · **Base:** main · **Sizing:** 1 subtask, 1 view + its test (well under split threshold)

## Subtask t1 — Update subscription page tier feature lists (+ render tests)

**File:** `resources/views/filament/pages/subscription.blade.php` (+ `tests/Filament/Pages/SubscriptionTest.php`)

### Pro Lite
1. **AC1** — add `'Advanced Plate State recognition'` to the active `Everything in Basic plan, plus:` array (verbatim from existing string; item 1 was a move, not a rename).
2. **AC2** — add `'Advanced Kit User Path'` to the active array (renamed from `'Advanced Gridding Analytics'`).
3. **AC3** — delete the Pro Lite `Coming Soon` block entirely (`<hr>` + heading `<p>` + `<ul>`), now empty.

### Clarity Pro
4. **AC4** — add `'RDN Reporting Integration'` to the active `Everything in Basic plan, Pro Lite, plus:` array (renamed from `'RDN custom reports'`), and remove `'RDN custom reports'` from the Coming Soon array (the other four Coming-Soon items stay).
5. **AC5** — add `'Optimized Gridding (Scout)'` to the active array (deployed but previously unlisted).

### Test (TDD)
Add a render assertion in `SubscriptionTest.php`: as a Pro admin, `Livewire::test(Subscription::class)` — `assertSee` the four new/renamed active labels and `assertDontSee('Advanced Gridding Analytics')` + `assertDontSee('RDN custom reports')`. Both tier cards render unconditionally, so one render covers all ACs.

**Blast radius:** none · **Complexity:** simple · **CH/data:** none
