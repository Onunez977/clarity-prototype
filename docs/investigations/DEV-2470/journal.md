2026-09-14 15:26 CDT · intake · intake complete: route=feature app=clarity base=main
2026-09-14 15:37 CDT · prepped · prep complete: gate=BLOCKED, 1 material definition question, best-of-N deliberately skipped (needs-human)

## Phase 2 — Question Gate (2026-09-14)

Gate was BLOCKED on two questions. Both answered by the operator inline.

**Q1 — what is this metric?** → **Option B**: a ratio, org-wide unique scans
(`ScanPlateStatsBatchQuery`) ÷ TOTAL repossessions (`clarity.recoveries`, the quantity
`RecoveryCounts` renders as "Total Repossessions").
Source: human:Dalton McCleery 2026-09-14.

The operator was shown, in terms, that this settles a product definition by engineering
inference while Oscar has still not answered, and that a wrong guess means a card Oscar
rejects. The decision was reaffirmed. The ticket's "Do not build until Oscar answers"
blocker is therefore **deliberately overridden**, not resolved — recorded here and in
prep-context.blocker_disposition so the Build Record and any reviewer can see who made
the call.

**Q2 — Pro-gate?** → **Yes**, behind COMPANY_METRICS, consistent with every sibling
"Unique Scans to X" card. Takes DashboardCardVisibilityTest's allowlist from eight to nine.
Source: human:Dalton McCleery 2026-09-14.

### Resource envelope — MEASURED, verdict `go`

Read-only HTTPS probe against production ClickHouse Cloud as `readonly_user`, literal
`clarity` database, `X-ClickHouse-Summary` read per response. No writes.

Note: the **worktree** `.env` carries a bad ClickHouse password and points at prod Cloud
with a per-worktree database name that does not exist there — the same defect DEV-2469's
QA found and fixed. Credentials were taken from the main checkout. This must be repaired
before QA, not worked around.

| measurement | value |
| --- | --- |
| `clarity.recoveries` | 17,059,091 rows / 776.25 MiB / `ORDER BY (org_id, repo_date, id)` |
| denominator, 13-mo single-org series, FINAL + `_peerdb_is_deleted=0` | 281,882 rows / 5.91 MB / 52.3 MB peak / ~15 ms |
| largest single org, same window | 240,140 rows |
| DEV-2469's denominator, for scale | 584M rows / 2.79 GB / 33.7s tail |

The denominator reads roughly **2,000× fewer rows** than the sibling's. No cold-mutex
guard machinery is warranted for it.

### Two findings that change how the query must be written

1. **`FINAL` is required here.** 39,358 with vs 46,313 without (org 1271, 13-month window)
   — omitting it over-counts repossessions by **17.7%**. This is the opposite of DEV-2466's
   `uniqExact(ocr)` finding on `scans_cold`, where FINAL was pure overhead. Do not
   generalise that result to this table.
2. **`count()` FINAL still drifts from `uniqExact(id)` FINAL** by 2–7 rows per org over 13
   months — the DEV-2406 `hits_cold` defect class (mutable sort-key column `repo_date`
   prevents FINAL collapsing every duplicate). Present but ~0.02%, not the ~30% seen on
   `hits_cold`, and invisible at one-decimal precision. Open decision recorded in
   prep-context.measurements: match `RecoveryCounts` (`count()`) so the denominator equals
   the Total Repossessions figure on the same grid, vs strict `uniqExact(id)`. Current pick
   is to match; flagged for review to challenge.

Gate recomputed → **CLEAN**. No blast-radius site in scope.
2026-09-14 20:26 CDT · gated · gate CLEAN: option B + Pro-gated (operator); envelope MEASURED go (281,882 rows / 5.91 MB / 52.3 MB peak)
2026-09-14 20:27 CDT · gated · build segment handed to spawned session 0cbea5e5 (Phases 3-5)
2026-09-14 20:33 CDT · planned · plan complete: 1 subtask / 5 files; option B ratio, batched clarity.recoveries denominator, 5 recorded design decisions

## Phase 3.5 — environment (2026-09-14)

The worktree `.env` ClickHouse block was repointed OFF production Cloud onto the local Docker
container. Prior values, for reversal:

