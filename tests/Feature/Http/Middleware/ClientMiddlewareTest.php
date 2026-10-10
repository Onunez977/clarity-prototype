<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\ClientMiddleware;
use Illuminate\Support\Facades\Route;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ClientMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(ClientMiddleware::class)->any('/billing', fn () => response(status: 200));
    }

    #[Group('middleware')]
    public function test_users_with_client_panel_access_pass(): void
    {
        $this->actingAs(
            $this->createClientPortalUser()
        );

        $this->get('/billing')
            ->assertStatus(200);
    }

    #[Group('middleware')]
    public function test_redirect_on_non_authenticated_users(): void
    {
        $this->get('/billing')
            ->assertStatus(302);
    }

    #[Group('middleware')]
    public function test_redirect_on_non_client_users(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::USER->value)
        );

        $this->get('/billing')
            ->assertStatus(302);
    }

    #[Group('middleware')]
    public function test_users_with_impersonator_session_pass(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::USER->value)
        );

        session()->put('impersonator', ['id' => 1, 'name' => 'Admin User']);

        $this->get('/billing')
            ->assertStatus(200);
    }
}
