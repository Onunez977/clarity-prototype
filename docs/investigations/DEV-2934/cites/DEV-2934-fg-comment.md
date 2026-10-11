**First Glance**: Sprint 42 · **Ready**

| Field | Value |
| --- | --- |
| Key | DEV-2934 |
| Sprint | 42 (active) |
| App | Clarity |
| Repo | insight-lpr/platform |
| **Branch path** | **main→main** (bugfix). Base is current main `43255f51`, the [DEV-2928](https://insightlpr-team.atlassian.net/browse/DEV-2928) / PR [#510](https://github.com/insight-lpr/platform/pull/510) squash merge. Jeff merges the QA Ready PR into main. |
| Verdict | **Ready** |
| Hardness / size | Low · **S**. About 20–40 lines of production code across the job, one small accessor in `RDN.php` and the dry-run mirror in the command, plus feature tests. |
| Surface | RDN case-info error classification only. No query, UI, ClickHouse, queue, Horizon or config change. **ch_touch: no** (#31). #25 parity does not apply (no query rewrite). #27 does not apply (no UI or copy change). |
| Hard stop | Classification change inside the existing error handling only. A per-case "not authorized" SOAP fault becomes a miss that is not retried, in both the job and `app:rdn-backfill-branches` (live and dry run). Every other 500 and transport error keeps today's retry. No new queue, job, throttle, config or `config/horizon.php` change (#24). The API key never appears in logs, test output or the PR. **Out of scope:** the duplicate Orlando branch (RDN ids 20576 / 21849). |
| Forecast gates | See notes 1–4 below. Main one: the AC says "not retried", so the default is to **seal the row on the first refusal** rather than spend one of its 3 attempts. |
| Collisions | None. Open PRs #511, #509, #503, #490 and #489 touch no RDN file. Relates to [DEV-2928](https://insightlpr-team.atlassian.net/browse/DEV-2928) (merged). Must land before the org 167 production backfill after the next release. |
| Next | Prep → Plan → Provision (keyed test PG only; no Docker CH, no twin) → Implement → Review → Agent QA (suites) → Verify → **undraft touch-base** (the human checkpoint, since there is no query or UI change). No branch, PR or provision at glance. |

**Recon (main `43255f51`)**

* **Where the fault is lost:** `app/Services/RDN.php` `call()` L106–146. The `catch (\Exception)` at L121–125 logs `$exception->getMessage()` (L122) and returns `EMPTY_RESULT` (L128–129) with `lastCallCompleted` left false (L108, L120). Callers never see the fault text, only `lastCallCompleted()` (L101–104).
* **Transport vs miss in the job:** `app/Jobs/Rdn/GetOrgHotlistCaseInfo.php` `handle()` L366–429. Resolved L404–409. An incomplete call returns `OUTCOME_TRANSPORT_FAILED` with no write and no attempt spent (L411–422). An answered miss calls `recordUnresolvedLookup()` then `miss_no_branch_match` / `miss_no_branch_name` (L424–428). Every RDN SOAP fault arrives as HTTP 500, so the refusal currently lands in the transport branch at L418.
* **Attempts and ceiling:** `ATTEMPTS_KEY = 'branch_lookup_attempts'` (L42). `sealedPredicate()` L98–102. `maxLookupAttempts()` L120–126 = min(config 3, selectionsPerWindow − 1) = **3** with the shipped 4-day lookback and daily run (`config/services.php` L79–80, L91). `recordUnresolvedLookup()` L510–550 increments by 1 and leaves a resolved `branch_id` alone. `isSealed()` L461–467.
* **Outcome counters:** `resolved`, `miss_no_branch_name`, `miss_no_branch_match`, `transport_failed`, `sealed` (L44–57), emitted as `rdn_case_info.<outcome>` in `recordOutcome()` L450–459. The docblock at L437–442 already scopes `miss_no_branch_name` to "credentials, ids, or genuinely unknown cases".
* **Command:** `app/Console/Commands/RdnBackfillBranches.php`. The live path runs `$job->handle()` in-process (L147–178), so it inherits the job fix. Its summary has only walked / processed / failed (L189–214), and the refusal already counts as *processed*, not failed. The **dry run does not call `handle()`**: `classify()` L285–324 duplicates the rule and returns `transport_failed` at L313–315, so it needs the same change. Re-selection: `eligible()` L333–342 filters on `sealedPredicate()`, so sealing is what stops repeat backfill passes. Scheduled runs only reselect inside the 4-day `created_at` lookback (`GetOrgHotlistCases::candidates()` L350–357).
* **Real fault text** (DEV-2928 pre-prod dry run, hotlist 31963346, HTTP 500, logged by `RDN.php` L122): `This API key is not authorized to access this case`. The ticket paraphrase ("API key not authorized for this case") is not the literal string, so match the logged text.
* **Existing tests:** `tests/Feature/Jobs/Rdn/GetOrgHotlistCaseInfoTest.php` has transport no-burn at L1046–1088, an existing counter left alone at L1092–1139, transport outcome at L1459–1487, miss counter at L733 / L975 and the at-ceiling seal at L1330–1357. `tests/Feature/Console/Commands/RdnBackfillBranchesTest.php` has the stub `stubSoapClient()` L427–447 (default arm throws `SoapFault('HTTP','Could not connect to host')`), live L135–165 ("walked 4, processed 3, failed 1"), dry-run per-bucket regex L239–289 and dry-run vs job parity L293–325.

**Proposed minimal approach (for Plan to confirm)**

1. `RDN.php`: in the existing catch (L121–125), keep the caught exception on the instance and expose it next to `lastCallCompleted()` (one property and one getter, reset at L108). Return shape and other callers stay unchanged. This file is the only place the fault text exists. Reading `__getLastResponse()` from the job instead was considered and rejected as fragile.
2. `GetOrgHotlistCaseInfo.php`: add one public static check next to `findBranch()`, which the command already shares. It returns true only for a `\SoapFault` whose message contains `not authorized to access this case` (case-insensitive). In `handle()`, before the transport return at L418, a refusal records a miss with the counter written to `maxLookupAttempts()` (sealed on first sight, `branch_id` left as is) and emits `miss_no_branch_name`. No new outcome or metric, so the dry-run table columns and the runbook buckets stay the same.
3. `RdnBackfillBranches::classify()`: the same check before L313 returns `miss_no_branch_name`. The live path needs no change.

**Risk:** a broad match ("not authorized", "API key", or any HTTP 500) would also catch an org-wide key rotation or revocation. Every row that run touched would then be sealed, and there is no un-seal path or TTL (L411–417). That is why the match uses the case-specific phrase on `SoapFault` only, and why a generic 500 and a key-wide refusal stay `transport_failed`.

**Test plan:** extend the existing stubs, with no new harness.
* Job: a refusal `SoapFault` gives a sealed row (`branch_lookup_attempts` = ceiling), `branch_id` untouched, `miss_no_branch_name` emitted, and no re-dispatch by `GetOrgHotlistCases` (the inverse of L1046–1088). Also a refusal on a row with an existing counter, and a regression test that a generic 500 (`SoapFault('SOAP-ENV:Server','Internal Server Error')`) stays transport with no write and is re-dispatched. L1046 / L1092 / L1459 stay green.
* Command: add a `DENIED` arm to `stubSoapClient()`. Live: sealed, `failed 0`, and a re-run does not call RDN for it (`calledCases`). Dry run: counted under `miss_no_branch_name`, nothing written. Add `DENIED` to the parity loop at L302.
* Secrets: assert the org's `rdn_api_key` is absent from command output and logs (`Log::spy`). Run Pint.

**Forecast gates / notes (no answers invented)**
1. **Seal vs single attempt:** the AC reads "recorded once as a miss and is not retried", so the default is to seal on first refusal. A plain miss (+1 attempt) would still call RDN up to 3 times before sealing. If a normal miss is wanted instead, say so before Plan.
2. **Scope note:** fixing the job alone cannot meet the AC. It also needs the small `RDN.php` accessor (the job never sees the fault text) and the dry-run `classify()` mirror. Both are within "existing error handling", with no new class, queue or config.
3. **Live summary:** there is no miss bucket in the live summary (walked / processed / failed), and the refusal already counts as processed. Default is no new summary column; the miss shows as the sealed counter plus `rdn_case_info.miss_no_branch_name`. Ask only if per-outcome live counts are wanted.
4. **Environment:** test PG only. No Docker CH, no Path A twin, no Cloud CH (#18 / #31 do not apply). Agent QA is suites-only.

Live Jira re-GET 2026-10-08 ~12:26pm CDT: Task · Sprint 42 (active) · Application Clarity · epic [DEV-1580](https://insightlpr-team.atlassian.net/browse/DEV-1580) · relates to [DEV-2928](https://insightlpr-team.atlassian.net/browse/DEV-2928) · assignee Dalton · To Do · no prior comments. Code read at main `43255f51`.
