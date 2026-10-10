# DEV-2801 journal

## 2026-10-06 — Ultra-Prep (live)

- Re-GET Jira DEV-2801: To Do · Dalton McCleery · Task · Sprint field empty (Dalton kick → Sprint 42).
- First Glance comment **43292** live: Branch path **main→main** (CM sibling of DEV-2660; not scout holding stack). Verdict Ready.
- Reach #31 ledger PASS / torn_down (`/workspace/first-glance/CH-REACH-KIRO-31-ledger.json`). Path A Docker CH for provision still **future** — do not invent Mac/localhost CH.
- Soft collisions: DEV-2660 (MERGED PR #470 — do not reopen tip/undraft), DEV-2657, DEV-2904 LprRepossessions surfaces; DEV-2892 hotlist_status_transitions out of scope.
- Scout: `LprRepossessionsQuery::recoveryJoins()` dual-join intact on `origin/main` @ `551ce86e4`:
  1. `zod.public_hotlist FINAL` — (org, vin) match, status=3, `_peerdb_is_deleted = 0`, `d.agent_org_id != 0`
  2. `clarity.hotlist` — `first_repossessed_at` via `ch.id = h.id AND h.id != 0`, no FINAL, floor prune
- Docblock explicitly keeps dual-join intentional post–DEV-2660; ~13.3% asymmetric CDC cited.
- Worktree: `/Users/daltonmccleery/tmp/DEV-2801-kiro/platform-wt` from `origin/main`.
- Branch: `cursor/dev-2801-cm-lpr-hotlist-single-join`.
- Gate: **CLEAN** (FG + branch path + scout clear). Next: Kiro Plan.

## 2026-10-06 — Plan CLEAN

- Draft PR #504 opened (base main, assignee DaltonMcCleery). Prep tip `11ee92e2f`.
- Kiro Planner harness (worktree-local agent; trust `fs_read,fs_write,grep,glob,code,thinking`; never `--trust-all-tools`).
- Wall ~8m 42s (15:38:37 → 15:47:19 CDT). Spec Status **READY**. Branch **main→main**. **ch_touch: yes**.
- Self-check CLEAN. Checked plan comment posted as Jira **43295** (neutral; no Fellowship names).
- Plan shape: Gate 1 Cloud READ-ONLY measure (candidates A no-FINAL / B FINAL) → decide collapse or keep. Keep is valid. Path A still future.
- STOP before Provision. No qa-prep. No Implement baton.

## 2026-10-06 — Path A LIVE (Galadriel)

- Fleet twin sole LIVE: site `:18092` · PG `:15444`/`zod_dev_2801` · CH `:18135`/`clarity_dev_2801`.
- Ledger `/workspace/docs/investigations/DEV-2801/qa-prep-ledger.json` · cite `provision-cite-slim.json`.
- Prove: login 200 · hotlist + first_*_at · public_hotlist · billing_claim · recovery_scans · clarity tables 31 · `CLICKHOUSE_DRIVER=clickhouse` · cloud_touched=false · mac_herd_refused.
- Checkout `/workspace/dev-2801-wt` @ tip `3c1744ba` (PR #504). Neighbors torn — ports not stolen.
- Unblocks Provision.

## 2026-10-06 — Provision Done

- Re-cited slim+full ledger: prove_passed=true · live · cloud_touched=false · mac_herd_refused=true.
- Box smoke (CLI, no Mac Herd): login **200** · PG SELECT 1 / public **139** / platform **41** · CH SELECT 1 / tables **31** · driver **clickhouse** · Cloud not contacted.
- Disk copies: `.ai/specs/DEV-2801.md` (+ jira-comment / checked) and `.context/qa-prep-ledger.json` under `/workspace/dev-2801-wt` (gitignored). Mac harness `.ai/specs` optional sync.
- Wrote `provision.md` + `provision.json`. state → phase=`provisioned` · next=Implement · flags.path_a_stood=true.
- Living ticket-status → Phase **provisioned** · Next **Implement** (Gate 1 Cloud RO measure / decide collapse first; Q1–Q3 still open).
- Tip unchanged `3c1744ba`. No product PHP. No Implement baton from this step.

## 2026-10-06 — Implement prep (Gate 1 PARKED)

- Conductor hold: **no Cloud CH queries** until Q1–Q3 lock.
- Worktree `/workspace/dev-2801-wt` @ `3c1744ba` branch `cursor/dev-2801-cm-lpr-hotlist-single-join` ready; vendor present; no push.
- `.env.testing` (gitignored) retargeted to fleet testing DBs: CH `172.18.0.1:18135` / `clarity_testing_dev_2801` + `zod_testing_dev_2801`; PG `zod_testing_dev_2801` `:15444`. Credentials from clarity-qa env (unchanged).
- Optional `CompanyMetricsCacheScopeTest` probe in app container: **failed env** (`psql: not found` during Laravel load) — not a suite green claim.
- Drafted Gate 1 SQL under investigation-disk `measure-raw/` (not committed to product PR; Soft #25 = summary parity docs only). M0 PHP-rendered dual-join, candidates A/B, agreement, M1–M4, CAPTURE.md. Cloud DBs `clarity`/`zod`; Jul 2026 defaults pending Q2.
- Stubs: `cloud-parity-jul-2026.md` + `-decisions.json` (`status=awaiting_q_lock`, `cloud_ch_writes=false`).
- Living ticket-status on disk only — PR body not updated/pushed.
- Next: Q1–Q3 unlock → Cloud RO measure → decide → then product path / Verify.

## 2026-10-06 9:29PM CDT — Gate 1 unlocked; Cloud RO batoned to conductor-side runner
Q1 100%/Δ0; Q2 Jul 2026 org=0; Q3 Cloud RO not sourced by Implement. Awaiting cite.

## 2026-10-06 21:32 CT — Gate 1 Cloud RO measure COMPLETE (KEEP)

- Auth: `readonly_user` @ `zd69ju2h8d…:8443` via `ch-cloud-readonly.env`. SELECT 1 OK. SHOW DATABASES: clarity+zod. **cloud_ch_writes=false**.
- Window: Jul 2026 UTC org=0 (Q2 locked). Tip `3c1744ba`.
- Counts: M0 / A / B all **deduped=11975 db_repos=9159** (Δ0). Agreement A+B **100%** (9159/9159).
- Bytes: M0 6.32 GB · A 1.89 GB (−70%) · B 6.67 GB (**+5.6% > M0**). Memory: M0 ~226 MB · A ~20 MB · B ~215 MB.
- m1 stale_status3_ids=**12** → A fails stale fixture. B fails read_bytes ≤ baseline.
- **Decision: KEEP dual-join** (CDC/FINAL/stale). Collapse not taken. Docs filled; no git/Jira/GitHub.

## 2026-10-06 21:35 CT — Gate 1 EXIT formal decide KEEP (Implement)

- Formal OWNED decide (no Cloud re-measure): **KEEP dual-join**. FINAL n/a.
- Cite: M0 db_repos 9159 / deduped 11975 / read_bytes 6,315,077,036; A 100% + m1 stale_status3_ids=12; B 100% but read_bytes 6,666,238,834 > M0.
- Product: class docblock only in `LprRepossessionsQuery.php` (SQL byte-identical). Optional `CompanyMetricsCacheScopeTest` DEV-2801 pin.
- Artifacts: `cloud-parity-jul-2026.md` FINAL + `gate1_owner_exit`; decisions.json status=final.
- Next: Review (Sam) via PM. No collapse. No baton from this step beyond hand-off.

## 2026-10-06 21:56 CT — Agent QA PASS (real Docker CH)

- Tip `720a549a` · suites 137/137 (649) · 0 skips · clickhouse @ :18135 · Soft #27 N/A · fleet LEFT UP · Next Verify.