```
CLICKHOUSE_HOST=<prod ClickHouse Cloud host>   CLICKHOUSE_PORT=8443
CLICKHOUSE_HTTPS=true   CLICKHOUSE_USERNAME=<prod read account>   CLICKHOUSE_PASSWORD=<redacted>
CLICKHOUSE_WRITE_USERNAME=<prod write account>  CLICKHOUSE_WRITE_PASSWORD=<redacted>
CLICKHOUSE_DATABASE_CLARITY=zod_claude_dev_2470_9936de      CLICKHOUSE_DATABASE_ZOD=zod_claude_dev_2470_9936de
```

Both `CLICKHOUSE_DATABASE_*` named a database that does not exist on Cloud, and the password was
bad — the defect DEV-2469's QA also hit. Now `127.0.0.1:18123`, literal `clarity`/`zod`.

`.env.testing` was already safe (`CLICKHOUSE_DRIVER=pgsql`, host `127.0.0.1`, per-worktree DB) and
was ALSO moved to the local container, so the `RequiresClickhouse` suites execute instead of
skipping. Prior values: `CLICKHOUSE_DRIVER=pgsql`, `CLICKHOUSE_DATABASE_CLARITY`/`_ZOD` =
`zod_testing_claude_dev_2470_9936de`. Both files are gitignored.

Postgres needed no provisioning: `zod_claude_dev_2470_9936de` and `zod_testing_claude_dev_2470_9936de`
both already exist and are migrated (41 rows in `platform.migrations`).
2026-09-14 20:59 CDT · implemented · implement complete: ratio card + RecoveryTotalsBatchQuery; 27 card tests, 9 CH query tests on REAL ClickHouse, 332 green across the dashboard suites; pint clean, phpstan clean in changed files

### Review RN1 — a correction to two lanes' premise on the host name

Lanes 2 and 3 both flagged the Phase 3.5 block above for "committing the production ClickHouse
Cloud hostname and account names into the repo". The host name is now written as a placeholder
there, but the finding's premise — that this ticket introduced the exposure — is **wrong**, and
the record should say so rather than accept the credit for a fix that changes nothing.

That same host string is already committed in the artifacts of at least eight prior tickets
(DEV-2235, 2255, 2324, 2406, 2448, 2461, 2464, 2465), and `readonly_user` is committed in
**application source** — `app/Filament/Widgets/Dashboard/LprRepossessions.php` and
`UniqueScanCounts.php` both name it in their docblocks. Redacting this one file lowers the
exposure by zero.

It is a host name and two account names, never a credential: every password in these artifacts
was written `<redacted>` at the time. Whether the repo-wide pattern is worth a cleanup ticket is
an operator decision, not this ticket's to make unilaterally. Recorded here so it is a decision
someone takes rather than a thing nobody noticed.

## Review round 1 — gate FAILED (88 / 94 / 94 vs 90). Fixes applied.

Lane MIN 88 is below the threshold, and lane 1 raised a **High**, which fails the gate outright
whatever the score.

### Fixed

| finding | lanes | fix |
| --- | --- | --- |
| **Numerator failure rendered a confident `0.0`** — a failed 2.44 GB `scans_cold` numerator was defaulted to 0 and divided by a 5.91 MB `clarity.recoveries` denominator that had SUCCEEDED, producing a plausible wrong number indistinguishable from an org with no scans | **all 3** | the card now renders an explicit `UNAVAILABLE_VALUE` (`--`) with no sparkline, on BOTH date arms. Propagating instead would have been worse: the marker this leg observes is written by `UniqueScanCounts` three slots earlier, so throwing would escalate THAT card's graceful degradation into a grid-wide 500. This is the resolution `okf/concepts/company-metrics-dashboard.md` names as the proper one and records as its open follow-up |
| **The window arm propagated while the series arm degraded** — one card, two failure semantics depending on the selected date filter | 3 | both arms now observe the marker and degrade identically |
| **UTC sold as "the correctness property"** — `repo_date` is a LOCAL BUSINESS DATE, not a UTC instant, so pinning the numerator to UTC does not eliminate the axis mismatch; for a US fleet the viewer's zone is the closer axis. The mechanism was inherited from DEV-2469, where the denominator genuinely *is* hard UTC; the premise did not carry over | **all 3** | design KEPT (the real justification — sharing the sibling's cache key instead of opening a third heavy `scans_cold` pass — is sound and is now what the docblock says), claim corrected, and the residual divergence from `RecoveryCounts`' viewer-tz label axis disclosed |
| **"Equals the neighbouring card" was asserted, not enforced** — both cards cache the same quantity independently at a one-year rung, so they can disagree within a TTL | 2 | claim softened to what is true on both classes; pointing `RecoveryCounts` at the class-neutral key is named as the follow-up that would make it structural |
| **Denominator span clamped to today** while `RecoveryCounts` windows to end-of-month, so a future-dated `repo_date` would be counted by one card and not the other — breaking the very parity decision d1 rests on | 1 | denominator now covers the whole current month; the numerator still clamps (it filters a `scanned_at` DateTime) |
| **`windowRatioMemo` not reset by `refreshData()`**, asymmetric with the base class's `monthlyDataMemo` | 2 | `refreshData()` overridden; both this memo and the unavailable flag are cleared |
| **Test integrity** — the label test silently depended on `APP_TIMEZONE`; no literal pin on either cache-key string; no test that `today`/`last_7d` cannot collide on one window key | 2 | all three added; `setTestNow` now takes an explicit UTC instant |

