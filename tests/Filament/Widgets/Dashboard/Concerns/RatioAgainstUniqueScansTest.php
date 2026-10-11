<?php

namespace Tests\Filament\Widgets\Dashboard\Concerns;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\RatioAgainstUniqueScans;
use App\Filament\Widgets\Dashboard\UniqueScanCounts;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * DEV-2651 t1. Pins RatioAgainstUniqueScans' numerator seam over a local stub card and
 * Counting- and Throwing-style ScanPlateStatsBatchQuery seams -- never real ClickHouse, since
 * the local CH is deliberately down in this environment.
 */
class RatioAgainstUniqueScansTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Pin 1. The month keys are the CLASS-NEUTRAL ScanPlateStatsBatchQuery keys, never
     * DashboardWidget::buildWidgetCacheKey() (which prefixes the calling widget's class
     * basename). Two DIFFERENTLY NAMED cards must therefore read one another's cached months --
     * mutate fetchUniqueScansSeries() to key on buildWidgetCacheKey() and this goes red, because
     * the sibling would then miss and re-query under its own class-prefixed key.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_month_series_cache_keys_carry_no_class_name_and_are_shared_across_cards(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $mine = new CountingRatioStubCard;
        $mine->scansResult = ['2026-08' => 11, '2026-07' => 22];
        $this->invoke($mine, 'fetchUniqueScansSeries', [$user->org_id, now('UTC'), 'UTC']);

        $sibling = new SiblingCountingRatioStubCard;
        $theirs = $this->invoke($sibling, 'fetchUniqueScansSeries', [$user->org_id, now('UTC'), 'UTC']);

        $this->assertSame(0, $sibling->scansCalls, 'a differently-named card must read the first card\'s entries, not re-query');
        $this->assertSame(22, $theirs['2026-07'], 'both cards must address one class-neutral set of keys');
        $this->assertSame(
            22,
            cache()->get(ScanPlateStatsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-07', 'UTC')),
            'the closed month must be written under the shared key ScanPlateStatsBatchQuery owns'
        );
    }

    /** Pin 2. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_a_thrown_numerator_batch_flags_unavailable_writes_no_month_keys_and_sets_the_backoff_marker(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new ThrowingRatioStubCard;

        $series = $this->invoke($widget, 'fetchUniqueScansSeries', [$user->org_id, now('UTC'), 'UTC']);

        $this->assertSame([], $series, 'a failed batch must write NO bucket, not a fabricated 0');
        $this->assertTrue($this->numeratorUnavailableFlag($widget));
        $this->assertNull(
            cache()->get(ScanPlateStatsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-07', 'UTC')),
            'a failed batch must leave the closed-month key EMPTY, never a fabricated 0 at a long TTL'
        );
        $this->assertNotNull(
            cache()->get(ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($user->org_id)),
            'the shared backoff marker must be set so the next reader does not re-hammer ClickHouse'
        );
    }

    /** Pin 3. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_an_existing_backoff_marker_suppresses_the_query_and_flags_unavailable_rather_than_zeros(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        cache()->put(ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($user->org_id), true, 60);

        $widget = new CountingRatioStubCard;
        $widget->scansResult = ['2026-08' => 11];

        $series = $this->invoke($widget, 'fetchUniqueScansSeries', [$user->org_id, now('UTC'), 'UTC']);

        $this->assertSame([], $series, 'a suppressed read must not fabricate a zero series');
        $this->assertSame(0, $widget->scansCalls, 'a set marker must suppress the query entirely');
        $this->assertTrue($this->numeratorUnavailableFlag($widget));
    }

    /** Pin 4. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_ratio_returns_zero_for_a_zero_denominator_and_a_real_quotient_otherwise(): void
    {
        $this->assertSame(0.0, $this->invokeStatic(RatioStubCard::class, 'ratio', [5000, 0]));
        $this->assertSame(2.5, $this->invokeStatic(RatioStubCard::class, 'ratio', [5, 2]));
    }

    /**
     * Pin 5, guest arm. Review-1 M3: this asserted only that the two gates AGREE, which for an
     * ungated host means it certified "guest => ratio disclosed" as correct. A disclosure gate
     * must be asserted by VALUE. canViewCardData() is deliberately true for a guest; the ratio
     * gate must still be false.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_can_view_ratio_figure_is_closed_for_a_guest_even_on_an_ungated_card(): void
    {
        $this->assertTrue(auth()->guest());
        $this->assertTrue(RatioStubCard::canViewCardData(), 'the card-level gate is open to a guest by design');
        $this->assertFalse(RatioStubCard::canViewRatioFigure(), 'the ratio is a disclosure gate and must be closed with no viewer');
    }

    /** Pin 5, gated arm -- proves the default is a real delegation, not a hardcoded true. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_can_view_ratio_figure_defaults_to_can_view_card_data_for_an_ungated_user(): void
    {
        $this->actingAs($this->createUser());

        $this->assertFalse(RatioStubCard::canViewCardData(), 'guard: a roleless user must fail canView() or this test proves nothing');
        $this->assertSame(RatioStubCard::canViewCardData(), RatioStubCard::canViewRatioFigure());
    }

    /** A card MAY override the seam independently of canViewCardData() -- the whole point of it. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_can_view_ratio_figure_is_a_genuine_override_seam(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $this->assertTrue(RatioStubCard::canViewCardData());
        $this->assertFalse(
            OverriddenRatioStubCard::canViewRatioFigure(),
            'an overriding card must not be forced back onto canViewCardData()'
        );
    }

    /** The window (today/last_7d) numerator must degrade the same way the series does. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_window_numerator_degrades_on_a_thrown_query(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new ThrowingWindowRatioStubCard;

        $scans = $this->invoke($widget, 'windowUniqueScans', [
            $user->org_id, now('UTC')->startOfDay(), now('UTC')->endOfDay(), 'UTC',
        ]);

        $this->assertSame(0, $scans);
        $this->assertTrue($this->numeratorUnavailableFlag($widget));
        $this->assertNotNull(cache()->get(ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($user->org_id)));
    }

    /** Same marker, same suppression, on the window leg. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_window_numerator_suppressed_by_an_existing_marker_returns_zero_and_flags_unavailable(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        cache()->put(ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($user->org_id), true, 60);

        $widget = new RecordingWindowRatioStubCard;

        $scans = $this->invoke($widget, 'windowUniqueScans', [
            $user->org_id, now('UTC')->startOfDay(), now('UTC')->endOfDay(), 'UTC',
        ]);

        $this->assertSame(0, $scans);
        $this->assertSame(0, $widget->rangeCalls, 'a set marker must suppress the range query entirely');
        $this->assertTrue($this->numeratorUnavailableFlag($widget));
    }

    /** The extraction must keep the existing "one batch, then cache" property. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_uncached_months_are_fetched_once_and_closed_months_are_not_requeried(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new CountingRatioStubCard;
        $widget->scansResult = ['2026-08' => 11, '2026-07' => 22];

        $first = $this->invoke($widget, 'fetchUniqueScansSeries', [$user->org_id, now('UTC'), 'UTC']);
        $this->assertSame(1, $widget->scansCalls, 'thirteen uncached months must be ONE batched call');
        $this->assertSame(11, $first['2026-08']);

        $this->invoke($widget, 'fetchUniqueScansSeries', [$user->org_id, now('UTC'), 'UTC']);
        $this->assertSame(1, $widget->scansCalls, 'a fully cached series must issue no query');

        cache()->forget(ScanPlateStatsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-08', 'UTC'));
        $second = $this->invoke($widget, 'fetchUniqueScansSeries', [$user->org_id, now('UTC'), 'UTC']);

        $this->assertSame(2, $widget->scansCalls);
        $this->assertSame('2026-08-01 00:00:00', $widget->scansStart, 'the refetch must span only the CURRENT month');
        $this->assertSame(22, $second['2026-07'], 'the closed month must still be served from cache');
    }

    /**
     * DEV-2652 t1. The default `dti` fallback is America/Chicago, and a real IANA id is kept.
     * No ClickHouse: this only reads the authenticated user's zone through safeTimezone().
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_numerator_zone_uses_the_chicago_fallback_and_keeps_a_real_iana_id(): void
    {
        $fallback = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($fallback);
        $this->assertSame('America/Chicago', $fallback->timezone);

        $this->assertSame('America/Chicago', $this->invoke(new RatioStubCard, 'numeratorZone', []));

        $newYork = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'America/New_York']],
            role: RoleEnum::ADMIN->value,
        );
        $this->actingAs($newYork);

        $this->assertSame('America/New_York', $this->invoke(new RatioStubCard, 'numeratorZone', []));
    }

    /**
     * CST, PST, and +05:00 construct a DateTimeZone and are still unlisted, so safeTimezone()
     * returns UTC. US/Central is kept when this PHP can construct it and otherwise becomes
     * America/Chicago. Do not re-derive that allowlist. The method must not throw.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeViewerZones(): array
    {
        return [
            'CST' => ['CST', 'UTC'],
            'PST' => ['PST', 'UTC'],
            '+05:00' => ['+05:00', 'UTC'],
            'US/Central' => ['US/Central', self::expectedLegacyTimezone('US/Central')],
        ];
    }

    /**
     * Alias when this PHP can construct it; otherwise the canonical IANA id.
     */
    private static function expectedLegacyTimezone(string $alias): string
    {
        $canonical = match ($alias) {
            'US/Central' => 'America/Chicago',
            'US/Eastern' => 'America/New_York',
            'US/Pacific' => 'America/Los_Angeles',
            'Asia/Calcutta' => 'Asia/Kolkata',
            default => throw new \InvalidArgumentException("Unknown legacy alias [{$alias}]"),
        };

        try {
            new \DateTimeZone($alias);

            return $alias;
        } catch (\DateInvalidTimeZoneException|\Exception) {
            return $canonical;
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    #[DataProvider('unsafeViewerZones')]
    public function test_numerator_zone_falls_back_to_utc_for_zones_outside_the_allowlist(string $zone, string $expected): void
    {
        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => $zone]],
            role: RoleEnum::ADMIN->value,
        );
        $this->actingAs($user);
        $this->assertSame($zone, $user->timezone, 'guard: the accessor must surface the raw dti zone');

        $this->assertSame($expected, ScanPlateStatsBatchQuery::safeTimezone($zone));
        $this->assertSame($expected, $this->invoke(new RatioStubCard, 'numeratorZone', []));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_ratio_reuses_the_open_month_y_m_int_without_a_second_scan(): void
    {
        $this->freezeUtc('2026-08-15 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $counts = new OpenMonthFillUniqueScanCounts;
        (new ReflectionMethod($counts, 'getMonthlyData'))->invoke($counts, false);

        $zone = ScanPlateStatsBatchQuery::safeTimezone((string) ($user->timezone ?? 'UTC'));
        $openMonth = now(tz: $zone)->format('Y-m');
        $key = ScanPlateStatsBatchQuery::sharedMonthCacheKey($user->org_id, $openMonth, $zone);

        $this->assertSame(42, cache()->get($key));
        $this->assertStringNotContainsString('UniqueScanCounts', $key);
        $this->assertStringNotContainsString('this_month', $key);
        $this->assertSame(1, $counts->handleCalls);
        $this->assertSame(1, $counts->rangeCalls);

        $ratio = new CountingRatioStubCard;
        $series = $this->invoke($ratio, 'fetchUniqueScansSeries', [$user->org_id, now(tz: $zone), $zone]);

        $this->assertSame(0, $ratio->scansCalls);
        $this->assertSame(42, $series[$openMonth]);
    }

    /**
     * The open-month shared int is the base ladder (index 0), not this card's getCacheTTL()
     * floor. UniqueLprCaseClaims raises a 10-second impersonation TTL to 60 seconds. UniqueScanCounts
     * skips handleRange when the Y-m key is already warm, so that floor would stick on the shared
     * scans int and leave the leaderboard range key cold.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_open_month_shared_key_uses_the_base_ladder_not_the_card_ttl_floor(): void
    {
        $this->freezeUtc('2026-08-15 12:00:00');
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $widget = new FlooredTtlRatioStubCard;
        $this->assertSame(
            now()->addSeconds(60)->getTimestamp(),
            $this->invoke($widget, 'getCacheTTL', [0])->getTimestamp(),
            'guard: the stub must still floor its own TTL at 60 seconds'
        );

        $this->invoke($widget, 'fetchUniqueScansSeries', [7, now('UTC'), 'UTC']);

        $openKey = ScanPlateStatsBatchQuery::sharedMonthCacheKey(7, '2026-08', 'UTC');
        $store = cache()->store()->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        $storage = (new ReflectionProperty($store, 'storage'))->getValue($store);

        $this->assertArrayHasKey($openKey, $storage);
        $this->assertSame(
            now()->addSeconds(10)->getTimestamp(),
            (int) $storage[$openKey]['expiresAt'],
            'the shared open-month int must expire on DashboardWidget index 0, not the 60-second floor'
        );
    }

    /** Closed months must honour the OWNING card's 10-minute floor, not this card's own. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('ratio-against-unique-scans')]
    public function test_closed_month_writes_honour_the_owning_cards_ten_minute_impersonation_floor(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $ttl = ScanPlateStatsBatchQuery::sharedClosedMonthTtl();

        $this->assertGreaterThanOrEqual(
            now()->addMinutes(UniqueScanCounts::IMPERSONATED_PAST_MONTH_TTL_MINUTES)->getTimestamp(),
            $ttl->getTimestamp(),
            'a closed-month bucket must not be written at this card\'s own short floor on a namespace '
            .'whose owner documents a 10-minute one'
        );
    }

    private function freezeUtc(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    /**
     * ReflectionProperty resolves a PRIVATE trait property against the DECLARING class, not the
     * test double's runtime subclass -- reflecting on the subclass name throws "does not exist"
     * even though the property is inherited and set. RatioStubCard is where the trait is used,
     * so every stub in this file resolves against it.
     */
    private function numeratorUnavailableFlag(object $widget): bool
    {
        $property = new ReflectionProperty(RatioStubCard::class, 'numeratorUnavailable');
        $property->setAccessible(true);

        return $property->getValue($widget);
    }

    private function invoke(object $widget, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($widget, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($widget, $args);
    }

    private function invokeStatic(string $class, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(null, $args);
    }
}

/** The minimal DashboardWidget host the trait is designed to be mixed into. */
class RatioStubCard extends DashboardWidget
{
    use RatioAgainstUniqueScans;

    public function getRgbColor(): array
    {
        return [0, 0, 0];
    }

    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return 0;
    }
}

class OverriddenRatioStubCard extends RatioStubCard
{
    public static function canViewRatioFigure(): bool
    {
        return false;
    }
}

class OpenMonthFillUniqueScanCounts extends UniqueScanCounts
{
    public int $handleCalls = 0;

    public int $rangeCalls = 0;

    protected function fetchMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->handleCalls++;

        return [];
    }

    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeCalls++;

        return ['totals' => [0 => 42], 'names' => []];
    }
}

