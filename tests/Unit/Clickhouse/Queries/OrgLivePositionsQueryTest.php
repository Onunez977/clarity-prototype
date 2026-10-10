<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\OrgLivePositionsQuery;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class OrgLivePositionsQueryTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    /**
     * The default cutoff is compared against the UTC `timestamp_utc` column, so
     * it must be built in UTC even when the app runs in a non-UTC timezone. This
     * forces a non-UTC default timezone so `now()` and `now()->utc()` render on
     * different clock strings — the pre-fix `now()->subHours(24)` emits the app-tz
     * string, so asserting the UTC string genuinely fails before the fix.
     */
    #[Group('clickhouse')]
    #[Group('scout')]
    public function test_default_cutoff_is_built_in_utc_regardless_of_app_timezone(): void
    {
        config(['app.timezone' => 'America/Chicago']);
        date_default_timezone_set('America/Chicago');

        // 20:00 CDT == 01:00 UTC the next calendar day (UTC-5 in July).
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 20, 0, 0, 'America/Chicago'));

        $cutoff = self::callDefaultCutoff();

        // UTC floor: (2026-07-09 01:00:00 UTC) - 24h.
        $this->assertSame('2026-07-08 01:00:00', $cutoff);

        // Pre-fix `now()->subHours(24)` under the app tz would emit this string.
        $this->assertNotSame('2026-07-07 20:00:00', $cutoff);
    }

    private static function callDefaultCutoff(): string
    {
        $method = new ReflectionMethod(OrgLivePositionsQuery::class, 'defaultCutoff');
        $method->setAccessible(true);

        return (string) $method->invoke(null);
    }
}