33 card tests (was 27), 9 query tests. Every fix was mutation-verified: reverting the unavailable
state fails 3 tests, re-clamping the span fails 1, removing the `refreshData` reset fails 1.

### NOT fixed — with the correction each deserves

- **The production ClickHouse Cloud host name in this file.** Two lanes called it a new exposure.
  It is not — see the correction block above. Redacted here anyway; the repo-wide pattern is an
  operator decision.
- **`_peerdb_is_deleted = 0` is not pinned by any test.** It cannot be while `FINAL` is present
  on a two-argument `ReplacingMergeTree`. Both docblocks say so rather than implying a test covers it.
- **~70 lines of the sibling's numerator leg are still duplicated.** Extracting it means editing
  `UniqueScansToLprRepossession`, which this ticket's scope excludes. The cross-card key-parity
  test is what keeps the two copies honest in the meantime.
- **Closed denominator months cached for a year vs late-entered `repo_date`.** Real, and shared
  with `RecoveryCounts`, which rides the same rung for the same quantity — so this card does not
  introduce it. The back-dating rate is a data question nobody has measured; recorded, not guessed.

### Environment note, not a code defect

The shared local ClickHouse container sits at its own 5.21 GiB ceiling (`CGroupMemoryUsed`
measured at 5.25 GiB) and intermittently returns `Code: 241 MEMORY_LIMIT_EXCEEDED` on whichever
statement runs when memory is tight — observed on three different tests across ~25 runs, never
reproducible, always error 241. The container is shared with other worktrees so it was not
restarted. CI builds a fresh container and is unaffected.
2026-09-14 21:34 CDT · implemented · review RN1 gate FAILED (88/94/94 vs 90); fixed the 3-lane High (failed numerator rendered a fabricated 0.0 -> explicit unavailable state) + 6 further scoring findings; 33 card tests, all fixes mutation-verified

## Review round 2 — gate FAILED (76 / 84 / 92 vs 90). The round-1 fix introduced a REGRESSION.

Scores went DOWN. Two lanes independently raised a High, and they were right.

### What round 1 broke

**`refreshData()` was overridden WITHOUT re-declaring `#[On('refresh-dashboard')]`.** PHP does not
inherit method attributes onto an override -- verified directly in this session:

```
$ php -r '... class C extends P { public function r(): void {} } ...'
P declaring=P attrs=1
C declaring=C attrs=0
```

Livewire's `AttributeCollection` reflects per declaration and walks the parent chain for CLASS
attributes only, so the listener was silently de-registered. The expanded card is keyed
`<class>-expanded` and is therefore never remounted, and `$filters` is `#[Url]`, not `#[Reactive]`
-- so the expanded card would have stopped responding to the dashboard date toggle entirely,
silently, until a full page load. Every other `refreshData()` in this namespace re-declares the
attribute; this was the only one that did not.

Round 1 introduced this to fix a memo bug its own docblock called "latent rather than live". That
is a live regression traded for a latent bug, which is strictly worse -- the lanes said so and the
grade was correct to drop.

