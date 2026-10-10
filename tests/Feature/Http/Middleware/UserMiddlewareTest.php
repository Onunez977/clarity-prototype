<?php

namespace Tests\Feature\Http\Middleware;

use App\Filament\Client\Resources\ClientSearchScansResource\Pages\ListClientSearchScans;
use App\Http\Middleware\UserMiddleware;
use Illuminate\Support\Facades\Route;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class UserMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(UserMiddleware::class)->any('/billing', fn () => response(status: 200));
    }

    #[Group('middleware')]
    public function test_client_users_are_redirected(): void
    {
        $this->actingAs(
            $this->createClientPortalUser()
        );

        $this->get('/billing')
            ->assertRedirect(ListClientSearchScans::getUrl(panel: 'client'));
    }

    #[Group('middleware')]
    public function test_non_client_users_pass_through(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::USER->value)
        );

        $this->get('/billing')
            ->assertStatus(200);
    }

    #[Group('middleware')]
    public function test_admin_users_pass_through(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $this->get('/billing')
            ->assertStatus(200);
    }
}
