# DEV-2414 vs DEV-1756 — the conflict, the measured options, and the parked decision

**Status: PARKED by the operator (2026-08-25).** Merge DEV-2414 unchanged; settle this once AC-4's
post-deploy per-kit number is known. **Do not silently resolve it in the queued okf/ wiki pass.**

## The collision

Three claims cannot all hold at a co-located Direct+Staging geo:

| source | claim |
|---|---|
| DEV-1756 (`okf/concepts/check-in.md:39`) | *"Every match should be tracked regardless of assignment type"* |
| DEV-2414 AC-2 | Direct-wins → **one** row per geo |
| DEV-2414 AC-5 | the `check-in-counts` tile *"stays consistent with recorded check-ins"* |

At a shared geo the tile reports `staging_bucket = 1` while the recorder writes **zero** staging
rows. And `okf/concepts/check-in.md:46` states the DEV-1756 running count is satisfied **by
derivation** — `COUNT(*) FROM platform.scout_check_ins WHERE address_id = ?` — which returns **0
forever** for any non-elected co-located address.

**This predates DEV-2414.** The old code already did Direct-wins for one `address_id` carried on
both a Direct and a Staging hotlist (`argMin(hotlist_id, (toUInt8(is_staged), hotlist_id))`), and
`test_shared_address_is_direct_wins_on_matcher_and_counted_in_both_rollup_buckets` exists precisely
to assert the divergence "rather than paper over" it. DEV-2414 **widens** it from same-address to
same-geo, which is what makes it start to matter.

**Currently latent:** nothing in `app/` reads `platform.scout_check_ins` back — the only reference is
the recorder's `insertOrIgnore`. The `check-in-counts` tile is `ScoutCheckInRollupQuery`, the
ClickHouse scan-sourced path, which preserves both buckets (AC-6). So this is a contract break
waiting for its first consumer, not a live bug.

## Measured options (production, read-only, 14 kits, one 15-min window each)

| | rows | reduction vs today |
|---|---|---|
| OLD grain (pre-change) | 186 | — |
| **Shipped** — `PARTITION BY (geo, hour)` | 87 | 2.14x |
| **Variant** — `PARTITION BY (geo, hour, is_staged)` | 92 | 2.02x |

**The variant costs +5 rows (+5.7%)** and recovers exactly the shared-geo Staging check-ins the
shipped version drops. It also makes the matcher **structurally identical to the rollup**, which
would let the parity test assert agreement instead of asserting divergence. One line, no migration
— the `(kit_id, address_id, hour_bucket)` index handles two distinct `address_id`s fine.

Per-kit detail was kept local-only; the totals above are the committed record.

### The three resolutions, for whoever picks this up

1. **Per-bucket election** — the one-line change above. Satisfies all three claims. Reverses AC-2's
   explicit *"Direct precedence is retained deliberately"* decision, so it needs to be recorded as a
   deliberate reversal, not a bug fix.
2. **Amend DEV-1756's derivation** — make the per-address count geo-aware by joining back through
   the book to find co-located siblings. Derivable **today with no schema change**, but the book is a
   live 15-minute snapshot, so historical geo groupings drift as orders close. Leaves the
   matcher/rollup divergence in place and requires rewriting `check-in.md:46`.
3. **Store all rows + a canonical flag** — both semantics preserved exactly (location-visits =
   `count where canonical`; address-coverage = `count by address_id`). Most correct, needs a
   migration, therefore its own ticket.

## AC-4 estimate — wider than first reported

Two independent production samples of the same measurement disagree meaningfully:

| sample | kits | fan-out removed | implied per-kit (from the 2.07 baseline) |
|---|---|---|---|
| A | 19 | 1.54x | ~1.35 |
| B | 14 | 2.14x | ~0.97 |

So the honest prediction is a **range of ~0.97–1.35 per kit**, not the single ~1.35 first reported —
the ~1.0 target is plausibly **met**. The per-kit-cap follow-up is worth queueing but is no longer a
foregone conclusion. The post-deploy measurement is the decider, and it is also the trigger for the
decision parked above.