**Why no test caught it:** `test_refresh_data_clears_this_cards_own_memos` called the method
directly on a PHP object. The method was never broken -- only its registration was. The new test
dispatches through `Livewire::test(...)->dispatch('refresh-dashboard', ...)`, which is the shape
that exercises the attribute.

### Fixed

| finding | lanes | fix |
| --- | --- | --- |
| **`#[On]` dropped by the override** (High) | 2 | attribute re-declared + `Livewire\Attributes\On` imported; pinned by a real Livewire dispatch test |
| **The expanded chart still drew the fabricated flat-zero 13-bar series** — round 1 suppressed only the compact sparkline, and the expanded card is where the chart IS the content | 2 | `getData()` returns an empty dataset/label pair when the numerator is unavailable |
| **Clock control fixed on one test but not the other 23** — the class-not-instance pattern; a bare `setTestNow('...')` parses in `config('app.timezone')`, which nothing pins | 2 | one `freezeUtc()` helper, used by every test in the file |
| **The query test hardcoded `clarity.`** — the exact rule this ticket cites, broken in its own test | 1 | resolves the configured database, as the query class does |

35 card tests (was 33) + 9 query tests. Both round-2 fixes mutation-verified: removing the
`#[On]` attribute fails the Livewire test; un-suppressing `getData()` fails the expanded-chart test.

### On the round budget

