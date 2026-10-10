<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\UniqueLprCaseClaims;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * DEV-2463, review round 2 -> 3. Pins the cold-path herd guard on getMonthlyData().
 *
 * Review round 2 found that every guard added in that round was untestable-by-construction
 * and could be deleted with the suite still green. These tests exist so that cannot recur.
 *
 * NOTE ON THE CLOCK: Illuminate\Cache\Lock::block() measures its timeout with now(), which
 * honours Carbon::setTestNow(). A frozen clock plus a genuinely contended lock would spin
 * forever instead of throwing LockTimeoutException. These tests therefore never freeze the
 * clock while contending the lock.
 */
final class UniqueLprCaseClaimsHerdGuardTest extends TestCase
{
    /**
     * The warm path must take NO lock. This is the ordering that matters: an earlier guard
     * gated before the cache read and could mask a value that was present.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_a_warm_cache_is_served_without_taking_the_cold_lock(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new CountingHerdWidget;
        $widget->date = 'this_month';
        $this->invokeMonthly($widget);
        $this->assertSame(1, $widget->calls, 'the first resolve must compute');

        // Hold the cold lock for the SAME key an outside process would use. A guard that
        // locked before reading would now block or fall through; this one must not care.
        $key = $this->monthlyKey($widget);
        $lock = Cache::lock($key.':cold', 90);
        $this->assertTrue($lock->get(), 'precondition: the cold lock is free');

        try {
            $fresh = new CountingHerdWidget;
            $fresh->date = 'this_month';
            $started = microtime(true);
            $value = $this->invokeMonthly($fresh);
            $elapsed = microtime(true) - $started;

            $this->assertSame(0, $fresh->calls, 'a warm read must not compute');
            $this->assertCount(13, $value);
            $this->assertLessThan(
                2.0,
                $elapsed,
                'a warm read must not block on the cold lock (it should never take it)'
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * On a total miss the guard must serialise: the waiter reads what the holder wrote
     * rather than issuing its own ~40M-row scan.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_a_waiter_reads_the_holders_value_instead_of_recomputing(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $holder = new CountingHerdWidget;
        $holder->date = 'this_month';
        $this->invokeMonthly($holder);
        $this->assertSame(1, $holder->calls);

        // A second widget on the same warm key must not compute again -- the cache-backed
        // (not per-instance) contract the guard has to preserve.
        $waiter = new CountingHerdWidget;
        $waiter->date = 'this_month';
        $this->invokeMonthly($waiter);

        $this->assertSame(0, $waiter->calls, 'the waiter must read the cache, not re-scan');
    }

    /**
     * The block window must exceed the query's measured contended p99 (9.3s), and the lock
     * TTL must exceed CLICKHOUSE_TIMEOUT_QUERY. Getting these backwards is precisely how the
     * first attempt at this guard failed to bind.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_guard_windows_are_sized_against_the_measured_p99_and_query_timeout(): void
    {
        $r = new \ReflectionClass(UniqueLprCaseClaims::class);

        $block = $r->getConstant('COLD_BLOCK_SECONDS');
        $lockTtl = $r->getConstant('COLD_LOCK_SECONDS');
        $refresh = $r->getConstant('REFRESH_LOCK_SECONDS');

        $this->assertGreaterThan(
            9.3,
            $block,
            'COLD_BLOCK_SECONDS must exceed the measured contended p99 or every waiter times out and computes anyway'
        );
        $this->assertGreaterThan(
            (int) config('database.connections.clarity_clickhouse.timeout_query', 60),
            $lockTtl,
            'COLD_LOCK_SECONDS must exceed the query timeout or a slow holder loses the mutex mid-query'
        );
        $this->assertGreaterThan(
            0,
            $refresh,
            "Cache::flexible's refresh lock defaults to no expiry; a killed worker would wedge the key"
        );
    }

    /**
     * Proves the guard EXISTS, not merely that it is well-ordered. Without this, deleting
     * heldFlexible() and reverting to a bare Cache::flexible() left every other test in this
     * file green (verified by mutation).
     *
     * Mechanism: the compute closure tries to take the same cold lock. If the guard is
     * holding it, that attempt fails; if the guard is gone, the lock is free.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_the_cold_compute_actually_runs_while_holding_the_cold_lock(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new LockProbingHerdWidget;
        $widget->date = 'this_month';
        $widget->coldLockKey = $this->monthlyKey($widget).':cold';

        $this->invokeMonthly($widget);

        $this->assertSame(1, $widget->calls, 'precondition: the cold path computed');
        $this->assertFalse(
            $widget->lockWasFreeDuringCompute,
            'the cold compute must run while HOLDING the cold lock -- a free lock means the herd guard is absent'
        );
    }

    /**
     * REGRESSION (review round 3, lane 3 M2). Cache::flexible's own miss test spans TWO keys
     * -- the value and a companion `created` timestamp. The guard originally tested only the
     * value, so with the companion missing it took the warm path, skipped the lock, and let
     * flexible() run a full unserialised ~40M-row scan: precisely the state the guard exists
     * for. The two keys carry no hash tag, so on a Redis cluster they can be evicted
     * independently.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_a_half_populated_flexible_key_is_treated_as_a_miss_and_takes_the_lock(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        // Same subclass for both reads: buildWidgetCacheKey() derives its namespace from
        // class_basename(static::class), so two different doubles address DIFFERENT keys.
        $warm = new LockProbingHerdWidget;
        $warm->date = 'this_month';
        $warm->coldLockKey = $this->monthlyKey($warm).':cold';
        $this->invokeMonthly($warm);
        $this->assertSame(1, $warm->calls, 'precondition: warmed');

        $key = $this->monthlyKey($warm);
        $this->assertTrue(cache()->has($key), 'precondition: the value key is present');

        // Evict ONLY the companion key, as an independent-slot eviction would.
        cache()->forget('illuminate:cache:flexible:created:'.$key);

        $probe = new LockProbingHerdWidget;
        $probe->date = 'this_month';
        $probe->coldLockKey = $key.':cold';
        $this->invokeMonthly($probe);

        $this->assertSame(1, $probe->calls, 'a half-populated key must recompute -- flexible() treats it as a miss');
        $this->assertFalse(
            $probe->lockWasFreeDuringCompute,
            'that recompute must be SERIALISED: testing only the value key skipped the lock entirely'
        );
    }

    /**
     * REGRESSION (review round 3, lane 3 M1). The sizing test asserts the CONSTANT is > 0,
     * which does not prove it reaches Cache::flexible -- dropping the 4th argument survived
     * the whole suite. flexible() then falls back to `$lock['seconds'] ?? 0` = NO EXPIRY, so
     * a worker killed mid-refresh wedges that key's refresh for the full 24h TTL.
     *
     * Asserted through the flexibleRefreshLock() seam: if the 4th argument is dropped, the
     * seam is never consulted and this fails.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_the_refresh_lock_expiry_is_actually_handed_to_cache_flexible(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new LockConfigRecordingHerdWidget;
        $widget->date = 'this_month';
        $this->invokeMonthly($widget);

        $this->assertNotNull(
            $widget->lockConfigUsed,
            'Cache::flexible must be given a $lock argument; without it its refresh lock never expires'
        );
        $this->assertSame(
            UniqueLprCaseClaims::REFRESH_LOCK_SECONDS,
            $widget->lockConfigUsed['seconds'] ?? null,
            'the refresh lock must carry a positive expiry'
        );
    }

    private function invokeMonthly(UniqueLprCaseClaims $widget): array
    {
        $m = new \ReflectionMethod($widget, 'getMonthlyData');
        $m->setAccessible(true);

        return $m->invoke($widget, false);
    }

    private function monthlyKey(UniqueLprCaseClaims $widget): string
    {
        $m = new \ReflectionMethod($widget, 'buildWidgetCacheKey');
        $m->setAccessible(true);

        return $m->invoke($widget, 'monthly-series:'.now('UTC')->format('Y-m'), auth()->user()->org_id);
    }
}

/** Counts real computes so the guard's cache behaviour is observable without ClickHouse. */
class CountingHerdWidget extends UniqueLprCaseClaims
{
    public int $calls = 0;

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        $this->calls++;