---

## RESOLVED WITH FULL PRODUCTION CARDINALITY (2026-08-25)

The parked decision was waiting on data. The data is now in — measured over the **entire book**
for all 12 Scout-active orgs, each inside its own `hotlistOrgFilter` view (Direct = own org,
Staging = its approval-list partners), not a per-kit bbox sample.

| | |
|---|---|
| address rows in scope | **13,918,976** |
| distinct geos | **9,136,653** → fleet fan-out **1.52x** |
| co-located geos (n>1) | **2,714,036** — **29.7%** of geos |
| **MIXED geos (Direct + Staging at one coordinate)** | **24,378** — **0.27%** of geos |

Per-org detail was kept local-only; the range across the 12 orgs was 0.01%–0.78%.

### 1. The variant is essentially free

`variant / shipped = (9,136,653 + 24,378) / 9,136,653 = 1.0027` → **+0.27%**, not the +5.7% the
14-kit bbox sample suggested. That sample over-stated the cost ~21x.

### 2. The shipped version silently zeroes 24,378 locations

At each mixed geo the Staging address loses to the Direct one and receives **no row, ever** — so
DEV-1756's derived count (`COUNT(*) WHERE address_id = ?`) reads **0 forever**, meaning "the driver
never went there". Across the 12 Scout-active orgs that is **24,378 locations** whose staging-side
coverage is unrecoverable from the row (no geo, no sibling list stored).

### 3. Correcting the AC-4 estimate — again, and this time with the population figure

The fleet-wide book fan-out is **1.52x**, which matches production sample A (**1.54x**) almost
exactly. Sample B's 2.14x was the outlier, not the correction.

| source | fan-out | implied per-kit from the 2.07 baseline |
|---|---|---|
| sample A (19 kits, bbox) | 1.54x | ~1.35 |
| sample B (14 kits, bbox) | 2.14x | ~0.97 |
| **full book, 12 orgs** | **1.52x** | **~1.36** |

So the original ~1.35 estimate was right and the "0.97–1.35, target plausibly met" revision was
wrong — it over-weighted one noisy 14-kit sample. **The ~1.0 target is likely missed at ~1.36/kit**,
and the per-kit-cap follow-up is warranted after all. (Caveat: book fan-out is uniform over the
book while check-ins are weighted by where kits actually drive — but 1.52 vs 1.54 from two
independent methods is strong convergence.)

### What this means for the decision

Both numbers the park was waiting on now point the same way: the conflict costs **+0.27%** to fix
and **24,378 zeroed locations** to leave. The volume outcome (~1.36/kit) is unaffected either way —
0.27% does not move it.

---

## DECISION CHANGED (2026-08-25, after the data above)

The park was lifted. With mixed geos measured at **0.27%** and **24,378** staging locations facing a
permanent `0`, the operator chose the per-bucket election. **Shipped:** the matcher's `ROW_NUMBER`
partition is now `(addr_lng, addr_lat, hour_bucket, out_is_staged)` ordered by `address_id ASC`.
This supersedes "PARKED" above; that section is retained as the record of how the decision was reached.

**It is a PARTIAL fix, and the limit is precise.** `min(is_staged)` in `address_hour` runs BEFORE the
bucket partition, so a single `address_id` carried on BOTH a Direct and a Staging hotlist still
collapses to one Direct row and never reaches the staging bucket — its staging coverage is still
lost. Only genuinely DISTINCT co-located address_ids are recovered. Consequently the matcher grain is
still NOT identical to the rollup, and
`test_shared_address_is_direct_wins_on_matcher_and_counted_in_both_rollup_buckets` still asserts that
surviving divergence. An earlier draft of the matcher docblock claimed otherwise; corrected.