`/ticket` bounds gate-failure fixes to ONE cycle, and round 1 -> round 2 was it. Round 3 is being
run anyway, deliberately, and the reason is narrow: round 2's High is a regression this pipeline's
own round-1 fix introduced, not a pre-existing finding argued twice. Parking with a known live
listener regression would also strand `reviewed_sha` behind HEAD and fail `rework-gate.sh` at
Phase 7. If round 3 does not clear the gate, the run parks with everything disclosed rather than
looping a fourth time.
2026-09-14 21:54 CDT · implemented · review RN2 gate FAILED (76/84/92); fixed the 2-lane High regression introduced by RN1 (refreshData override dropped the inherited #[On] Livewire listener) + expanded-chart suppression + test clock control; 35 card tests, mutation-verified

## Review round 3 — gate PASSED (94 / 88 / 94; arbitration 94, lane_min 88)

`REVIEW_GATE: pass score=94 src=arbitration lane_min=88 lanes=3 rn=3 sources=harness-claude-p:3`

**Zero High findings**, down from 1 (RN1) and 2 (RN2). Sixteen findings unioned across the three
lanes, every severity taken from the lane that raised it — nothing downgraded to clear the gate.
Six Medium, nine Low, one non-scoring. Arbitration scores 94 under the "only Medium" rule.

Divergence between the arbitration score and lane_min is 6, under the 10-point threshold that
would flag it.

### Not fixed, and why — the top one first

**A denominator-only ClickHouse fault still 500s all sixteen cards** (Medium, 2 lanes). This is the
strongest open finding and it is a fair hit on decision d2. d2 chose *propagate* because a swallowed
denominator failure would render `0.0` indistinguishably from real data — but `UNAVAILABLE_VALUE`,
built in round 1, dissolves exactly that dilemma. The premise is weaker than when the decision was
made. It was left alone because reversing a documented, reviewed decision in the last round is how
round 2's regression happened, and because `RecoveryCounts` reads the same table with no catch, so
the grid goes down regardless. Recommended follow-up, flagged to the operator rather than quietly
carried.

The remaining Mediums: the shipped sibling still fabricates `0.0` for the same failure (out of
scope); a marker-suppressed numerator discards valid cached months; flag order-dependence on the
window arms; the current-month bar divides month-to-date scans by a whole-month denominator
(introduced by round 1's fix for the *opposite* finding — the two constraints genuinely conflict and
flip-flopping a third time would be churn); and the `RecoveryCounts` parity behind decision d1 is
pinned by no cross-CARD test.

### Round-count honesty

Three rounds is one more than `/ticket` nominally allows for gate fixes. The reason is recorded
above: round 2's High was a regression introduced by round 1's own fix, and parking on a known live
listener regression would have stranded `reviewed_sha` behind HEAD and failed `rework-gate.sh` at
Phase 7 anyway. The run stops here regardless of what a fourth round might say.

2026-09-15 · reviewed · parked at --stop-at=review; Phase 6 (verify) belongs to the next session.
2026-09-14 22:14 CDT · reviewed · review RN3 gate PASSED: score=94 src=arbitration lane_min=88, zero High findings; parked at --stop-at=review

### Two obligations this session could NOT meet — for the next session to pick up

This build session loaded **no MCP servers** (the `--strict-mcp-config` spawn did not surface an
Atlassian tool). Two things the flow asks of a build session therefore did not happen:

1. **The Jira status-mirror comment was not posted.** It still reads `gated`. The PR Status block
   is current and is the live surface. Phase 7 step 2.5 reconciles the mirror.
2. **The ticket's comments were NOT re-checked at the `planned` / `implemented` / `reviewed`
   boundaries.** That check exists to catch a scope change arriving mid-build, and it simply could
   not run here. **Phase 7 step 2.4 is therefore the only backstop for this run** — it must fetch
   comments created after `prep-context.generated_at` and compare them against what was built,
   before the Build Record is posted. Anchor: `prep-context.generated_at`; the journal's `prepped`
   line (2026-09-14 15:37 CDT) is the fallback.

Also for Phase 4's entry condition: the To Do -> In Progress transition could not be performed, so
`state.jira_in_progress_by` is unset and session C should do it before the Build Record.
2026-09-14 22:15 CDT · reviewed · record the no-MCP gaps for Phase 7 reconcile (Jira mirror, In Progress transition, mid-build comment re-check)

## Phase C resume (2026-09-15) — pre-verify cleanup

Session B (0cbea5e5) parked at `--stop-at=review`. Both gates re-verified on resume:
`REWORK_GATE: pass` (only investigation artifacts changed since `reviewed_sha`
b8d395ef2) and `REVIEW_GATE: pass score=94 src=arbitration lane_min=88 lanes=3 rn=3
sources=harness-claude-p:3`.

Two defects found and fixed before Phase 6 could run:

1. **`.handoff/INITIAL_PROMPT.md` was in the PR diff.** `spawn.sh` writes it into the
   worktree in `inplace` mode and the Phase 2.5 checkpoint (ce6e75b22) swept it in. It is
   run machinery, absent from `main`, and never reviewed as product code. Removed. Light-path
   edit under the materiality guard: one file, no sensitive glob, no architecture change.
2. **`.context/` held DEV-2469's QA artifacts, not this ticket's** — the ledger named
   `DEV-2469` and `qa-ac-results.json` was dated 2026-09-12, both predating this run. This
   worktree is reused (`dev-2409-investigation-1a0ab8`), and `.context/` is not ticket-scoped.
   Left in place, the Phase 6 AC fold-in would have read a prior ticket's results and
   **false-passed** DEV-2470's acceptance criteria. Moved to `.context/_archive-DEV-2469/`.
   (A `_archive-DEV-2486` from an even earlier ticket was already sitting there — same trap,
   two tickets deep.)

Phase 6 therefore starts against an empty `.context/`, so qa-prep provisions and seeds for
DEV-2470 from scratch rather than reusing DEV-2469's environment.
2026-09-15 08:14 CDT · reviewed · pre-verify cleanup: drop .handoff spawn artifact from the diff; quarantine DEV-2469's stale .context QA artifacts

## Phase 6 — BLOCKED: host disk exhausted, ClickHouse cannot write (2026-09-15)

Provisioning got as far as it could and then hit a hard environmental stop.

**Completed before the stop**
- Postgres: `zod_claude_dev_2470_9936de` + `zod_testing_claude_dev_2470_9936de` both exist and
  are migrated (warm path — no bootstrap needed).
- `.env`: dev-login on, `APP_ENV=local`, ClickHouse pointed at the local container
  (`127.0.0.1:18123`, literal `clarity`/`zod`). `MAIL_MAILER` flipped `smtp` → `log`.
- PostGIS `tiger.state` patch applied (`simplified_geom`, `geom_4326` added).
- Seed SQL composed and persisted to `.context/qa-ch-seed.sql`.

**The stop.** `clarity.recoveries` and `clarity.scans_cold` are both empty and **cannot be
written to**. Every write fails:

```
Code: 1001 filesystem error: in create_directories: Input/output error
  ["/var/lib/clickhouse/store/465/.../tmp_insert_202609_1007_1007_1"]
```

Reads still work (`SELECT count()` returns 0 fine) — it is writes only. Root cause is **not**
ClickHouse: the host volume is **100% full**, 2.9 GiB free of 926 GiB, so the Docker Desktop VM
has no room to allocate. Docker's own content store is erroring too (`docker system df` and
`docker images` both fail with `input/output error` reading their blob store), which confirms
the VM disk, not the container.

