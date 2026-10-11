2026-08-25 12:23 CDT · intake · intake complete: route=bug app=clarity base=main
2026-08-25 12:38 CDT · prep · wip: flip-risk measured read-only on prod CH (33.3% of election groups are flip-capable; mechanism 3 restated)
2026-08-25 12:51 CDT · prep · wip: 4 prep candidates + size bound + float-precision analysis; reconcile running
2026-08-25 13:05 CDT · prepped · prep complete: gate=BLOCKED, 3 open questions, confidence 80

## Phase 2 — Question Gate interview (2026-08-25, mode=normal)

Q1. Mechanism-3 grain: one row or two?
A1. **TWO rows, one per `is_staged` side.** `is_staged` stays in the uniqueness key; `insertOrIgnore`
    is retained. AC1 is read as per-side; AC4 defines the grain. Preserves DEV-2414's both-sides
    behaviour and its parity test. Accepted cost: one visit yields two check-ins in the
    mechanism-3 case (judged small — 0 of 4,519,947 book address_ids are dual-listed today).
    — human:Dalton McCleery 2026-08-25

Q2. Attribution staleness: refresh `address_id`/`hotlist_id`, or freeze?
A2. **FREEZE at first sighting.** No UPDATE/upsert path. Matches okf/concepts/check-in.md's
    "immutable event — first sighting wins". Accepted, documented consequence: after a
    mechanism-3 flip both surviving rows can carry the same `address_id`, so DEV-1756's derived
    `COUNT(*) WHERE address_id = ?` would report two check-ins for one address at one place in one
    hour. Recorded as a known boundary — that consumer does not exist yet, and `->upsert(` has no
    precedent anywhere in `app/`.
    — human:Dalton McCleery 2026-08-25

Q3. Does AC3's "after" figure gate Done?
A3. **No — post-merge follow-up.** Ship with the "before" figure plus the measured exposure sizing.
    Sequence stays: before-run -> hand-apply DDL -> deploy -> after-run; steps 2 and 3 must not be
    inverted.
    — human:Dalton McCleery 2026-08-25

Gate recomputed: BLOCKED -> CLEAN. One blast-radius obligation carried forward: the exact migration
DDL must be shown for an explicit approval line at the end of Phase 3, before Phase 4 touches it.
2026-08-25 14:29 CDT · gated · gate CLEAN: 3 questions answered (two-rows-per-side, freeze attribution, AC3 after-figure post-merge)
2026-08-25 14:36 CDT · planned · plan complete: 5 nodes, 6 files; t2 is the blast-radius migration node awaiting approval

## Phase 3 — blast-radius approval (t2, migration) — 2026-08-25

Q4. Approve the migration DDL on `platform.scout_check_ins`?
A4. **Approved, but the finished file is to be shown before any other node runs and before the
    test suite executes against it.** — human:Dalton McCleery 2026-08-25

Q5. `down()` behaviour, given restoring the old unique index can fail on data the new design
    legitimately creates (a mechanism-3 pair: two rows, same `address_id`, same hour, opposite
    sides)?
A5. **Drop the restore from `down()`.** `down()` removes the new index and both columns and always
    succeeds; it does NOT recreate `unique(kit_id, address_id, hour_bucket)`. Accepted consequence,
    to be documented in the migration itself: a rollback leaves the table with no uniqueness guard,
    so pre-change code rolled back alongside it would accumulate true duplicates via
    `insertOrIgnore`. `down()` is therefore deliberately NOT a strict inverse of `up()`.
    — human:Dalton McCleery 2026-08-25

