<?php

namespace Tests\Feature\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GateServiceProviderTest extends TestCase
{
    #[Group('gate')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_can_pass_admin_gate(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        $this->assertTrue(Gate::forUser($user)->allows('admin'));
    }

    #[Group('gate')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_fails_admin_gate(): void
    {
        $user = $this->createUser(role: RoleEnum::USER->value);

        $this->assertFalse(Gate::forUser($user)->allows('admin'));
    }

    #[Group('gate')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_can_pass_insightlpr_gate(): void
    {
        $user = User::factory()->create([
            'email' => 'dwaynejohnson@insightlpr.com',
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('insight-lpr'));
    }

    #[Group('gate')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_can_pass_insightlpr_by_impersonator_gate(): void
    {
        $user = User::factory()->make();
        session()->put('impersonator', true);

        $this->assertTrue(Gate::forUser($user)->allows('insight-lpr'));
    }

    #[Group('gate')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_can_bypass_insightlpr_gate_locally(): void
    {
        Config::set('app.env', 'local');

        $this->assertTrue(Gate::forUser(User::factory()->make())->allows('insight-lpr'));
    }

    #[Group('gate')]
    #[Group('provider')]
    #[Group('providers')]
    public function test_fails_insightlpr_gate(): void
    {
        $user = User::factory()->make();

        $this->assertFalse(Gate::forUser($user)->allows('insight-lpr'));
    }
}
