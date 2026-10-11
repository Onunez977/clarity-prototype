<?php

namespace Tests\Filament\Resources;

use App\Enums\InviteTypeEnum;
use App\Filament\Resources\InviteResource\Pages\ListInvites;
use App\Models\Invite;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\ResourceTestCase;

class InviteResourceTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Observer.
        Invite::flushEventListeners();
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_can_render_table(): void
    {
        $invites = Invite::factory()
            ->fromUser($this->user)
            ->count(3)
            ->create();

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($invites);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(role: [])
        );

        Livewire::test(ListInvites::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_can_render_table_columns(): void
    {
        $sentInvite = Invite::factory()
            ->fromUser($this->user)
            ->create();

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'sent')
            ->assertCanRenderTableColumn('type')
            ->assertSeeText($sentInvite->type)
            ->assertCanRenderTableColumn('id')
            ->assertSeeText('Pending')
            ->assertCanRenderTableColumn('user_name')
            ->assertSeeText($sentInvite->user->email)
            ->assertCanRenderTableColumn('created_at');

        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create();

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'received')
            ->assertCanRenderTableColumn('type')
            ->assertSeeText($receivedInvite->type)
            ->assertCanRenderTableColumn('id')
            ->assertCanNotRenderTableColumn('meta.code')
            ->assertSeeText('Pending')
            ->assertCanRenderTableColumn('user_name')
            ->assertSeeText($receivedInvite->user->email)
            ->assertCanRenderTableColumn('created_at');
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_type_filter(): void
    {
        $shareInvite = Invite::factory()
            ->fromUser($this->user)
            ->create([
                'type' => InviteTypeEnum::SHARE_SCANS->value,
            ]);

        $clientInvite = Invite::factory()
            ->fromUser($this->user)
            ->create([
                'type' => InviteTypeEnum::CLIENT_SCANS->value,
            ]);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.type', InviteTypeEnum::SHARE_SCANS->value)
            ->assertCanSeeTableRecords([$shareInvite])
            ->assertCanNotSeeTableRecords([$clientInvite])
            ->set('tableFilters.filters.type', InviteTypeEnum::CLIENT_SCANS->value)
            ->assertCanNotSeeTableRecords([$shareInvite])
            ->assertCanSeeTableRecords([$clientInvite]);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_status_filter(): void
    {
        $pendingInvite = Invite::factory()
            ->fromUser($this->user)
            ->create();

        $completedInvite = Invite::factory()
            ->fromUser($this->user)
            ->create([
                'accepted_at' => now(),
            ]);

        $expiredInvite = Invite::factory()
            ->fromUser($this->user)
            ->create([
                'expires_at' => now()->subDay(),
            ]);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.status', 'pending')
            ->assertCanSeeTableRecords([$pendingInvite])
            ->assertCanNotSeeTableRecords([$completedInvite])
            ->assertCanNotSeeTableRecords([$expiredInvite])
            ->set('tableFilters.filters.status', 'completed')
            ->assertCanSeeTableRecords([$completedInvite])
            ->assertCanNotSeeTableRecords([$pendingInvite])
            ->assertCanNotSeeTableRecords([$expiredInvite])
            ->set('tableFilters.filters.status', 'expired')
            ->assertCanSeeTableRecords([$expiredInvite])
            ->assertCanNotSeeTableRecords([$pendingInvite])
            ->assertCanNotSeeTableRecords([$completedInvite]);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_direction_filter(): void
    {
        $sentInvite = Invite::factory()
            ->fromUser($this->user)
            ->create();

        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create();

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'sent')
            ->assertCanSeeTableRecords([$sentInvite])
            ->assertCanNotSeeTableRecords([$receivedInvite])
            ->set('tableFilters.filters.direction', 'received')
            ->assertCanNotSeeTableRecords([$sentInvite])
            ->assertCanSeeTableRecords([$receivedInvite]);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_can_only_delete_sent_invites(): void
    {
        $sentInvite = Invite::factory()
            ->fromUser($this->user)
            ->create();

        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create();

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->loadTable()
            ->set('tableFilters.filters.direction', 'sent')
            ->assertTableActionVisible('delete', record: $sentInvite)
            ->set('tableFilters.filters.direction', 'received')
            ->assertTableActionHidden('delete', record: $receivedInvite);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_can_decline_received_invite(): void
    {
        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create();

        $this->assertNull($receivedInvite->expires_at);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'received')
            ->assertTableActionExists('decline', record: $receivedInvite)
            ->assertTableActionVisible('decline', record: $receivedInvite)
            ->callTableAction('decline', $receivedInvite)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($receivedInvite->fresh()->expires_at);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_cannot_decline_expired_invite(): void
    {
        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create([
                'expires_at' => now()->subDay(),
            ]);

        $this->assertNull($receivedInvite->accepted_at);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'received')
            ->assertTableActionHidden('decline', record: $receivedInvite);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_can_accept_received_invite(): void
    {
        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create();

        $this->assertNull($receivedInvite->accepted_at);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'received')
            ->assertTableActionExists('accept', record: $receivedInvite)
            ->assertTableActionVisible('accept', record: $receivedInvite)
            ->callTableAction('accept', $receivedInvite)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($receivedInvite->fresh()->accepted_at);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_cannot_accept_expired_invite(): void
    {
        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create([
                'expires_at' => now()->subDay(),
            ]);

        $this->assertNull($receivedInvite->accepted_at);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'received')
            ->assertTableActionHidden('accept', record: $receivedInvite);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_can_accept_received_invite_with_code(): void
    {
        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create([
                'meta' => [
                    'code' => '123456',
                ],
            ]);

        $this->assertNull($receivedInvite->accepted_at);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'received')
            ->assertTableActionExists('accept', record: $receivedInvite)
            ->assertTableActionVisible('accept', record: $receivedInvite)
            ->callTableAction('accept', $receivedInvite, data: [
                'code' => '123456',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($receivedInvite->fresh()->accepted_at);
    }

    #[Group('resources')]
    #[Group('invites')]
    public function test_cannot_accept_received_invite_without_code(): void
    {
        $receivedInvite = Invite::factory()
            ->toUser($this->user)
            ->create([
                'meta' => [
                    'code' => '123456',
                ],
            ]);

        $this->assertNull($receivedInvite->accepted_at);

        Livewire::test(ListInvites::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.direction', 'received')
            ->assertTableActionExists('accept', record: $receivedInvite)
            ->assertTableActionVisible('accept', record: $receivedInvite)
            ->callTableAction('accept', $receivedInvite)
            ->assertHasTableActionErrors(['code' => ['required']]);

        $this->assertNull($receivedInvite->fresh()->accepted_at);
    }
}
