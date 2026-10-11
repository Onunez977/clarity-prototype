# DEV-2928 Provision — Path A LIVE ledger cited

**Phase:** Provision **Done** · **Next:** Implement
**Gate:** CLEAN
**At:** 2026-10-07 4:48pm CDT
**PR:** https://github.com/insight-lpr/platform/pull/510 (draft · base `claude/DEV-2442` @ `42a4310f`)
**Tip at provision smoke:** `2fd4c3e9487d8687b5989449554c48296d81438e` (unchanged by Provision)

## LIVE ledger (authoritative)

- `/workspace/docs/investigations/DEV-2928/qa-prep-ledger.json` (stood + proved 4:46pm CDT)
- Slim cite: `/workspace/docs/investigations/DEV-2928/provision-cite-slim.json`
- Checkout ledger copy: `/workspace/dev-2928-wt/.context/qa-prep-ledger.json` (`provision_mode: fleet_path_a_cli`; disk only — never commit `.context/`)
- Spec (checked): `/workspace/docs/investigations/DEV-2928/DEV-2928.md` · checkout copy `/workspace/dev-2928-wt/.ai/specs/DEV-2928.md` (disk only — never commit `.ai/`)
- Jira plan comment **43358** · First Glance **43357**

Confirmed from ledger JSON:

| Field | Value |
| --- | --- |
| `prove_passed` | `true` |
| `live` / `status` | `true` / `live` |
| `cloud_touched` | `false` |
| `mac_herd_refused` | `true` |
| `CLICKHOUSE_DRIVER` | `clickhouse` (not pgsql) |
| Schema | `database/schema/clickhouse-schema.sql` @ `2fd4c3e9` loaded as-is (CI recipe) · 0 load errors · **86/86** object names match the file |
| Live Path A keys | DEV-2450, DEV-2928 (2 of 2 slots) — DEV-2450 untouched and healthy |

## Fleet endpoints (Path A twin)

| Service | Endpoint | Database / path |
| --- | --- | --- |
| Site | `http://172.21.0.1:18094` (also `127.0.0.1:18094`) | login `/app/login` · container `clarity-qa-app-dev-2928` |
| Postgres | `172.21.0.1:15446` | dev `zod_dev_2928` (user `clarity`) · test `zod_testing_dev_2928` |
| ClickHouse (Docker, dedicated per-key container) | `http://172.21.0.1:18137/?database=clarity` | real `clarity` + `zod` DBs, no prefix rewrite · server 25.8.11.66 |
| Network / gateway | `clarity-qa-dev-2928` / `172.21.0.1` | |
| Checkout | `/workspace/dev-2928-wt` | branch `cursor/dev-2928-rdn-backfill-branches` · tip `2fd4c3e9` · `vendor/` present |
| QA user | `test@tester.com` | password via secrets ref |

Secrets (refs only, never print): `/workspace/first-glance/secrets/dev-2928-*.txt`, `clarity-qa-dev-2928.env`.
**Do not steal/rebind:** DEV-2450 `:18093/:15445/:18136` (LIVE).

## Smoke results (box CLI · no Mac Herd · Cloud not contacted)