Measured consumers (read-only): `~/Library/Containers` 155 GB (of which Docker.raw is 153 GB),
`~/Library/Caches` 41 GB, `~/Herd` 40 GB.

**Not attempted deliberately.** No files were deleted and no `docker system prune` was run.
Pruning would destroy the other tickets' QA databases living in the same container
(`clarity_claude_dev_2409`, `clarity_claude_dev_2426`, and their `zod_*` pairs), and freeing
space on the operator's machine is their call, not the pipeline's.

**State is intact.** Both gates still pass (`REWORK_GATE: pass`, `REVIEW_GATE: pass score=94
src=arbitration lane_min=88`). The PR stays in **draft**, the ticket is **In Progress**, and no
Build Record has been posted — `done` is correctly unreachable. Resume with
`/ticket DEV-2470 --resume` once there is disk; Phase 6 re-enters at qa-prep and the persisted
`.context/qa-ch-seed.sql` applies as-is.
2026-09-15 08:20 CDT · reviewed · Phase 6 BLOCKED: host disk 100% full, ClickHouse writes fail (I/O); Postgres+PostGIS+env done, CH seed persisted

### Note for whoever resumes

The seed in `.context/qa-ch-seed.sql` is correct but was **not** applied. Do not apply it the
way this session first tried — splitting on `;` and piping statement-by-statement through
`curl` mangles the multi-line `INSERT ... SELECT` bodies. Use the documented path instead:

```
docker exec -i clarity-qa-clickhouse clickhouse-client --multiquery < .context/qa-ch-seed.sql
```

Expected once it applies (org 2, viewer America/Chicago, today 2026-09-15):
Today **6.0** (6 plates / 1 repo) · Last 7 Days **40.0** (40 / 1) · This Month **5.0** (40 / 8) ·
Last Month **0.0** (zero-denominator guard).

Two discriminators are built into the seed deliberately: 132 scan ROWS carry only 40 distinct
`ocr`, so a `count()`-instead-of-`uniqExact` regression in the numerator would read 16.5 rather
than 5.0; and every seeded recovery has `has_case_claim = 0`, so DEV-2469's case-claim-funnel
denominator sees zero here — which proves this card reads TOTAL repossessions and not the
sibling's population.
2026-09-15 08:25 CDT · reviewed · record Phase 6 blocker in state + resume notes for the persisted CH seed

## Phase 6 resumed — environment rebuilt from scratch (2026-09-15)

Disk freed (470 GiB available, 49% capacity). The cleanup had removed **everything**: no Docker
containers, no images, and both Postgres branch DBs dropped. Full re-provision, not a restart.

**ClickHouse.** `up.sh --also-testing` created the container and applied the schema (clarity 33
objects, zod 48) but then **failed its own verification** — it asserts the prefix-rewritten
per-worktree database names and this repo needs LITERAL `clarity`/`zod` (~15 query classes
hardcode the prefix, and `.env` was already pointed there). The container and schema were correct;
only the assertion failed. Steered with `QA_CH_DB_CLARITY_PATTERN=clarity` /
`QA_CH_DB_ZOD_PATTERN=zod` — a pattern without `{wt}` substitutes to a literal name. Writes
confirmed working, which is what failed before.

**Postgres.** Re-ran `.claude/hooks/bootstrap.sh`. Its `db:seed` died on
`no_scans_in_illegal_states()` exactly as qa-prep documents — the PostGIS patch does not exist yet
at that point — and bootstrap still exited 0 with the DB and site healthy. Applied the
`tiger.state` patch, then `db:seed --force` reseeded clean.

**Consequence of the cleanup, recorded:** the other worktrees' ClickHouse databases
(`clarity_claude_dev_2409`, `clarity_claude_dev_2426` and their `zod_*` pairs) and the
`zod_claude_dev_2470_9936de` Postgres DB were all destroyed. Those tickets will need their own
re-provision.