/** Counts and records the batch seam, mirroring the repo's existing Recording- and Counting-style stubs. */
class CountingRatioStubCard extends RatioStubCard
{
    public int $scansCalls = 0;

    public ?string $scansStart = null;

    public ?string $scansEnd = null;

    public ?string $scansTimezone = null;

    /** @var array<string, int> */
    public array $scansResult = [];

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->scansCalls++;
        $this->scansStart = $start;
        $this->scansEnd = $end;
        $this->scansTimezone = $timezone;

        return $this->scansResult;
    }
}

/**
 * Mirrors UniqueLprCaseClaims::getCacheTTL(): the card's own keys are floored at 60 seconds.
 * The shared scans int must not inherit that floor.
 */
class FlooredTtlRatioStubCard extends CountingRatioStubCard
{
    protected function getCacheTTL($index = 0, ?\DateTimeInterface $monthEndedAt = null): \DateTimeInterface
    {
        $ttl = parent::getCacheTTL($index, $monthEndedAt);
        $floor = now()->addSeconds(60);

        return $ttl->getTimestamp() < $floor->getTimestamp() ? $floor : $ttl;
    }
}

/** A DIFFERENTLY NAMED class using the same trait -- see the shared-key test. */
class SiblingCountingRatioStubCard extends RatioStubCard
{
    public int $scansCalls = 0;

    /** @var array<string, int> */
    public array $scansResult = [];

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->scansCalls++;

        return $this->scansResult;
    }
}

class ThrowingRatioStubCard extends RatioStubCard
{
    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        throw new RuntimeException('clickhouse down');
    }
}

class ThrowingWindowRatioStubCard extends RatioStubCard
{
    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        throw new RuntimeException('clickhouse down');
    }
}

class RecordingWindowRatioStubCard extends RatioStubCard
{
    public int $rangeCalls = 0;

    /** @var array{totals: array<int, int>, names: array<int, string>} */
    public array $rangeResult = ['totals' => [0 => 0], 'names' => []];

    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeCalls++;

        return $this->rangeResult;
    }
}
