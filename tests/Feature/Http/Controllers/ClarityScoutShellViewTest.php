<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The Scout SPA shell view (DEV-1964): browser geolocation is removed, so the
 * client config object no longer injects the liveGpsEnabled flag.
 */
class ClarityScoutShellViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
    }

    #[Group('clarity-scout')]
    public function test_shell_renders_client_config_without_live_gps_flag(): void
    {
        $response = $this->get('/scout');

        $response->assertOk();
        $response->assertSee('googleMapsApiKey', false);
        $response->assertDontSee('debugMapEnabled', false);
        $response->assertDontSee('liveGpsEnabled', false);
    }
}
