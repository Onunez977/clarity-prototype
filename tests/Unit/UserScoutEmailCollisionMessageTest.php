<?php

namespace Tests\Unit;

use App\Models\Org;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class UserScoutEmailCollisionMessageTest extends TestCase
{
    #[Group('clarity-scout')]
    public function test_in_org_trashed_collision_names_user_and_offers_restore(): void
    {
        $org = Org::factory()->create();
        $this->actingAs(User::factory()->forOrg($org)->create());

        $deleted = User::factory()->forOrg($org)->create([
            'name' => 'Deleted Driver',
            'email' => 'gone@example.com',
        ]);
        $deleted->delete();

        $message = User::scoutEmailCollisionMessage('gone@example.com', orgId: $org->id);

        $this->assertNotNull($message);
        $this->assertStringContainsString('Deleted Driver', $message);
        $this->assertStringContainsString('deleted', $message);
        $this->assertStringContainsString('Restore', $message);
    }

    #[Group('clarity-scout')]
    public function test_out_of_org_collision_stays_generic_without_foreign_name(): void
    {
        $orgA = Org::factory()->create();
        $orgB = Org::factory()->create();
        $this->actingAs(User::factory()->forOrg($orgA)->create());

        User::factory()->forOrg($orgB)->create([
            'name' => 'Foreign Secret',
            'email' => 'shared@example.com',
        ]);

        $message = User::scoutEmailCollisionMessage('shared@example.com', orgId: $orgA->id);

        $this->assertSame('This email address is already in use.', $message);
        $this->assertStringNotContainsString('Foreign Secret', $message);
    }

    #[Group('clarity-scout')]
    public function test_self_ignore_returns_null(): void
    {
        $org = Org::factory()->create();
        $this->actingAs(User::factory()->forOrg($org)->create());

        $user = User::factory()->forOrg($org)->create(['email' => 'self@example.com']);

        $this->assertNull(User::scoutEmailCollisionMessage('self@example.com', ignoreUserId: $user->id, orgId: $org->id));
    }

    #[Group('clarity-scout')]
    public function test_users_resource_copy_omits_scout_guidance(): void
    {
        $org = Org::factory()->create();
        $this->actingAs(User::factory()->forOrg($org)->create());

        User::factory()->forOrg($org)->create([
            'name' => 'Existing',
            'email' => 'taken@example.com',
            'email_verified_at' => now(),
        ]);

        $message = User::scoutEmailCollisionMessage('taken@example.com', orgId: $org->id, scoutContext: false);

        $this->assertNotNull($message);
        $this->assertStringContainsString('Existing', $message);
        $this->assertStringNotContainsString('Scout Users', $message);
    }
}
