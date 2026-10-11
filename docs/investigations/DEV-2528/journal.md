2026-09-14 15:46 CDT · intake · intake complete: route=bug app=clarity base=main
2026-09-14 16:41 CDT · prepped · prep complete: root cause PROVEN = local Postgres TimeZone=America/Chicago + no connection timezone key + naive Eloquent literals into timestamptz columns; gate=BLOCKED on production Postgres timezone

## Phase 2 — Question Gate (interview)

Q1 (MATERIAL): Is production's Postgres TimeZone GUC already UTC? Proposed fix was
`'timezone' => 'UTC'` on the pgsql connections in `config/database.php`.

A1 (human:Dalton 2026-09-14): "We shouldnt change any production stuff, isnt this a testing
suite thing?" — `config/database.php` ships to production, so that fix is rejected. Ticket is
re-scoped to TEST-ONLY. Production's Postgres timezone is NOT verified and is recorded as an
open risk in the Build Record rather than acted on.

Driver note: the operator's read is defensible. The failure is test-only; the mechanism is not
test-specific but only bites on a non-UTC server, and CI is UTC. Residual risk stated, not argued.

Q2: Does the "passes in isolation" premise hold?
A2 (code/driver): settled empirically in verification rather than asked of a human — the proven
mechanism is deterministic and predicts failure in both modes.

New lever, verified on this box before adopting:
  PGTZ=UTC  -> platform session TimeZone = UTC
  (unset)   -> platform session TimeZone = America/Chicago
libpq reads PGTZ and sends it as the connection startup timezone. phpunit.xml is not read in
production, so this is genuinely test-scoped.
2026-09-14 20:08 CDT · gated · gate answered: scope re-cut to TEST-ONLY (PGTZ in phpunit.xml); production config change rejected by operator
2026-09-14 20:42 CDT · implemented · fix: pin the test suite's Postgres session timezone to UTC via PGTZ (phpunit.xml) + PostgresConnectionTimezoneTest; A/B proven 3 failures -> 0

## Phase 5 — Review RN1 (claude1, score 92/100, gate min 90 => PASS)

M1 (Medium) — fix is incomplete: only the PHPUnit process is pinned. Reviewer identified an
option never put to the operator: `'timezone' => env('DB_TIMEZONE')`, a verified production
no-op (PostgresConnector guards on isset(); env() null is not isset). Driver verified the claim
in vendor source, then put it to the operator.
  -> Operator: "Stay test-only, as it is now." Recorded as a known limitation, not a silent gap.

M2 (Medium) — the fix deletes the only signal that would ever surface the production exposure
AC-3 exists to catch. Remedy: state the residual prominently in the PR body and the Build
Record, and queue an okf/concepts/platform-gotchas.md PG-0xx entry for after approval
(CLAUDE.md WRITE-after-approval rule).

L1 (Low) — FIXED. The test claimed "every pgsql connection" but its data provider listed 2 of 4.
Data providers run before the application boots, so config() is unavailable there (first attempt
failed with "Target class [config] does not exist"); the enumeration moved into the test body and
now covers every driver=>pgsql connection at runtime. 2 passed / 8 assertions.

L2 (Low) — AC-3 status recorded only in prep-context.json, which no PR reviewer reads. Remedy:
same as M2 - surfaced in the PR body.

Because L1 changed the reviewed code, RN2 is a `scope` round over the final diff (not a
gate-fix cycle; it does not consume the single gate-fix budget).

## Phase 5 — Review RN2 (claude1, score 80/100) => GATE FAIL

H1 (High) — REGRESSION INTRODUCED BY THE RN1 L1 FIX. Enumerating every `driver => pgsql`
connection pulled in `graphql`, whose database name is hardcoded in config/database.php and which
NEITHER CI workflow provisions (both create only `POSTGRES_DB: zod`). The test would have errored
in CI with `FATAL: database "graphql" does not exist`. It passed locally only because this
workstation happens to have that database — the exact workstation-only divergence this ticket
exists to eliminate. Driver verified both premises independently before fixing.
  -> FIXED: the assertion set is now `connectionsToTransact()` (zod, platform), with the reason
     documented in the test so nobody "improves" it back. PGTZ is process-wide for libpq, so
     pinning those two pins every connection the suite opens.

M1 (Medium) — the recorded full-suite figures described the RN1 run, not the reviewed code, and
the "A/B-proven unrelated" claim had not been re-established after the RN1 edit. Correct, and a
fair catch on reporting honesty.
  -> FIXED: record.tests corrected. Re-established empirically: the failure count swings 8-10
     across four runs of identical code (CH container 5.21 GiB ceiling), and the specific
     timezone-crossing test the reviewer flagged (ClarityScoutTripsControllerTest,
     utc_time_lands_in_the_local_evening) passes 3/3 WITH the fix and 3/3 WITHOUT it.

M2 (Medium) — incomplete by class; repaired for PHPUnit only. Operator decision, twice, on two
distinct variants. Recorded as a known limitation in the Build Record, not silently dropped.

M3 (Medium) — the test name/docblock over-claimed and its subject set moved with CLICKHOUSE_DRIVER.
  -> FIXED by the same H1 repair: the name, docblock and assertion set now agree.

L3 (Low) — literal 'UTC' string match would mis-describe a semantically-correct UTC session.
  -> FIXED: asserts the session's UTC offset is 0 instead. Red output now reads -18000.

L1/L2 (Low) — unlisted sibling sites on production paths (`pg` connection, GeocodeRecoveryAddresses).
Out of scope under the operator's test-only decision; carried into the Build Record as named
residual risk rather than dropped.

RN3 is the single gate-fix cycle for the RN2 failure.
2026-09-14 21:52 CDT · implemented · review RN2 gate-fail (80): fix H1 CI-breaking regression (graphql db absent in CI), M3 over-claim, L3 offset-vs-name; correct the stale test record per M1
2026-09-14 22:05 CDT · reviewed · review gate PASS score=94 (RN3); RN2 H1 CI-breaking regression found and fixed; residuals posted to PR
2026-09-14 22:06 CDT · verified · AC verification complete: AC-1 SATISFIED, AC-2 SATISFIED (isolation + full suite, A/B controlled), AC-3 N/A with open risk recorded
2026-09-14 22:08 CDT · done · DEV-2528 complete: root cause proven, test-only fix, review 94/100, PR #407 ready for review
2026-09-14 22:16 CDT · done · CI green on all 3 shards; run complete
2026-09-15 08:15 CDT · reviewed · trim PostgresConnectionTimezoneTest to the single round-trip assertion (84->49 lines) per operator request; resolves review finding RN3/L1
2026-09-15 08:22 CDT · done · CI green on the trimmed test (3/3 shards); run complete