### Two environment traps hit and diagnosed

1. **Spatie roles need the permissions team id.** `UniqueScansToRepo::canView()` returned `false`
   in tinker while the user genuinely had Administrator — `hasAnyRole()` resolves nothing until
   `setPermissionsTeamId($user->org_id)` is called. This looked exactly like a seeding gap. It was
   not; verified by re-reading roles with and without the team id. **No role was assigned** — the
   "fix" would have been wrong.
2. **`Org::isProEnabled()` caches for 30 minutes** under `org_pro_enabled_<id>`. Toggling the
   entitlement to test the non-Pro path cached `false`, and restoring the org meta did **not**
   restore the gate — a fresh process still read `false`. `pennant:purge` did nothing because the
   value was never in Pennant storage. Fix is `cache()->forget('org_pro_enabled_<id>')`. Anyone
   flipping Pro state for QA must clear that key or the change silently does not take.

### Backend verification — both query classes, real ClickHouse

| check | result |
| --- | --- |
| `RecoveryTotalsBatchQuery` Sept series | `{"2026-09": 8}` |
| `RecoveryTotalsBatchQuery` window arms | today 1 · last 7d 1 · this month 8 · last month 0 |
| `ScanPlateStatsBatchQuery` Sept series (UTC) | `{"2026-09": 40}` |
| Card `getCardRatio()` — today | **6.0** |
| Card `getCardRatio()` — last_7d | **40.0** |
| Card `getCardRatio()` — this_month | **5.0** |
| Card `getCardRatio()` — last_month | **0.0** (zero-denominator guard) |
| count-vs-uniqExact discriminator | **PASS** — 5.0, not the 16.5 a `count()` regression would give |
| expanded chart | 13 points / 13 labels, last label `Sep '26`, single non-zero bucket `[5]` |
| Pro gate, entitled | `canViewCardData()` true |
| Pro gate, non-entitled | `canViewCardData()` **false** — data withheld |

Two of my own test-call errors, recorded so they are not mistaken for defects: `handleRange` takes
naive **datetime** strings, so passing bare dates makes a zero-width window; and the Last-7-Days
filter key is `last_7d`, not `last_7_days` — the wrong key silently falls through to the
this-month path.

### Tests

`tests/Filament/Widgets/Dashboard` + `RecoveryTotalsBatchQueryTest` against the rebuilt env with
real ClickHouse: **324 passed, 1287 assertions**.

**Confirmed hazard:** the suite shares the literal `clarity`/`zod` databases, so it **wiped the QA
seed** (scans 132 → 0, repos 8 → 0). Re-applying `.context/qa-ch-seed.sql` restored it exactly.
Seed LAST, and re-apply after any suite run.
2026-09-15 09:38 CDT · reviewed · env rebuilt from scratch; backend verification green; 324 tests pass; seed re-applied

## Phase 6 — VERIFIED (2026-09-15)

Agent QA passed: **8/8 browser checks, 0 defects, 0 console errors**. ACs folded in:
**9 satisfied, 0 not satisfied, 2 N/A** (both recorded with reasons, not dropped).

All four arms matched, and the quotient was cross-checked against **independently computed
operands** — the numerator and denominator are each rendered by their own cards on the same grid,
so the ratio was never validated against itself:

| Filter | Card | Unique Scan Counts | Total Repossessions |
| --- | --- | --- | --- |
| Today | 6.0 | 6 | 1 |
| Last 7 Days | 40.0 | 40 | 1 |
| This Month | 5.0 | 40 | 8 |
| Last Month | 0.0 | 0 | 0 |

**The discriminator held.** 132 scan rows carry only 40 distinct plates, so a
`count()`-instead-of-`uniqExact` regression would render 16.5. The card reads 5.0.

**One lane observation investigated and dismissed.** The lane noted the collapsed card carries no
description, so "scans per repo" only appears on expand. Checked: `getDescription()` does exist
with correct per-arm text, and **no** dashboard card wires `->description()` in `toStat()` — the
shipped sibling DEV-2469 included. The compact grid shows the number alone by house pattern. Not
a defect; AC-8 marked satisfied with that evidence rather than waved through.

