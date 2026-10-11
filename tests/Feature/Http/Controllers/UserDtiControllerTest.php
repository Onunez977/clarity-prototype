<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class UserDtiControllerTest extends TestCase
{
    #[Group('controllers')]
    public function test_can_set_users_dti(): void
    {
        $this->actingAs(
            $user = User::factory()->create([
                'dti' => null,
            ])
        );

        $this->assertNull($user->dti);

        $dti = [
            'locale' => fake()->locale(),
            'timeZone' => fake()->timezone(),
        ];

        $this->post(route('user.dti'), [
            'dti' => $dti,
        ])
            ->assertSuccessful();

        $user->refresh();

        $this->assertNotNull($user->dti);
        $this->assertEquals($dti, $user->dti);
    }

    #[Group('controllers')]
    public function test_cannot_set_user_dti_with_dti_in_request(): void
    {
        $this->actingAs(
            $user = User::factory()->create([
                'dti' => null,
            ])
        );

        $this->assertNull($user->dti);

        $this->post(route('user.dti'))
            ->assertSuccessful();

        $user->refresh();

        $this->assertNull($user->dti);
    }

    #[Group('controllers')]
    public function test_cannot_set_dti_of_unauthenticated_user(): void
    {
        $this->post(route('user.dti'))
            ->assertStatus(302);
    }
}
