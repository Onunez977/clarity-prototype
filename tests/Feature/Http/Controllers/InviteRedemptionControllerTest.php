<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InviteTypeEnum;
use App\Models\ClientOrgAccess;
use App\Models\Invite;
use App\Models\Org;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class InviteRedemptionControllerTest extends TestCase
{
    #[Group('invites')]
    public function test_show_renders(): void
    {
        $this->get(route('client.invite.redeem.show'))
            ->assertOk()
            ->assertSee('Redeem Your Client Invite');
    }

    #[Group('invites')]
    public function test_show_prefills_email_and_code_from_query_string(): void
    {
        $response = $this->get(route('client.invite.redeem.show', [
            'email' => 'jane@example.com',
            'code' => 'ABC123',
        ]))->assertOk();

        $response->assertSee('jane@example.com', false);
        $response->assertSee('ABC123', false);
    }

    #[Group('invites')]
    public function test_redeem_creates_user_grants_access_and_logs_in(): void
    {
        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        $invite = Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
                'contact_person' => 'Jane Client',
                'contact_phone' => '555-1234',
            ],
            'approved_at' => now(),
            'expires_at' => now()->addDay(),
        ])->create();

        $response = $this->post(route('client.invite.redeem'), [
            'email' => 'newclient@example.com',
            'code' => 'ABC123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('filament.notifications');

        $user = User::query()->where('email', 'newclient@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($clientOrg->id, $user->org_id);

        setPermissionsTeamId($user->org_id);
        $this->assertTrue($user->fresh()->hasRole(RoleEnum::CLIENT->value));

        $this->assertNotNull($invite->fresh()->accepted_at);

        $this->assertEquals(
            1,
            ClientOrgAccess::query()
                ->where('client_org_id', $clientOrg->id)
                ->where('repo_org_id', $repoOrg->id)
                ->count()
        );

        $this->assertAuthenticatedAs($user);
    }

    #[Group('invites')]
    public function test_redeem_rejects_invalid_code(): void
    {
        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
            ],
            'approved_at' => now(),
            'expires_at' => now()->addDay(),
        ])->create();

        $this->from(route('client.invite.redeem.show'))
            ->post(route('client.invite.redeem'), [
                'email' => 'newclient@example.com',
                'code' => 'WRONG1',
            ])
            ->assertRedirect(route('client.invite.redeem.show'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'newclient@example.com')->first());
    }

    #[Group('invites')]
    public function test_redeem_rejects_unapproved_invite(): void
    {
        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
            ],
            'approved_at' => null,
            'expires_at' => now()->addDay(),
        ])->create();

        $this->from(route('client.invite.redeem.show'))
            ->post(route('client.invite.redeem'), [
                'email' => 'newclient@example.com',
                'code' => 'ABC123',
            ])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'newclient@example.com')->first());
    }

    #[Group('invites')]
    public function test_redeem_rejects_expired_invite(): void
    {
        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
            ],
            'approved_at' => now()->subHour(),
            'expires_at' => now()->subHour(),
        ])->create();

        $this->from(route('client.invite.redeem.show'))
            ->post(route('client.invite.redeem'), [
                'email' => 'newclient@example.com',
                'code' => 'ABC123',
            ])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    #[Group('invites')]
    public function test_redeem_rejects_mismatched_email(): void
    {
        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
            ],
            'approved_at' => now(),
            'expires_at' => now()->addDay(),
        ])->create();

        $this->from(route('client.invite.redeem.show'))
            ->post(route('client.invite.redeem'), [
                'email' => 'someoneelse@example.com',
                'code' => 'ABC123',
            ])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'someoneelse@example.com')->first());
    }

    #[Group('invites')]
    public function test_redeem_rejects_already_accepted_invite(): void
    {
        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
            ],
            'approved_at' => now()->subDay(),
            'accepted_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ])->create();

        $this->from(route('client.invite.redeem.show'))
            ->post(route('client.invite.redeem'), [
                'email' => 'newclient@example.com',
                'code' => 'ABC123',
            ])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    #[Group('invites')]
    public function test_redeem_rejects_invite_with_deleted_client_org(): void
    {
        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        $invite = Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
            ],
            'approved_at' => now(),
            'expires_at' => now()->addDay(),
        ])->create();

        $clientOrg->delete();

        $this->from(route('client.invite.redeem.show'))
            ->post(route('client.invite.redeem'), [
                'email' => 'newclient@example.com',
                'code' => 'ABC123',
            ])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'newclient@example.com')->first());
        $this->assertNull($invite->fresh()->accepted_at);
    }
}
