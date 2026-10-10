# INCIDENT REPORT — production host memory exhaustion + reboot, 2026-08-12 \~21:17 CDT

> **Revised 2026-08-13 after an evidence audit.** Five claims in the first version were wrong — most importantly, the host **fully rebooted** (it did not shed processes in place), and Scout's memory footprint was **\~29 GB, not \~52 GB**. Corrections and their evidence are listed at the bottom. Root cause, mechanism and the fix plan are unchanged.

**Severity:** production host exhausted memory and was rebooted to restore service. `scout:record-check-ins` has been disabled since and remains disabled.

**Evidence basis:** Datadog infrastructure metrics (Datadog Agent, independent of the app's log pipeline) plus the prod Laravel log. Memory, uptime and swap figures are measured.

## Impact

* Production host `i-069f64d6355f75f01` (`env:prod`, 67.9 GB RAM) ran out of usable memory — down to **1.88% usable**.
* **The host was rebooted by Andrew Jorgenson** to restore service, ending a 483-day uptime. It was **dark for \~16 minutes** (\~02:17 → 02:33 UTC).
* The scheduler was degraded beyond the reboot itself: the 02:30 UTC `CloseInactiveShifts` tick never fired, and the scheduler is only demonstrably back at 03:00:33 UTC.
* `scout:record-check-ins` was disabled by commit `11bc23e2` at 21:52:56 CDT (\~35 min after the reboot), which wrapped its schedule block in a comment. Scout check-in recording has been down since.
* **No swap is configured** on this host (`system.swap.used` flat at 0 for the entire period), so there was no reclaim buffer behind physical memory.

## Timeline (UTC)

| Time | Event |
| --- | --- |
| Aug 10 → Aug 12 \~12:00 | `pct_usable` flat at \~44% for two full days (\~38 GB steady footprint). Stable baseline, no drift. |
| Aug 12 \~12:58 | DEV-2313 deploy — `scout:record-check-ins` goes live |
| Aug 12 13:20:02 | First observed cycle start. **Memory begins a monotonic decline from this point.** |
| Aug 12 18:07 | 15.8% usable |
| Aug 12 19:21 | 9.4% usable |
| Aug 12 20:11:24 | Deploy `a2eaf384`. Usable _rises_ briefly (process restart), then resumes declining |
| Aug 12 23:01 | 7.9% usable |
| Aug 13 00:30 | Last scheduler tick that produced a completed cycle |
| Aug 13 01:56:57 | Final `completed` line ever emitted (`recorded: 4`) |
| Aug 13 02:16:17 | Last log line of any kind |
| Aug 13 02:17:00 | Last Datadog sample before the gap — **65.0 GB used / 1.88% usable** |
| Aug 13 02:17 → 02:33 | **HOST DARK \~16 min** — no agent samples, no cron. Rebooted by Andrew Jorgenson. |
| Aug 13 02:30 | `CloseInactiveShifts` tick **missed** — independent confirmation the host was down |
| Aug 13 02:33:04 | Boot complete — `system.uptime` resets **483 days → 20 seconds** |
| Aug 13 02:52:56 | Schedule commented out (`11bc23e2`) |
| Aug 13 03:00:33 | Scheduler demonstrably back |

Usable memory oscillated between roughly 6% and 15% for the final seven hours, repeatedly dipping below 10%, before the terminal 1.88%.

## Root cause

**Attribution comes from the two-day pre-Scout baseline, not from the post-reboot state.**

| Period | Footprint | Basis |
| --- | --- | --- |
| Aug 10 → Aug 12 (pre-Scout) | **\~38 GB** (\~44% usable, flat) | 2 days, no drift |
| At the crash | **\~67 GB** (1.88% usable) | last sample before the gap |
| **Scout's marginal footprint** | **\~29 GB** | difference |

That \~29 GB accumulated in the **\~13 hours** between Scout going live and the reboot, and the box only had \~30 GB of headroom — which is why 13 hours was enough.

Across a measured peak of **17 concurrent cycles**, that is roughly **1.5–3 GB per process**, for a job handling 43 kits across 12 orgs.

**Mechanism.** The command is scheduled `everyFiveMinutes()` while cycles run 63–209 minutes. `withoutOverlapping(10)` sets a 10-minute lock **expiry**, not a duration cap. Each spawn re-arms the mutex, so alternate ticks are skipped and a **new process starts roughly every 10 minutes** — not on every tick. That caps concurrency at about `longest cycle ÷ 10` ≈ 21, and the measured peak of 17 sits just under it, which is a useful self-consistency check. Between 00:30 and 02:17 UTC roughly **10–11 processes started and none completed**.

**Why memory scales without bound.** Per-process memory is `O(addresses_per_org)`:

* `$addresses` — the full org address list, held alive across the entire 43-kit loop
* `array_chunk($addresses, 2000)` — PHP **copies the whole array** to chunk it, once **per kit**
* `$matches[]` — accumulates every match for the kit, no streaming
* caching does not help: **every process materializes its own copy into process memory**, regardless of driver

Total host memory is therefore `O(addresses_per_org × concurrent_processes)`, with concurrency itself unbounded.

**Evidence that concurrency was real:** across all 248 `ClickHouse error 159` entries, **58 inter-arrival gaps are under 30 seconds**. A single process cannot emit two 30-second timeouts less than 30 seconds apart, so ≥2 processes were running simultaneously.

## What the deploy did not do

Deploy `a2eaf384` (20:11 UTC) did not cause this:

* The memory decline begins at **Scout go-live (\~13:00 UTC)**, over seven hours earlier, and is monotonic from there.
* Usable memory briefly _improved_ immediately after the deploy (process restart), then resumed declining.
* The address cache TTL was checked specifically as a suspect — halving it would double enumeration load. It was 15 minutes before and after; the deploy only moved it into config.

## Corrections from the 2026-08-13 audit

1. **"\~58.6 GB released in a single ≤30-second interval — a mass process kill"** — **wrong.** Datadog's raw CSV omits null samples; the original reading did index arithmetic across a \~31-sample gap. End-anchoring the series shows a **\~16-minute agent-dark window**, and `system.uptime` resetting 483 days → 20s proves a **full reboot**. There is no evidence the kernel OOM killer ever fired.
2. **"\~52 GB held by the recorder"** — **wrong.** That compared Scout-running against the _post-reboot_ baseline, but the reboot also cleared 483 days of accumulated residency, so the comparison was confounded. Against the two-day pre-Scout baseline the marginal footprint is **\~29 GB**. (The attribution is _stronger_ this way: a flat 2-day control followed by monotonic decline starting at go-live beats a before/after spanning a reboot.)
3. **"Every subsequent 5-minute tick spawns another process"** — **\~2× overstated.** Each spawn re-arms the 10-minute mutex, so spawns occur roughly every 10 minutes. "\~28 processes started and none completed" should read **\~10–11**.
4. **"Six timeouts in three pairs \~30s apart, consistent with concurrency"** — **unsound as stated.** A single process emits sequential org timeouts _exactly_ 30s apart. The claim is now supported by the **58 sub-30s gaps** cited above.
5. **"Below 10% usable for roughly seven hours"** — **overstated.** It oscillated 6–15% across those hours with repeated dips below 10%. Note also that `system.mem.used` includes reclaimable page cache, so `pct_usable` is the honest series for the intermediate points.

## Action items

1. **Scout stays disabled** until per-cycle memory is bounded and a concurrency cap exists. Re-enabling as-is reproduces this.
2. **Fix the root cause** — see the ticket description's W1–W4.
3. **Cap concurrent processes.** A serialized recorder that runs is strictly better than a fast one that takes the host down.
4. **Close the QA gap.** This passed testing and took production down within \~13 hours of go-live. Production data volume and multi-cycle concurrent execution were never exercised. Tracked separately.
5. **Consider swap and a memory alert.** With no swap and no host-memory alerting, the only signal was a human noticing. Usable memory sat under 15% for seven hours with nothing paging anyone.

## Why testing did not catch it

The failure requires volume, elapsed time, and concurrency simultaneously. No test or QA stage exercises any of the three.

| Dimension | Test | Production |
| --- | --- | --- |
| Orgs | 1 | 12 |
| Kits | 1–2 | 43 |
| Addresses | 3 | \~255k for the largest org |
| Concurrent processes | 1 (phpunit forces sync) | \~17 |
| Runtime | milliseconds | 63–209 min |

Verified absences: no `memory_get_peak_usage` / `memory_limit` assertion anywhere in the test suite; no production-scale or peak-memory guidance in `.claude/` or `okf/`; concurrency is structurally untestable under phpunit's `sync` driver.

The one test touching address volume (`the_matcher_merges_results_across_address_chunks`) is a good test — it uses 3 addresses with `chunk_size=1` and even notes `prod chunk = 2000`. It correctly validates chunk-merge correctness and says nothing about cost at production cardinality.

This ticket also received unusually rigorous adversarial review (three lanes, multiple rounds, 62 flagged locations). Every lane graded correctness and design. None asked what the code allocates at production cardinality or how many copies run concurrently.

## Open questions

* Which allocation dominates the \~1.5–3 GB per process — the per-org address array, ClickHouse result sets, or growth across the cycle lifetime.
* Whether the kernel OOM killer fired at all before the reboot (needs `kern.log` / `journalctl` from before 02:33 UTC, if retained across the reboot).

## Host identification

`i-069f64d6355f75f01`, matched by `env:prod`, being the only one of 46 monitored hosts to exhaust memory in the window, crash timing consistent with the reported \~21:17 CDT, and the 483-day uptime reset. Datadog carries no `name`/`service`/`role` tag on it, so the instance ID has not been directly confirmed against `10.100.3.142`.
