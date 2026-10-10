<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\TestingWorkflowMiddleware;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class TestingWorkflowMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(TestingWorkflowMiddleware::class)->any('/billing', fn () => response(status: 200));
    }

    #[Group('middleware')]
    public function test_cannot_access_in_production(): void
    {
        Config::set('app.env', 'production');

        $this->get('/billing')
            ->assertForbidden();
    }
}