Index name `scout_check_ins_geo_hour_side_unique` is forced, not stylistic: Laravel's implicit name
for the five-column unique index is 69 characters and PostgreSQL truncates identifiers at 63.
2026-08-25 14:43 CDT · implementing · t2: migration written (addr_lng/addr_lat, drop old unique, geo-grain unique); parked for file review before any other node runs
2026-08-26 08:07 CDT · provisioned · provision: isolated PG branch+testing DBs (migration verified applied), local Docker CH repointed by hand after up.sh verification failure
2026-08-26 08:28 CDT · implementing · t1/t3/t4/t5 implemented (40 tests green on the change's own files); reverted boost-doc regeneration drift; investigating cross-test pollution of HitDirectHotspotQueryTest
2026-08-26 08:41 CDT · implemented · implement complete: 40 tests green on the change's own files; 3 remaining Scout failures attributed pre-existing

## Review round 1 — findings and dispositions (lanes 92 / 88 / 91, MIN 88 < 90 → gate failed)

FIXED (scoring):
- **claude3 H1 (HIGH) — `org_id` missing from the new unique key.** Real, and fixed by adding
  `org_id`. **But the lane's stated mechanism is wrong and is NOT repeated as fact:** it claimed two
  Scout drivers in different orgs could both hold `kit_settings.kit_id = K` simultaneously.
  `platform.kit_settings` has `kit_id` as its PRIMARY KEY (and `user_id` UNIQUE), so a kit has at
  most one assigned driver at a time — that scenario is impossible. `Kit::getSharedKits()` shares a
  kit with an org for *visibility*, not by creating a second `kit_settings` assignment. The real
  reachable path is **sequential**: the kit is reassigned from org A's driver to org B's driver
  within the same hour, so two cycles write for the same (kit, geo, hour) under different
  `org_id`s and the second is silently dropped. Lower probability than the lane implied, still a
  genuine silent-data-loss regression, and `org_id` is free to add (it is already a column and is
  constant within one org's iteration, so single-org dedup is unchanged). Pinned by
  `a_kit_reassigned_between_orgs_within_an_hour_records_one_check_in_per_org`, which was PROVEN to fail when
  `org_id` is removed from the key.
- **claude2 D2 / claude3 M1 — `migrate:rollback && migrate` failed** because `up()` dropped a
  constraint `down()` never restores. Fixed by guarding the drop with `Schema::hasIndex()`. Proven:
  rollback→migrate now succeeds twice in a row on both branch DBs.
- **claude2 D3 — the hand-apply runbook's first statement would have ERRORED in production.** It
  used `DROP INDEX` on what is actually a UNIQUE CONSTRAINT. Proven locally: `DROP INDEX` fails with
  "cannot drop index ... because constraint ... requires it". `plan.md`'s runbook now uses
  `ALTER TABLE ... DROP CONSTRAINT IF EXISTS`, and the corrected script was dry-run end-to-end
  against a replica of the pre-change table (legacy row survives the index build; re-runnable).
- **claude2 D1 — the deploy boundary writes a duplicate for the straddling hour**, inflating AC3's
  "after" figure. Documented in `ac3-measurement-procedure.md` with a free mitigation: take the
  "after" measurement no earlier than the first full hour after deploy.
- **claude1 M2 — the provenance test could not detect the swap it exists to catch** (delta 0.0001
  ≈ 11 m vs a ~5 m separation). Separation widened to ~40 m and deltas tightened to 1e-6; proven to
  fail under a deliberate coordinate-pair swap.
- **claude1 L2 — `mechanism_1`/`mechanism_2` asserted only the absence of change**, so a run-2
  no-op passed. Positive controls added that assert at the query layer that the election genuinely
  flipped before asserting the persisted row stayed frozen; proven to fail if the control expects
  the old winner.
- **claude1 L1 — `@property float` on nullable columns** → `?float`.
- **claude1 L3 — the precision-guard comment overstated what the test proves.** Comment corrected
  rather than building a test at the ~1e-12-degree boundary, which would test floating-point noise
  rather than a reachable scenario.

NOT FIXED, by explicit prior decision (recorded, not overlooked):
- **claude1 L4 / claude2 — `down()` leaves no uniqueness guard.** This is Dalton's Q5 answer
  (2026-08-25), documented in the migration itself. Not a defect.
- **claude1 M1 — storage identity moved from an immutable id onto a mutable derived geocode.** This
  is the ticket's entire premise (AC1) and Dalton's Q1/Q2 answers. The residuals it implies are
  documented in the class docblocks.
2026-08-26 09:10 CDT · implemented · review round 1 fixes: org_id in the unique key (HIGH), guarded drop for re-runnability, corrected hand-apply runbook DDL, deploy-boundary caveat, 3 test-integrity fixes

## Review round 2 — gate PASSED (90 / 92 / 94, MIN 90) — then a voluntary correction pass

The gate passed, so the bounded fix loop was over. But round 2 surfaced defects in MY OWN round-1
work, two of them things this run had already discovered and recorded CORRECTLY in the journal while
leaving the WRONG version in the shipped code. `CLAUDE.md` makes docblocks load-bearing and cites
DEV-2342 as precedent for the exact harm of shipping a docblock that teaches a mechanism that never
existed, so these were fixed rather than deferred:

- **claude3 D1 / claude1 D3 — both shipped docblocks justified `org_id` with an IMPOSSIBLE
  mechanism.** I wrote "one shared kit can be processed under two orgs in one cycle" *after* having
  myself verified that `kit_settings.kit_id` is a PRIMARY KEY, which forbids it — the journal was
  right and the code was wrong. Both docblocks now state the reachable SEQUENTIAL path (the kit is
  reassigned to another org's driver inside the same hour) and explicitly say it is NOT two orgs in
  one cycle, so nobody re-derives the impossibility and concludes the column is dead weight.
- **claude1 D2 — `ScoutCheckInMatchQuery`'s docblock still stated the key without `org_id`.** Fixed.
- **claude3 D2 — `down()` was unguarded where `up()` was guarded.** A partially-applied `up()`
  (columns added, index build interrupted, no `migrations` row written) left a table neither
  direction could leave: `down()` errored on the missing constraint, `up()` errored on the existing
  columns. `down()` now guards both the constraint and the columns. Round-trip re-verified twice on
  both branch DBs.
- **claude2 D1 — my deploy-boundary mitigation was arithmetically WRONG.** I had prescribed "start
  the after-run at 12:00"; at 12:00 the 15-minute lookback starts at 11:45 and those fixes still
  carry `hour_bucket = 11:00`, so the duplicate still lands. Corrected to "at least one full
  `lookback_minutes` PAST the hour boundary" (12:15 with the default), and tied to the config knob
  rather than a hardcoded time.
- **claude2 D2 / claude1 D1 — a FOURTH duplication path, previously undocumented.** The elected GEO
  is itself mutable across rebuilds, so a re-geocoded address writes a second row for one visit —
  the same shape as the three mechanisms this ticket closes. Measured a bound on the driver
  (read-only): 24.92% of 198,300,757 `zod.public_address` rows have `_peerdb_version > 0`, ~166k/day
  synced. Recorded as a known residual in the matcher docblock and the AC3 doc, with the reason it
  is not closed (no stable place id exists in the book) and why the trade is still a net improvement
  (order churn is far more frequent than re-geocoding).
- **claude3 D4 — the storage-layer provenance delta.** Its 0.0001 delta was in fact already tighter
  than the 0.0002-0.0003 separation, so a swap DID fail — the finding was a robustness/consistency
  point, not a hole. Tightened to 1e-6 anyway to match its query-layer twin, and commented.
- **claude2 D3 — `plan.md`'s `up()` snippet did not compile** (`$schema` undefined) and its `down()`
  prose contradicted the shipped migration. Both fixed.

NOT actioned, with reasons:
- **claude1 D4 — the guarded drop turns a name mismatch into a silent no-op.** Real in principle,
  but the old constraint's name is the Laravel-derived one created by the same shipped migration
  (verified 52 chars, under Postgres's 63-char truncation limit), so a mismatch is not reachable.
  Guarding is still the right call because the un-guarded form provably broke
  `migrate:rollback && migrate`.
- **claude1 M1 / claude2 (round 1) — storage identity moved onto a mutable derived value.** That is
  the ticket's premise plus Dalton's Q1/Q2 answers; now documented as the fourth residual above.
2026-08-26 09:25 CDT · reviewed · round-2 correction pass: docblock org_id rationale (was impossible-as-written), guarded down(), corrected deploy-boundary arithmetic, documented the geo-mutation residual with a measured bound

## Review round 3 — gate PASSED (94 / 94 / 94, MIN 94) — documentation-consistency pass

Round 3 found no code defects. It found that round 2's corrections had been applied to the code but
NOT to every place that repeats them, which is its own kind of defect since `plan.md` is the only
artifact carrying hand-apply SQL for a production DDL. Fixed:

- **claude1 D2 / claude2 M1 / claude1 D3 — `plan.md` was actively dangerous.** Its `down()` snippet
  and prose still RESTORED the old unique index, and still framed it as an open approval question
  Dalton had already answered. An operator following it during a rollback would have run
  `ADD CONSTRAINT ... UNIQUE (kit_id, address_id, hour_bucket)`, which errors on the exact end state
  `mechanism_3_...` pins as correct, leaving the table half-migrated. Snippet now matches the shipped
  migration and the prose says explicitly: do not add a restore to any rollback runbook.
- **claude1 D2 — `plan.md` still asserted the impossible `org_id` mechanism** (`Kit::getSharedKits`,
  "two orgs in one cycle") that round 2 corrected in the code. Now corrected in all three sites.
- **claude1 D7 — the hand-apply runbook omitted the `platform.migrations` INSERT.** Without it the
  next `artisan migrate` re-runs this migration and fails on the already-existing columns. Added, as
  a guarded idempotent INSERT.
- **claude1 D1 — the DDL→code-deploy window has NO uniqueness guard at all.** Between the DDL landing
  and the new code deploying, the old code writes NULL geos, which the new index treats as distinct,
  so nothing dedupes. The doc previously warned only about the opposite ordering. Documented, with
  the mitigation that the schedule is currently off so the window can simply be kept clear.
- **claude1 D5 / claude2 L1 — the matcher docblock said "Two gaps remain" and then listed three**,
  with the third spliced mid-sentence into the second bullet. Rewritten as three consistent bullets.
- **claude2 L2 — the recorder docblock omitted the third residual.** Added, pointing at the matcher
  docblock for the measured bound.
- **claude1 D4 — the queued okf corrections carried the pre-`org_id` key** and claimed the
  post-merge pass was "mechanical". Corrected, and the claim softened — the file predates `org_id`,
  so each entry needs checking against the merged code.
- **claude1 D6 — `mechanism_3` lacked the positive control** its two siblings were given in round 2.
  Added: it now asserts at the query layer that the Direct side elects 8000 and the Staging side has
  moved to 8001 before asserting the persisted rows, so a run-2 no-op cannot pass.

Not actioned: **claude2 M2** ("a data-level mitigation for the deploy boundary is one statement
away") — that would mean backfilling or deleting straddling rows, which is the backfill decision
Dalton already declined (Q3, 2026-08-25). Recorded, not silently dropped.
2026-08-26 09:38 CDT · reviewed · round-3 doc-consistency pass: plan.md runbook corrected (dangerous rollback SQL + migrations row + DDL window), docblock list fixed, mechanism_3 positive control
2026-08-26 09:54 CDT · verified · verify: AC1 proven live at 20k book rows (flip absorbed, positive control confirms); resource envelope PASS (3.03 MB marginal, 0.67s); AC3 deferred per decision
2026-08-26 10:04 CDT · done · run complete: PR #370 ready, Build Record 38590, In Code Review, QA env torn down (seed rows only; container kept)

## Post-handoff — CI trigger

`Pint & Tests` (`.github/workflows/lint_check_and_test.yml`) triggers on
`pull_request: [opened, reopened, synchronize, ready_for_review]` against `main`/`deploy`. The
undraft fired `ready_for_review` while HEAD was `ff04be89`, whose message carried `[skip ci]`, so
GitHub skipped it — and no run appeared for the following push either. Verified the workflow does
fire for sibling PRs (DEV-2414 on the same branch pattern ran green twice), so this is the
trigger-skip trap, not a workflow misconfiguration. Pushing this artifact commit as a clean-message
`synchronize` to get a real CI run on the final head.
2026-08-26 10:07 CDT · done · push a clean-message trigger so Pint & Tests runs on the final head

## Post-handoff — operator feedback (2026-08-26)

Dalton, on the finished PR:

1. **"Do we need to commit all the prep-candidate json files or the measurement ones?"** Removed
   the four `prep-candidate-{1,A,B,C}.json` files (~140 KB, more than every other artifact combined
   apart from `prep-context.json`). They are pure intermediate scaffolding, fully superseded by the
   reconciled `prep-context.json`, whose `reconcile` block already records what each candidate
   contributed and where they disagreed. KEPT the measurement/analysis docs
   (`flip-risk-measurement.md`, `scout-check-ins-size-bound.md`, `geo-key-float-precision.md`,
   `ac3-measurement-procedure.md`) — the Build Record cites their derivations and `plan.md` +
   `ac3-measurement-procedure.md` are the production hand-apply runbook. Also kept the small
   `precedents.json` (2.4 KB), which records why specific prior tickets were judged not-applicable.

2. **"The migration is using private const and that's a code smell … inline those and accept the
   duplicates."** Done — both class constants removed and the two index names inlined at their three
   use sites. Also tidied `up()` to resolve `Schema::connection('platform')` once instead of twice
   (an artifact of the earlier guarded-drop edit). Pure refactor, no behaviour change; re-verified
   rather than assumed: `migrate:rollback && migrate` twice on the dev DB and once on the testing DB,
   the resulting constraint re-checked (`scout_check_ins_geo_hour_side_unique`), 41 tests green, Pint
   and okf-lint clean. `plan.md`'s snippet was synced so the runbook still matches what ships.
2026-08-26 10:20 CDT · done · operator feedback: drop prep-candidate scaffolding (~140KB), inline the migration's class constants, resolve the connection once

## Review round 4 — gate FAILED (94 / 94 / 88, MIN 88) and it was right to

Running round 4 was not optional: `rework-gate` refused `done` because the migration changed after
the round-3 pass, even though the change was a pure constant-inlining refactor. It caught a real
defect that the refactor's own reasoning had introduced asymmetrically.

**FIXED — claude3 H1 (HIGH).** `up()` guarded the index drop but left the **column adds
unguarded**. If a previous `up()` added the columns and then failed on the index build, Laravel
writes no `migrations` row, so the next `migrate` re-runs the file and `->double('addr_lng')` errors
with "column already exists" — wedged, and `rollback` cannot help because there is no row to target.
Reachable precisely because production hand-applies this DDL as three non-transactional statements.
Both adds are now `hasColumn`-guarded. **Proven, not asserted:** a database was put in exactly that
state (columns present, `migrations` row deleted, new constraint dropped) and `artisan migrate`
succeeded where it previously would have errored.

**FIXED — claude3 M2.** The runbook had no pre-flight duplicate check before `ADD CONSTRAINT`. If
the recorder runs between the `ADD COLUMN` and the `ADD CONSTRAINT` (not transactional across each
other), duplicates can exist and the constraint fails — leaving the table with the OLD constraint
already dropped and the new one absent, i.e. no uniqueness guard at all. Added the `GROUP BY ...
HAVING count(*) > 1` pre-flight with an explicit "must return zero rows".

**FIXED — claude3 M3.** `a_kit_shared_across_two_orgs_...` implied simultaneous sharing, which the
schema forbids. Renamed to `a_kit_reassigned_between_orgs_within_an_hour_...` and its comment now
states the sequential mechanism, matching the corrected docblocks. Stale references in `journal.md`
and `plan.md` updated too.

**FIXED — claude3 H2 (maintenance trap).** `hasIndex()`/`hasColumn()` execute immediately while
`$table` commands flush at closure end. Correct as written, but a comment now states the contract so
a maintainer keeps guards before the commands they guard.

**REFUTED — claude3 M1 (the "null island").** The lane argued a zero-geo book row would be cast to
`0.0` and collapse every such address into one `(kit, 0.0, 0.0, hour, side)` row. The cast reasoning
is right but the rows never reach it: `ScoutCheckInMatchQuery`'s `addresses` CTE excludes zero-geo
book rows (`tupleElement(book.geo, 1|2) != 0`, lines 188-189) and `position_window` excludes
zero-geo fixes (167-168), both pre-existing and untouched here. Rather than leave that as an
argument in a journal, it is now an asserted invariant:
`test_a_zero_geo_book_row_is_never_elected_so_no_null_island_row_can_be_written`.

Also fixed a slip of my own caught on re-read: the guarded `addr_lat` add had been written
`->after('lng')` instead of `->after('addr_lng')`.
2026-08-26 10:36 CDT · reviewed · round-4 fixes: guard up()'s column adds (HIGH, proven against the wedged state), runbook pre-flight duplicate check, test renamed to the real mechanism, zero-geo invariant pinned

## Review round 6 — gate PASSED (94 / 92 / 92, MIN 92); two fixes taken anyway

(Round 5 was launched but killed mid-flight by a session teardown — no lane output, so it was not
reused; the relaunch went out as round 6.)

The gate passed, so the loop was over. Two findings were fixed regardless, because both are the
SAME defect class as round 4's HIGH and leaving the asymmetry half-finished would be worse than
either state:

- **claude1 D1 + claude2 D3 (both lanes, independently) — the NEW unique-index build was the one
  remaining unguarded statement in `up()`**, after round 4 guarded the column adds and the old-index
  drop. A hand-apply that ran `ADD CONSTRAINT` without writing the `migrations` row would error
  "constraint already exists" on the next `migrate`. Now `hasIndex`-guarded, so `up()` is fully
  idempotent. **Proven:** a database was left with the constraint present, both columns present and
  the `migrations` row deleted — `artisan migrate` succeeded where it previously would have errored —
  and a clean rollback/migrate round-trip still works twice on dev plus once on testing.
- **claude1 D2 — my own null-island test could not fail if the filter it names were removed.** It
  seeded a zero-geo book row and a zero-geo fix, so `position_window`'s zero filter carried the
  assertion on its own and the book-side filter was never exercised. The fix is now a NON-zero fix
  ~55 m from (0, 0) — inside the 160.934 m radius — so only the `addresses` CTE filter can exclude
  the match. **Proven both ways:** removing `tupleElement(book.geo, 1|2) != 0` makes the test FAIL,
  restoring it makes it PASS, and the query file was verified byte-identical afterwards (md5).

REFUTED, with reasoning rather than a shrug:
- **claude2 D1 (HIGH) — "a single kit cycle can emit two matches mapping to the same new key."**
  It cannot, by construction: the election's `PARTITION BY addr_lng, addr_lat, hour_bucket,
  out_is_staged` with `rn = 1` is exactly the new key minus `kit_id`/`org_id`, both of which are
  constant within one kit iteration. The only theoretical path is two ClickHouse-distinct coordinates
  truncating to one Postgres value, which `geo-key-float-precision.md` puts at ~1e-12 degrees
  (~0.1 µm) against a 1e-5 degree grain. And even then `ON CONFLICT DO NOTHING` yields one row per
  place, which is the intended outcome.
- **claude2 D2 — "a match whose row exists with a different `org_id` is silently dropped."** A
  different `org_id` is a different key, so it inserts. That is precisely what
  `a_kit_reassigned_between_orgs_within_an_hour_records_one_check_in_per_org` asserts.

Carried as residual (documented, not actioned): the `precision` ini is not pinned in code
(`geo-key-float-precision.md` explains why determinism, not the exact value, is what the key needs);
`down()`'s guard checks only the new constraint name; and `mechanism_3`'s positive control converts
its window bounds to UTC where its two siblings do not — a cosmetic inconsistency, both forms
resolve to the same instant under the frozen clock.
2026-08-26 12:31 CDT · reviewed · round-6 follow-ups: guard the new index build so up() is fully idempotent (proven), make the null-island test actually pin its filter (proven both ways)

## Review round 8 — gate PASSED (92 / 94 / 90, MIN 90) — closing

Round 7, like round 5, was launched but its background shell was terminated before any lane wrote
output (`[killed]` in the log). Neither was reused. Round 8 was launched with `nohup` + `disown` so
a shell teardown could not take it, and completed normally.

No code changed after this pass, so `HEAD == reviewed_sha` and `rework-gate` reports that the merged
code IS the reviewed code — which is the property the earlier rounds kept failing, because each
migration fix invalidated the previous pass. `up()` now has no unguarded statement left (column adds,
old-index drop, and new-index build are all guarded), which was the recurring theme across rounds 4
and 6.

Round history: RN=1 fail(88) → RN=2 pass(90) → RN=3 pass(94) → RN=4 fail(88) → RN=5 killed →
RN=6 pass(92) → RN=7 killed → RN=8 pass(90). Every recorded round ran real `harness-claude-p` lanes;
no in-session substitution at any point.

## CI

`Pint & Tests` **passed on `a0092464`** — the final head — 3/3 shards green on PHP 8.4, plus the
Kamal PR-370 preview deploy. Worth recording why this looked broken for a while: three earlier runs
(`0817d7b9`, `82fad8d5`, `da495680`) sat *queued for over two hours with zero jobs allocated*. That
was not environment approval (`pending_deployments` was empty) and not a failure — GitHub simply had
not allocated runners. Once capacity freed, the run against the final head went green in ~6 minutes.
The lesson for next time: a queued-with-zero-jobs run on this repo is a runner stall, so check
`headSha` on the run that eventually completes rather than assuming an early queued run covers the
final code.
2026-08-26 12:50 CDT · done · run complete: RN=8 gate pass (92/94/90), CI green on the final head, both done gates pass
2026-08-26 13:26 CDT · done · capture the address-geo design rationale as a repo artifact (mirrored in the PR body and ticket comment)
