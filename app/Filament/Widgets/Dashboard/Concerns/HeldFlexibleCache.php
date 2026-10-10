<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * DEV-2463. Extracted for DEV-2464, the second of three users named in
 * UniqueLprCaseClaims's docblock (DEV-2465 is the third) -- the cold-path herd guard
 * around Cache::flexible() and the two seams it depends on.
 *
 * The COLD_BLOCK_SECONDS/COLD_LOCK_SECONDS/REFRESH_LOCK_SECONDS windows are NOT here.
 * They are calibrated per query (a card's own measured contended p99), so each using
 * class supplies them through the abstract accessors below rather than the trait
 * hoisting a shared number.
 */
trait HeldFlexibleCache
{
    /**
     * Cold-path herd guard. Cache::flexible locks only its stale-refresh branch
     * (Repository::flexible() line ~642); a TOTAL miss is a bare
     * `tap(value($callback), ...)` with no lock, so a deploy or cache:clear admits one
     * full scan per entitled org AND per concurrent tab.
     *
     * coldBlockSeconds() must exceed the query's measured contended p99 or every waiter
     * times out and computes anyway -- which is how the first attempt at this guard made
     * things worse rather than better. coldLockSeconds() must exceed
     * CLICKHOUSE_TIMEOUT_QUERY (60s) or a slow holder loses the mutex mid-query. The
     * refresh-lock expiry is handed to Cache::flexible so its OWN refresh lock expires:
     * it defaults to `$lock['seconds'] ?? 0` (no expiry), so a worker killed mid-refresh
     * would otherwise wedge the key until the hard TTL.
     *
     * Deliberately reads the cache FIRST and locks ONLY on a total miss. An earlier
     * version of this guard gated on a cached failure marker before the read and threw --
     * so one transient blip took the whole ten-card dashboard down for 60s even once a
     * fresh value was cached. The ordering here is the point: a guard must never be able
     * to mask a value that is present.
     *
     * A waiter that acquires the lock after the holder released finds the value already
     * cached, so the second flexible() call is a hit and issues no query. On timeout it
     * computes anyway -- correctness over herd strictness.
     */
    protected function heldFlexible(string $key, array $ttl, \Closure $compute): mixed
    {
        $lockConfig = $this->flexibleRefreshLock();

        // Warm path: no lock is taken at all, so the guard costs nothing and cannot mask a hit.
        //
        // BOTH keys, not just $key. Cache::flexible's own miss test is
        // `in_array(null, [$value, $created], true)` over $key AND
        // illuminate:cache:flexible:created:{$key} (Repository::flexible()). Testing only
        // $key meant that if the companion key were missing -- the two carry no hash tag, so
        // on the prod Redis cluster they land in different slots and can be evicted
        // independently, and putMany() is not atomic across slots -- this took the warm path,
        // skipped the lock, and let flexible() do a full unserialised scan. That is exactly
        // the state the guard exists for.
        if (cache()->has($key) && cache()->has(static::flexibleCreatedKey($key))) {
            return Cache::flexible($key, $ttl, $compute, $lockConfig);
        }

        $lock = cache()->lock($key.':cold', $this->coldLockSeconds());
        $acquired = false;

        try {
            $acquired = $lock->block($this->coldBlockSeconds());
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        try {
            return Cache::flexible($key, $ttl, $compute, $lockConfig);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /**
     * The expiry handed to Cache::flexible for its OWN deferred refresh lock. Without it,
     * flexible() falls back to `$lock['seconds'] ?? 0` -- never expires -- so a worker killed
     * mid-refresh wedges `illuminate:cache:flexible:lock:{key}` until the hard TTL.
     *
     * CORRECTION (review round 14, lane 1). This docblock used to claim the seam made dropping
     * the lock config "observable". That was FALSE, and the extraction made the false claim
     * canonical for every user of this trait. heldFlexible() calls this method unconditionally
     * and only afterwards passes the result to Cache::flexible, so a double that records this
     * method's return proves the seam was CALLED -- not that its value reached the consumer.
     * Deleting the 4th argument from both Cache::flexible calls in heldFlexible() left such a
     * test green. What actually pins it is recording the argument Cache::flexible itself
     * receives; see LprRepossessionsTest's lock-config test. The sibling
     * UniqueLprCaseClaimsHerdGuardTest still carries the old, blind assertion.
     *
     * @return array{seconds: int}
     */
    protected function flexibleRefreshLock(): array
    {
        return ['seconds' => $this->refreshLockSeconds()];
    }

    /**
     * The companion key Cache::flexible writes alongside the value; its absence is half of
     * flexible()'s own miss test, so the guard has to consider it too.
     */
    protected static function flexibleCreatedKey(string $key): string
    {
        return 'illuminate:cache:flexible:created:'.$key;
    }

    /**
     * The block window handed to the cold lock. Ideally exceeds the using query's measured
     * contended p99 -- see heldFlexible()'s docblock for why undershooting makes the guard
     * counterproductive.
     *
     * Stated as an ideal rather than a MUST because one shipped consumer cannot satisfy it, not
     * because the bar is wrong. Be precise about which, since round 15 first wrote "neither" here
     * and that was false:
     *
     *   UniqueLprCaseClaims  12s vs a measured contended p99 of 9.3s  -- SATISFIES it, and
     *                        UniqueLprCaseClaimsHerdGuardTest asserts exactly that bound.
     *   LprRepossessions     12s vs a 33.7s observed tail             -- does NOT, because it caps
     *                        the TOTAL block across its two guarded legs at the proxy's request
     *                        budget (kamal-proxy's 30s default); its constant's docblock argues
     *                        why raising it is worse.
     *
     * So a third consumer should treat this as a real bar and check its own measured p99 against
     * it, rather than reading a blanket "both violate it" as licence. The remedy where the bar
     * cannot be met is to stop computing synchronously on a total miss -- out-of-band warming --
     * not a larger number here.
     */
    abstract protected function coldBlockSeconds(): int;

    /**
     * The cold lock's own TTL. Must exceed the ClickHouse query timeout, or a slow holder
     * loses the mutex mid-query and a waiter starts a second copy.
     */
    abstract protected function coldLockSeconds(): int;

    /**
     * The expiry for Cache::flexible's own refresh lock, surfaced through
     * flexibleRefreshLock().
     */
    abstract protected function refreshLockSeconds(): int;
}
