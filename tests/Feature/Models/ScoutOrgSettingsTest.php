<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\ScoutMapLayerEnum;
use App\Models\Org;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ScoutOrgSettingsTest extends TestCase
{
    #[Group('clarity-scout')]
    public function test_missing_row_enables_every_layer(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $user = User::factory()->create();

        $this->assertEqualsCanonicalizing(
            ['hits', 'hotlist', 'staging', 'teammates'],
            ScoutOrgSettings::enabledLayersFor($user)
        );
    }

    #[Group('clarity-scout')]
    public function test_stored_false_hides_that_layer(): void
    {
        $user = User::factory()->create();
        ScoutOrgSettings::factory()->create([
            'org_id' => $user->org_id,
            'map_layers' => ScoutOrgSettings::mapFromEnabled(['hits', 'staging']),
        ]);

        $this->assertEqualsCanonicalizing(
            ['hits', 'staging'],
            ScoutOrgSettings::enabledLayersFor($user)
        );
        $this->assertFalse(ScoutOrgSettings::isLayerEnabled($user, ScoutMapLayerEnum::Hotlist));
    }

    #[Group('clarity-scout')]
    public function test_teammates_hidden_when_global_flag_off_unless_impersonating(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);

        $user = User::factory()->create();

        $this->assertNotContains('teammates', ScoutOrgSettings::enabledLayersFor($user));
        $this->assertContains('teammates', ScoutOrgSettings::enabledLayersFor($user, isImpersonating: true));
    }

    #[Group('clarity-scout')]
    public function test_org_off_hides_teammates_even_for_impersonators(): void
    {
        $user = User::factory()->create();
        ScoutOrgSettings::factory()->layers(['hits', 'hotlist', 'staging'])->create([
            'org_id' => $user->org_id,
        ]);

        $this->assertFalse(ScoutOrgSettings::isLayerEnabled($user, ScoutMapLayerEnum::Teammates, isImpersonating: true));
    }

    #[Group('clarity-scout')]
    public function test_stored_enabled_layers_ignore_the_global_flag(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);

        $org = Org::factory()->create();

        $this->assertContains('teammates', ScoutOrgSettings::storedEnabledLayers($org->id));
    }

    #[Group('clarity-scout')]
    public function test_org_has_scout_org_settings_relationship(): void
    {
        $org = Org::factory()->create();
        $settings = ScoutOrgSettings::factory()->create([
            'org_id' => $org->id,
        ]);

        $this->assertTrue($org->scoutOrgSettings->is($settings));
    }
}
