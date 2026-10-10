# DEV-2464 isolated-cloud runner

Apply `docs/investigations/DEV-2464/qa/ch-seed.sql` to `clarity_clickhouse` (ClickHouse `zod` database) **before** Playwright `tests/e2e/lpr-repossessions.spec.ts`. Use `clickhouse-client --multiquery` (or send each statement separately); the HTTP interface rejects multi-statement bodies.

Do not FREEZE/GO without that seed applied.

## Case B (required path)

1. Open Company Metrics (`/app`) as the Pro org-2 login persona (Test Company).
2. Stay on the default **This Month** window (select it explicitly if needed).
3. Compact LPR Repossessions (`db_repos`) is **1** (greater than 0).
4. Sparkline is visible.

Case B must pass on This Month. Do not rely on toggling Last Month. A last-month-only seed left the compact at 0 on every UI window even when in-process Last Month read 1.

Today is left empty so scenario H can still find a zero window.

## Env

- `E2E_LPR_REPOSSESSIONS_ZERO_WINDOW` — scenario H. After this seed use `today`.
- `E2E_NON_PRO_EMAIL` + `E2E_OTP` — scenario F (OTP only; do not use the QA login bypass).
