# DEV-2739 — Ultra-Plan (authoritative, Path A retarget)

**Hits Distance / Nearest Given — all N/A under admin impersonation (Org 14)**

| | |
|---|---|
| Gate | **CLEAN** |
| Phase | **provisioned** · Next **Implement** |
| PR | [#469](https://github.com/insight-lpr/platform/pull/469) |
| Branch | `cursor/dev-2739-hits-distance-na-prep-439d` @ `9f0a6cae76430aca9e0d4329de842eea0f2467e1` |
| Base | `main` @ `e09b222e15b912bd59542e800e5e0171c08d82eb` |
| First Glance | comment **41873** |

## Product lock (2026-09-29 — Dalton via Gandalf; Galadriel Board reopen)

**Path A:** Given = **any geocoded hotlist address** on the order (`hotlist_address` ⋈ `address` with `geo`). **No `type_id` filter.** Not `recovery_address` / `type_id=2` only.

Oscar: `type_id=2` is repo, wrong for Hits page. Glorfindel: only `2` + `NULL` proven in the wild.

Prior Q3 “defer Path A vs B / keep Path B” is **superseded** by this lock.

## Root class (measured)

Demo uses admin impersonation → ClickHouse list → blades → `NearestGivenAddressController` → **`clarity.recovery_address` (type_id=2 MV)**. Cohort geocoded addresses are almost all **`type_id IS NULL`**, so Path B returns empty → N/A while Path A would return a row (~81% B-miss·A-hit across 5 orgs). Packets: `measure.md`, `measure-q3-contrast.md`, `measure-path-b-gap.md`.

## Approach (#24)

Smallest product change: retarget **`NearestGivenAddressController`** to the Path A Postgres source (all geocoded linked addresses, no `type_id`). Keep feature/impersonator gate and zero-scan short-circuit. Leave `ListHits::buildPostgresQuery` Path A as-is (already matches the lock). **Do not** widen `recovery_address_mv`.

## Files / steps / done-signal

See `plan.json`. Canon still `okf/concepts/hit-address-distance.md` — wiki update after approval if WRITE rule blocks in-flight.

## Hard stops

- No Cloud ClickHouse writes.
- No inventing additional address `type_id` values.
- No Hits resource rewrite / DEV-1611 reopen.
- N/A stays correct when Path A has no geocoded address or scan geo is missing/zero.

## Next

**Provision (Path A)** after Galadriel LIVE fleet ledger. Then Frodo Implement.

## Provision (Path A LIVE)

Ledger: `docs/investigations/DEV-2739/qa-prep-ledger.json` (2026-09-29 08:21 CT)

| | |
|---|---|
| Site | http://127.0.0.1:18089 |
| PG | :15441 · `zod_dev_2739` |
| CH | :18132 · `clarity_dev_2739` (Docker) |
| Checkout | `/workspace/dev-2739-wt` @ `b0a89abdf714f882c9dc3ae316ada6acc53351f9` |
| cloud_touched | false |
| Sister | DEV-2601 left intact on :18088/:15440/:18131 |

Ready for Implement.
