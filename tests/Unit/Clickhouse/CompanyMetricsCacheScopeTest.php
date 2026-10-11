<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Filament\Widgets\Dashboard\UniqueLprCaseClaims;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class CompanyMetricsCacheScopeTest extends TestCase
{
    #[Group('clickhouse')]
    public function test_claims_query_has_no_shared_cache_key_and_ac5_is_per_class(): void
    {
        $claims = file_get_contents(app_path('Clickhouse/Queries/UniqueLprCaseClaimsQuery.php'));
        $scans = file_get_contents(app_path('Clickhouse/Queries/ScanPlateStatsBatchQuery.php'));
        $repossessions = file_get_contents(app_path('Clickhouse/Queries/LprRepossessionsQuery.php'));

        $this->assertDoesNotMatchRegularExpression('/function shared/', $claims);
        $this->assertStringContainsString('DEV-2659', $claims);
        $this->assertStringContainsString('billing_claim_events_v', $claims);

        $this->assertStringNotContainsString('DEV-2655', $scans);
        $this->assertStringContainsString('60,738,996', $scans);
        $this->assertStringContainsString('scans_cold', $scans);

        $this->assertStringContainsString('billing_claim_events_v', $repossessions);
        $this->assertStringContainsString('first_repossessed_at', $repossessions);
        $this->assertStringContainsString('DEV-2660', $repossessions);
        $this->assertStringContainsString('DEV-2801', $repossessions);

        $widget = new UniqueLprCaseClaims;
        $key = (new ReflectionMethod($widget, 'buildWidgetCacheKey'))->invoke(
            $widget,
            'monthly-series:2026-08',
            42
        );

        $this->assertStringStartsWith('unique-lpr-case-claims:', $key);
    }
}