        return [$through->clone()->utc()->format('Y-m-01') => ['all_claims' => 3, 'successful' => 3, 'deduped' => 2]];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return 0;
    }
}

/** Reports whether the cold lock was free at the moment the cold compute ran. */
class LockProbingHerdWidget extends UniqueLprCaseClaims
{
    public int $calls = 0;

    public string $coldLockKey = '';

    public bool $lockWasFreeDuringCompute = true;

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        $this->calls++;

        $probe = Cache::lock($this->coldLockKey, 5);
        $this->lockWasFreeDuringCompute = $probe->get();

        if ($this->lockWasFreeDuringCompute) {
            $probe->release();
        }

        return [$through->clone()->utc()->format('Y-m-01') => ['all_claims' => 3, 'successful' => 3, 'deduped' => 2]];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return 0;
    }
}

/** Records the lock config actually handed to Cache::flexible. */
class LockConfigRecordingHerdWidget extends UniqueLprCaseClaims
{
    public ?array $lockConfigUsed = null;

    protected function flexibleRefreshLock(): array
    {
        return $this->lockConfigUsed = parent::flexibleRefreshLock();
    }

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return [$through->clone()->utc()->format('Y-m-01') => ['all_claims' => 1, 'successful' => 1, 'deduped' => 1]];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return 0;
    }
}
