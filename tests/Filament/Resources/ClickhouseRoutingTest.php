<?php

namespace Tests\Filament\Resources;

use App\Enums\FeatureEnum;
use App\Filament\Resources\ScansResource\Pages\ListScans;
use App\Support\Hits\HitsListQuery;
use Illuminate\Support\Facades\Config;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DEV-2591 / DEV-2614 — the Postgres/ClickHouse routing decision for SCANS and HITS.
 *
 * Both used to read Feature::active('clickhouse:scans|hits'), literals no Feature::define()
 * can ever match: the defined names are the FeatureEnum slugs, and Str::slug() cannot
 * emit a colon. The paths were unreachable outside impersonation.
 */
class ClickhouseRoutingTest extends TestCase
{
    private function routes(object $page): bool
    {
        $m = new ReflectionMethod($page, 'shouldUseClickhouse');
        $m->setAccessible(true);

        return $m->invoke($page);
    }

    #[Group('clickhouse')]
    public function test_the_old_literals_match_no_defined_feature(): void
    {
        $this->assertNotContains('clickhouse:scans', FeatureEnum::slugs());
        $this->assertNotContains('clickhouse:hits', FeatureEnum::slugs());
        $this->assertEquals('clickhouse-scans', FeatureEnum::CLICKHOUSE_SCANS->slug());
    }

    #[Group('clickhouse')]
    public function test_scans_route_to_clickhouse_when_the_config_is_on(): void
    {
        Config::set('app.experimental.clickhouse.scans', true);
        $this->actingAs($this->createUser());

        $this->assertTrue($this->routes(new ListScans));
    }

    #[Group('clickhouse')]
    public function test_scans_stay_on_postgres_when_the_config_is_off(): void
    {
        Config::set('app.experimental.clickhouse.scans', false);
        $this->actingAs($this->createUser());

        $this->assertFalse($this->routes(new ListScans));
    }

    #[Group('clickhouse')]
    public function test_routing_does_not_depend_on_the_pro_tier(): void
    {
        // The routing decision is where the query runs, not a sold feature. A Pro org
        // must not get a different path from a non-Pro org.
        Config::set('app.experimental.clickhouse.scans', true);

        $plain = $this->createUser();
        $this->actingAs($plain);
        $this->assertTrue($this->routes(new ListScans));

        $pro = $this->createUser();
        $this->makeUserPro($pro);
        $this->actingAs($pro);
        $this->assertTrue($this->routes(new ListScans));
    }

    #[Group('clickhouse')]
    public function test_admin_impersonation_still_reaches_clickhouse_with_the_config_off(): void
    {
        Config::set('app.experimental.clickhouse.scans', false);
        $this->actingAs($this->createUser());
        session(['admin_impersonator' => 1]);

        $this->assertTrue($this->routes(new ListScans));
    }

    #[Group('clickhouse')]
    public function test_pennant_is_no_longer_consulted_for_routing(): void
    {
        Config::set('app.experimental.clickhouse.scans', true);
        $this->actingAs($this->createUser());

        // Pennant's database store persists resolved values, so a stored false would
        // otherwise outlive a config flip until Feature::purge() ran.
        Feature::for(auth()->user())->deactivate(FeatureEnum::CLICKHOUSE_SCANS->slug());

        $this->assertTrue($this->routes(new ListScans));
    }

    #[Group('clickhouse')]
    public function test_hits_route_to_clickhouse_when_the_config_is_on(): void
    {
        Config::set('app.experimental.clickhouse.hits', true);
        $this->actingAs($this->createUser());

        $this->assertTrue(HitsListQuery::shouldUseClickhouse());
    }

    #[Group('clickhouse')]
    public function test_hits_stay_on_postgres_when_the_config_is_off(): void
    {
        Config::set('app.experimental.clickhouse.hits', false);
        $this->actingAs($this->createUser());

        $this->assertFalse(HitsListQuery::shouldUseClickhouse());
    }

    #[Group('clickhouse')]
    public function test_hits_routing_does_not_depend_on_the_pro_tier(): void
    {
        Config::set('app.experimental.clickhouse.hits', true);

        $plain = $this->createUser();
        $this->actingAs($plain);
        $this->assertTrue(HitsListQuery::shouldUseClickhouse());

        $pro = $this->createUser();
        $this->makeUserPro($pro);
        $this->actingAs($pro);
        $this->assertTrue(HitsListQuery::shouldUseClickhouse());
    }

    #[Group('clickhouse')]
    public function test_hits_admin_impersonation_reaches_clickhouse_with_the_config_off(): void
    {
        Config::set('app.experimental.clickhouse.hits', false);
        $this->actingAs($this->createUser());
        session(['admin_impersonator' => 1]);

        $this->assertTrue(HitsListQuery::shouldUseClickhouse());
    }

    #[Group('clickhouse')]
    public function test_hits_routing_ignores_the_pennant_clickhouse_hits_feature(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Feature::for($user)->deactivate(FeatureEnum::CLICKHOUSE_HITS->slug());

        Config::set('app.experimental.clickhouse.hits', true);
        $this->assertTrue(HitsListQuery::shouldUseClickhouse());

        Config::set('app.experimental.clickhouse.hits', false);
        Feature::for($user)->activate(FeatureEnum::CLICKHOUSE_HITS->slug());
        $this->assertFalse(HitsListQuery::shouldUseClickhouse());
    }
}
