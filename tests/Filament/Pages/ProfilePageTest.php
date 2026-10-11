<?php

namespace Tests\Filament\Pages;

use App\Filament\Pages\Profile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    #[Group('profile')]
    public function test_profile_page_renders_successfully(): void
    {
        $this->actingAs(
            $this->createUser()
        );

        Livewire::test(Profile::class)
            ->assertSuccessful();
    }

    #[Group('profile')]
    public function test_profile_form_loads_user_data(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '555-123-4567'])
        );

        Livewire::test(Profile::class)
            ->assertFormSet([
                'name' => $user->name,
                'mobile_phone' => '5551234567',
            ]);
    }

    #[Group('profile')]
    public function test_user_can_update_profile_information(): void
    {
        $this->actingAs(
            $user = $this->createUser()
        );

        $this->assertNotEquals('Jane Doe', $user->name);
        $this->assertNull($user->mobile_phone);

        Livewire::test(Profile::class)
            ->fillForm([
                'name' => 'Jane Doe',
                'mobile_phone' => '5559876543',
            ])
            ->call('save')
            ->assertNotified();

        $user->refresh();

        $this->assertEquals('Jane Doe', $user->name);
        $this->assertEquals('5559876543', $user->mobile_phone);
    }
}