| Check | Result |
| --- | --- |
| GET `/app/login` | **200** |
| PG `SELECT 1` (`zod_dev_2928`) | **1** |
| PG public / platform BASE TABLE | **139 / 41** |
| PG seed (ids 9280001–9280018, orgs 92801–92804) | hotlist **17** · hotlist_json **11** · hotlist_status_log **12** · platform.branches **6** · orgs with `rdn_api_key` **2** |
| CH `SELECT 1` (`clarity`) | **1** · version 25.8.11.66 |
| CH `clarity` objects | **39** (ReplacingMergeTree 20 · MergeTree 5 · AggregatingMergeTree 5 · View 6 · MaterializedView 3) |
| `clarity.hotlist.branch_id` | **Nullable(Int64)** |
| `clarity.lpr_assists` | **View** |
| CH seeded rows | hotlist **18** (FINAL 17) · recoveries **8** · recovery_scans **9** · lpr_assists **7** · missed_opportunities **9** · hotlist_status_transitions **12** |
| `.env.testing` (#31) | `CLICKHOUSE_DRIVER=clickhouse` · `CLICKHOUSE_HOST=172.21.0.1` · `CLICKHOUSE_PORT=18137` · `CLICKHOUSE_DATABASE{,_CLARITY}=clarity` · `_ZOD=zod` · PG `172.21.0.1:15446/zod_testing_dev_2928`. phpunit.xml does not force-override CLICKHOUSE_DRIVER/host. |
| `.env` | twin (`zod_dev_2928` · CH `172.21.0.1:18137`) |
| Cloud CH | not queried · `cloud_touched=false` |
| Mac Herd | refused · not provision host |

Ledger proof (Path A stand, cited not re-run): branch-filter shape on seeded org 92801 — current branch A: MO 3 / LPR 2 (old copied: 1 / 1); B: MO 2 / LPR 1 (old 2 / 2); unfiltered MO 7 distinct / LPR 6; transition-card repossessions for A = 2 = LPR new(A). Harness smoke: `LprAssistBranchFilterTest` OK, `HotlistStatusTransitionBranchFilterTest` 9/48 OK on real Docker CH from the box.

## Sealed plan (Implement)

- Spec Status **READY** · Branch path stacked on `claude/DEV-2442` · **ch_touch: yes** (read path only)
- Build per spec §6: command `app:rdn-backfill-branches` + `{rdn-backfill}` queue/supervisor; `GetOrgHotlistCaseInfo::findBranch()` normalized fallback; `LprAssist` / `MissedOpportunity` current-branch subquery. Zero consumer edits; All Branches SQL unchanged.
- Seed reference window: `--since=2026-07-01 --until=2026-10-08` (backfill-eligible preview newest-first: 9280002, 9280003, 9280014, 9280006).

## Implement caveats (must read)

1. **CH tests truncate `clarity`.** There is no separate CH test DB on this twin (deliberate: `LprAssist` hard-codes `clarity.*`, and CI also tests against `clarity`/`zod`). `RequiresClickhouse` TRUNCATEs the seeded tables on every CH test. After any CH suite run, **re-run `docs/investigations/DEV-2928/seed-work/ch-seed-dev-2928.sql`** (idempotent) so Agent QA has data. Seed ids are 928xxxx because `recovery_scans` is not in the truncate list yet (spec step 7 adds it — after that, reseeding is mandatory).
2. **Run phpunit from the box**, not the app container: `clarity-qa-app-dev-2928` lacks `psql`, so `RefreshDatabase` schema load fails there (exit 127). Box PHP is **8.4.26**; CI is **8.3** — keep code 8.3-compatible (PG-021).
3. **Twin CH 25.8.11.66 vs CI 26.2** (Cloud prod 26.6). Schema loaded cleanly on 25.8; if a query works here but CI disagrees, suspect version drift first.
4. PG dev DB is a fixture restore (app migrations not run); `zod_testing_dev_2928` is built by `RefreshDatabase` on first box-side run.
5. The #25 Cloud read-only measure (spec §10 P0–P3) is **not done**. The stand attempted a read-only SELECT via the ClickHouse connector and it was refused ("Service does not allow MCP calls") — not routed around. A sanctioned box-side read-only path from Dalton is needed before undraft (G1).
6. Never `CLICKHOUSE_DRIVER=pgsql` as a done-signal (#18/#31). Never `--parallel`.

## Hard stops (carry to Implement)

- Never push/commit/edit PR #374 or `claude/DEV-2442`; explicit-refspec pushes to `cursor/dev-2928-rdn-backfill-branches` only (branch has no upstream)
- Cloud CH **READ-ONLY** — writes only on this Docker twin
- No CH schema / MV changes; no production or fleet-wide backfill
- Reuse `GetOrgHotlistCaseInfo` dispatch contract unchanged; smallest change (#24)
- Implement agent · sonnet-5.5 · Extra/xhigh · per-key worktree · never `--trust-all-tools` (#23/#30)
- Q1–Q4 open — do not invent
- Do not steal Path A ports (DEV-2450 `:18093/:15445/:18136`; DEV-2928 `:18094/:15446/:18137`)

## Open gates

- #25 parity: Cloud CH read-only measure via a sanctioned box-side read-only path, filed in PR + Build Record before undraft
- Human QA check with Dalton before undraft (query + UI change)
- Retarget base to `main` after #374 merges

## Next

Implement against this Path A ledger. Cite this `provision.md` + `qa-prep-ledger.json`. No Implement handoff from the Provision step.