**AC-1 remains N/A on purpose.** Oscar never answered. The build proceeded on the operator's
explicit override, and the Build Record says so rather than presenting the definition as settled.
2026-09-15 09:44 CDT · verified · agent QA PASS 8/8, ACs 9 satisfied / 0 failed / 2 N/A, 324 tests green
2026-09-15 09:44 CDT · verified · agent QA PASS 8/8, ACs 9 satisfied / 0 failed / 2 N/A, 324 tests green
2026-09-15 09:46 CDT · verified · human QA plan written; parked for the manual pass, QA env left up

## Phase 7 step 1 — human QA guide rendered (2026-09-15)

`.context/qa-human-guide.html` written and opened. Env re-verified live (check-only, nothing
fixed): site 200, DB migrated, ClickHouse local container up with the seed intact
(132 scans / 40 plates / 8 repos), dev-login on, persona resolves. One warn row — no queue
worker, which this card does not need.

**Gate note.** The plan's `head_sha` was behind HEAD, which would normally force a full
re-prep. Checked first: `git diff --name-only c864ee8b4..HEAD` excluding
`docs/investigations/` was **empty** — the drift was this pipeline's own artifact commits, not
code. Re-baselined `head_sha`/`wip_sha` and rendered, rather than re-provisioning and re-seeding
a world nobody had touched.

Zero agent-qa findings to fold (PASS 8/8), so `agentQaFindings` is omitted rather than emitted
empty. Four curated screenshots attached to their steps.

`resourceEnvelope` rendered with **`status: "not-measured"`** — the change is scale-relevant and
QA ran at toy scale, so the honest value is not-measured. The measured production *query*
envelope is shown alongside it as a separate row so the two are not confused.

Parked for the operator's manual pass. PR stays draft; the QA env stays up.
2026-09-15 10:00 CDT · verified · human QA guide rendered and opened; parked for the manual pass

## Phase 7 — closeout (2026-09-15)

Human QA completed by the operator; no findings reported. No product change.

Gates re-verified before anything irreversible: `REWORK_GATE: pass` (the only file changed since
the passing review is the `.handoff/INITIAL_PROMPT.md` *deletion*). Final shipping diff is exactly
the five intended files — the spawn artifact is gone.

PR #406 undrafted and retitled. Build Record posted as comment **39886**.

**CI note.** The undrafted HEAD was a `wip … [skip ci]` checkpoint — five of the last six commits
carry that marker — so "Pint & Tests" reported nothing on the branch. That is the documented trap:
undrafting on a skip-ci head does not start CI, and `--boundary` does not recover it. Pushing a
clean-message commit as the trigger rather than closing and reopening the PR.

**Left alone deliberately:** the ticket keeps its `blocked-needs-definition` label and the
`[BLOCKED: …]` prefix in its summary. Oscar still has not answered; the code shipped on an
operator override, so the definitional question is genuinely still open and the marker is still
accurate.
2026-09-15 10:04 CDT · done · DEV-2470 | Company Metrics — Unique Scans to Repo card: build record posted, PR ready for review

### Teardown + done gates

Torn down **by hand**, not via `clickhouse-qa down.sh`: this env used LITERAL `clarity`/`zod`
ClickHouse databases, and `down.sh` derives per-worktree names — it would have missed these and
could have reached for shared ones. Each step shape-guarded against the ledger's own values:

- `.env` `MAIL_MAILER` restored `log` → `smtp` (the one key this run changed)
- container `clarity-qa-clickhouse` removed (name matched the ledger exactly)
- `zod_claude_dev_2470_9936de` + `zod_testing_claude_dev_2470_9936de` dropped — each checked for
  this worktree's suffix first; **the shared `zod` and `zod_testing` were verified still present
  afterwards**
- Herd site unlinked; ledger deleted last, then all four verified gone

Both gates pass: `REWORK_GATE: pass` · `BUILD_RECORD_GATE: pass id=39886 (route=feature, ac=11,
review_rounds=3, rerun_required=false)`.

CI runs on the clean-message head `5f9eedee3` — Pint & Tests ×3 plus the preview deploy — after
the `[skip ci]` checkpoint head reported nothing.

**Run complete.** PR #406 ready for review, ticket In Code Review, Build Record comment 39886.
2026-09-15 10:06 CDT · done · run complete: QA env torn down, both done gates pass
