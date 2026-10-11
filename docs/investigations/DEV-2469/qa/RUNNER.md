# DEV-2469 isolated-cloud runner

Apply `docs/investigations/DEV-2469/qa-ch-seed.sql` to the isolated ClickHouse
(`clarity` + `zod` databases) **before** Playwright
`tests/e2e/unique-scans-to-lpr-repossession.spec.ts`. Use
`clickhouse-client --multiquery` (or send each statement separately); the HTTP
interface rejects multi-statement bodies.

Do not run the file-scope without that seed if Case B must check the seeded ratio.

## Case B (required path)

1. Open Company Metrics (`/app`) as the Pro org-2 login persona (Test Company).
2. Select **This Month** explicitly and wait for Livewire to settle.
3. Compact Unique Scans to LPR Repossession is a one-decimal **ratio** with no
   `%` suffix. With the seed applied (org 2, September 2026 window) that value
   is **5.0** (`uniqExact(ocr)` 40 / `db_repos` 8).
4. Last Month is **0.0** — a valid zero-denominator reading (scenario H).

Do not invent `E2E_*` env for the ratio. If This Month is a well-formed ratio
other than 5.0, the spec fails with a seed hint pointing at this file.

## Env (Clarity isolated runner)

- Isolated hosts only: `127.0.0.1` / `*.test`. Never `*.insightlpr.io`.
- `APP_ENV=local` and `DEV_LOGIN_ENABLED=true` for the Pro QA login path.
- Pro: `test@tester.com` (org 2). `loginAsAdmin` may use QA login or OTP.
- Non-Pro (scenario F): `E2E_NON_PRO_EMAIL` + `E2E_OTP` (or a log-backed OTP).
  Use `loginAsNonProOrgAdmin` only — do not use `GET /__qa/login/{id}` (that
  plants `admin_impersonator` and bypasses the Pro gate).
