<?php

namespace Tests\Filament\Actions;

use App\Enums\InviteTypeEnum;
use App\Filament\Actions\InviteAction;
use App\Filament\Resources\InviteResource\Pages\ListInvites;
use App\Mail\Invites\NewInvite;
use App\Models\ClientOrgAccess;
use App\Models\Invite;
use App\Models\Org;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use InsightLPR\BlackAdam\Enum\OrgType;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class InviteActionTest extends TestCase
{
    #[Group('actions')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $action = InviteAction::make();

        $this->assertEquals('Send an Invite', $action->getLabel());
        $this->assertEquals('send-invite', $action->getName());
    }

    #[Group('actions')]
    public function test_is_not_visible_unless_pro_or_admin(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: false)
        );

        $action = InviteAction::make();
        $this->assertFalse($action->isVisible());

        $this->actingAs(
            $this->createUser(role: RoleEnum::CLIENT->value, pro: true)
        );

        $action = InviteAction::make();
        $this->assertFalse($action->isVisible());
    }

    #[Group('actions')]
    public function test_can_send_share_scans_invite(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        $recipient = User::factory()->create();

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::SHARE_SCANS->value,
                'to' => $recipient->email,
            ])
            ->assertHasNoFormErrors();

        $this->assertEquals(1, Invite::count());

        $invite = Invite::first();

        $this->assertEquals(InviteTypeEnum::SHARE_SCANS->value, $invite->type);
        $this->assertEquals($user->id, $invite->user_id);
        $this->assertEquals($recipient->id, $invite->invitable_id);
    }

    #[Group('actions')]
    public function test_can_send_share_scans_invite_with_email(): void
    {
        Mail::fake();

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        $recipient = User::factory()->create();

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::SHARE_SCANS->value,
                'to' => $recipient->email,
                'send_email' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertEquals(1, Invite::count());

        Mail::assertQueued(NewInvite::class);
    }

    #[Group('actions')]
    public function test_cannot_send_share_scans_invite_if_user_doesnt_exist(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::SHARE_SCANS->value,
                'to' => fake()->email(),
            ])
            ->assertHasFormErrors(['to' => 'A user with that email does not exist.']);

        $this->assertEquals(0, Invite::count());
    }

    #[Group('actions')]
    public function test_can_send_client_scans_invite_for_existing_client_org(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $clientOrg = Org::factory()->create([
            'type' => OrgType::Client->value,
            'rdn_id' => '12345',
            'rdn_api_key' => fake()->uuid(),
        ]);

        ClientOrgAccess::factory()->create([
            'client_org_id' => $clientOrg->id,
            'repo_org_id' => $user->org_id,
        ]);

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::CLIENT_SCANS->value,
                'to' => fake()->safeEmail(),
                'client_org_id' => $clientOrg->id,
                'contact_person' => fake()->name(),
                'contact_phone' => fake()->phoneNumber(),
            ])
            ->assertHasNoFormErrors();

        $invite = Invite::query()->latest('id')->first();

        $this->assertNotNull($invite);
        $this->assertEquals(InviteTypeEnum::CLIENT_SCANS->value, $invite->type);
        $this->assertEquals($user->id, $invite->user_id);
        $this->assertEquals('org', $invite->invitable_type);
        $this->assertEquals($clientOrg->id, $invite->invitable_id);
        $this->assertNull($invite->approved_at);
        $this->assertNull($invite->expires_at);
        $this->assertArrayHasKey('code', $invite->meta);
        $this->assertArrayHasKey('to', $invite->meta);
    }

    #[Group('actions')]
    public function test_cannot_send_client_scans_invite_when_existing_client_org_has_no_rdn(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $clientOrg = Org::factory()->create([
            'type' => OrgType::Client->value,
            'rdn_id' => null,
            'rdn_api_key' => fake()->uuid(),
        ]);

        ClientOrgAccess::factory()->create([
            'client_org_id' => $clientOrg->id,
            'repo_org_id' => $user->org_id,
        ]);

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::CLIENT_SCANS->value,
                'to' => fake()->safeEmail(),
                'client_org_id' => $clientOrg->id,
                'contact_person' => fake()->name(),
                'contact_phone' => fake()->phoneNumber(),
            ])
            ->assertHasFormErrors(['client_org_id']);

        $this->assertEquals(0, Invite::count());
    }

    #[Group('actions')]
    public function test_can_send_client_scans_invite_when_client_org_has_rdn_id_but_no_api_key(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $clientOrg = Org::factory()->create([
            'type' => OrgType::Client->value,
            'rdn_id' => '12345',
            'rdn_api_key' => null,
        ]);

        ClientOrgAccess::factory()->create([
            'client_org_id' => $clientOrg->id,
            'repo_org_id' => $user->org_id,
        ]);

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::CLIENT_SCANS->value,
                'to' => fake()->safeEmail(),
                'client_org_id' => $clientOrg->id,
                'contact_person' => fake()->name(),
                'contact_phone' => fake()->phoneNumber(),
            ])
            ->assertHasNoFormErrors();

        $invite = Invite::query()->latest('id')->first();

        $this->assertNotNull($invite);
        $this->assertEquals(InviteTypeEnum::CLIENT_SCANS->value, $invite->type);
        $this->assertEquals($clientOrg->id, $invite->invitable_id);
    }

    #[Group('actions')]
    public function test_can_send_client_scans_invite_creating_new_client_org(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        $clientName = 'Acme Repos LLC '.fake()->randomNumber(4);

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::CLIENT_SCANS->value,
                'to' => fake()->safeEmail(),
                'client_name' => $clientName,
                'contact_person' => fake()->name(),
                'contact_phone' => fake()->phoneNumber(),
            ])
            ->assertHasNoFormErrors();

        $clientOrg = Org::query()
            ->where('name', $clientName)
            ->where('type', OrgType::Client->value)
            ->first();

        $this->assertNotNull($clientOrg);

        $invite = Invite::query()->latest('id')->first();
        $this->assertEquals('org', $invite->invitable_type);
        $this->assertEquals($clientOrg->id, $invite->invitable_id);
        $this->assertNull($invite->approved_at);
    }

    #[Group('actions')]
    public function test_client_scans_invite_email_is_not_sent_at_creation(): void
    {
        Mail::fake();

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $clientOrg = Org::factory()->create([
            'type' => OrgType::Client->value,
            'rdn_id' => '12345',
            'rdn_api_key' => fake()->uuid(),
        ]);

        ClientOrgAccess::factory()->create([
            'client_org_id' => $clientOrg->id,
            'repo_org_id' => $user->org_id,
        ]);

        $action = TestAction::make(InviteAction::getDefaultName())->table();

        Livewire::test(ListInvites::class)
            ->assertActionVisible($action)
            ->callAction($action, data: [
                'type' => InviteTypeEnum::CLIENT_SCANS->value,
                'to' => fake()->safeEmail(),
                'client_org_id' => $clientOrg->id,
                'contact_person' => fake()->name(),
                'contact_phone' => fake()->phoneNumber(),
                'send_email' => true,
            ])
            ->assertHasNoFormErrors();

        // CLIENT_SCANS mail is queued by the scheduled command after Jon approves
        // in Backyard, not at creation time.
        Mail::assertNotQueued(NewInvite::class);
    }
}