Closing the remaining case would mean not collapsing `is_staged` in `address_hour` — i.e. grouping by
`(address_id, geo, hour, is_staged)` — which changes the pre-existing per-address Direct-wins that
predates DEV-2414 entirely. Out of scope here; folds into the same follow-up as options 2 and 3.

---

## CORRECTION (2026-08-25) — the "fleet-wide" figures above are view-sums, not distinct counts

Review round 8 caught a statistical error in the section above. The per-org measurements were
**summed across 12 Scout-active orgs whose filtered views overlap heavily** — staging partners 165,
851 and 6523 appear in nearly every approval list, so those addresses were counted once per agent
org. The totals presented as fleet-wide are therefore inflated:

| quoted above | actual |
|---|---|
| "13,918,976 address rows in scope" | sum over overlapping views — the **whole book is 4,516,420** |
| "9,136,653 distinct geos" | sum over overlapping views — the book has **2,364,058** distinct geos |
| "fleet fan-out 1.52x" | that is the **view-weighted** figure; the book's true fan-out is **1.91x** |

**What survives the correction:** the **0.27% ratio**, which is what the decision actually rested
on. Both its numerator and denominator are summed over the same views, so it is a view-size-weighted
average of per-org ratios (range 0.01%–0.78%) — and since check-ins are generated per kit *inside one
org's view*, that is the correct basis for "how many extra rows does the variant write". The
per-bucket decision stands.

**What does NOT survive:** the AC-4 conclusion drawn from 1.52x.

## AC-4 — stating the range and stopping there

I have now revised this estimate three times, each time on a partial view, and each revision was
stated with more confidence than the evidence carried. The full set:

| basis | fan-out | implied per-kit from the 2.07 baseline |
|---|---|---|
| whole book, distinct | 1.91x | **~1.08** |
| view-weighted sum (12 orgs) | 1.52x | ~1.36 |
| bbox sample A (19 kits) | 1.54x | ~1.35 |
| bbox sample B (14 kits) | 2.14x | ~0.97 |

**Honest conclusion: ~1.0–1.4 per kit, and no basis available here distinguishes further.** None of
these is bbox-and-traffic-weighted the way real check-ins are. Whether the ~1.0 target is met is
genuinely open, and the post-deploy measurement is the only thing that settles it — which is exactly
what the operator said when parking it. No further point estimate should be quoted from this
analysis.

---

## RESIDUAL MEASURED AT PRODUCTION CARDINALITY — it is currently EMPTY (2026-08-25)

The partial-fix limit described above (one `address_id` carried on BOTH a Direct and a Staging
hotlist collapses to Direct before the bucket partition, so its staging coverage is lost) was
measured across all 12 Scout-active orgs' filtered views:

**0 of 68,966 Direct addresses.** Zero instances, every org.

Two consequences:

1. **The DEV-1756 hole is closed completely for the data as it actually stands** — not merely for
   the 0.27% of mixed geos. The real-world case is always *distinct* co-located address_ids, which
   the per-bucket election recovers in full.
2. **The ticket's original AC-2 was written around a case with no instances.**
   `test_shared_address_is_direct_wins_on_matcher_and_counted_in_both_rollup_buckets` — the test the
   ticket cited as the reason Direct-wins had to be retained — exercises a shape that does not occur
   in the current book.

**Zero today is not zero forever.** Nothing enforces it, the book rebuilds every 15 minutes, and one
order sharing an address across a Direct/Staging pair would create an instance. The divergence stays
documented and the test stays. But it is a low-priority follow-up, not a live gap.

## Follow-up tickets raised (2026-08-25, Sprint 39)

* **DEV-2426** (Bug) — make the uniqueness key geo-aware so a re-run cannot write a second row for
  one visit. This is the remaining over-count and the likely reason any post-deploy figure sits
  above ~1.0.
* **DEV-2427** (Task, chlocal) — exclude geocoder-fallback coordinates from
  `scout_open_addresses_mv`. See `cluster-investigation.md`.
