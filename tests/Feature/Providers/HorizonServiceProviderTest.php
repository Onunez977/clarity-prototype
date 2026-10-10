<?php

namespace Tests\Feature\Providers;

use App\Horizon\WorkCommand;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HorizonServiceProviderTest extends TestCase
{
    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_can_pass_horizon_gate(): void
    {
        $user = User::factory()->create([
            'email' => 'therock@insightlpr.com',
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('viewHorizon'));
    }

    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_can_pass_horizon_by_impersonator_gate(): void
    {
        session()->put('admin_impersonator', true);

        $this->assertTrue(Gate::forUser(User::factory()->make())->allows('viewHorizon'));
    }

    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_fails_horizon_gate(): void
    {
        $this->assertFalse(Gate::forUser(User::factory()->make())->allows('viewHorizon'));
    }

    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_horizon_is_not_swallowed_by_the_web_catch_all(): void
    {
        $response = $this->get('/horizon');

        $this->assertFalse($response->isRedirect(), 'GET /horizon must not hit the web catch-all');
        $response->assertForbidden();
    }

    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_horizon_is_registered_before_the_web_catch_all(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $horizonIndex = $routes->search(
            fn ($route): bool => $route->uri() === 'horizon' || str_starts_with((string) $route->uri(), 'horizon/')
        );
        $catchAllIndex = $routes->search(
            fn ($route): bool => $route->uri() === '{any}' && in_array('GET', $route->methods(), true)
        );

        $this->assertNotFalse($horizonIndex);
        $this->assertNotFalse($catchAllIndex);
        $this->assertLessThan(
            $catchAllIndex,
            $horizonIndex,
            'GET /horizon must be registered before the web {any} catch-all',
        );
    }

    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_authenticated_preview_users_can_open_horizon(): void
    {
        config(['horizon.preview' => true]);

        $this->actingAs($this->createUser())
            ->get('/horizon')
            ->assertOk();
    }

    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_authenticated_non_staff_cannot_open_horizon_outside_preview(): void
    {
        config(['horizon.preview' => false]);

        $this->actingAs($this->createUser())
            ->get('/horizon')
            ->assertForbidden();
    }

    #[Group('horizon')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_horizon_work_command_defines_stop_when_empty_for(): void
    {
        $command = $this->app->make(HorizonWorkCommand::class);

        $this->assertInstanceOf(WorkCommand::class, $command);
        $this->assertTrue($command->getDefinition()->hasOption('stop-when-empty-for'));
    }
}
