<?php

namespace Tests\Feature\Observers;

use App\Enums\InviteTypeEnum;
use App\Jobs\ShareOrgKits;
use App\Mail\Invites\AcceptedInvite;
use App\Mail\Invites\DeclinedInvite;
use App\Models\ClientOrgAccess;
use App\Models\Invite;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class InviteObserverTest extends TestCase
{
    #[Group('observers')]
    #[Group('invites')]
    public function test_doesnt_send_new_invite_mail_on_creation_for_no_user(): void
    {
        Mail::fake();

        Invite::factory()->create([
            'invitable_type' => 'org',
            'invitable_id' => 1,
        ]);

        Mail::assertNothingQueued();
    }

    #[Group('observers')]
    #[Group('invites')]
    public function test_sends_accepted_invite_mail_on_update(): void
    {
        Mail::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create());

        $invite->update([
            'accepted_at' => now(),
        ]);

        Mail::assertQueued(AcceptedInvite::class);
    }

    #[Group('observers')]
    #[Group('invites')]
    public function test_sends_declined_invite_mail_on_update(): void
    {
        Mail::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create());

        $invite->update([
            'expires_at' => now(),
        ]);

        Mail::assertQueued(DeclinedInvite::class);
    }

    #[Group('observers')]
    #[Group('invites')]
    public function test_does_not_send_mail_on_empty_update(): void
    {
        Mail::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create());

        $invite->update([
            'meta' => [
                'key' => 'value',
            ],
        ]);

        Mail::assertNothingQueued();
    }

    #[Group('observers')]
    #[Group('invites')]
    public function test_dispatches_share_kits_job_on_acceptance(): void
    {
        Mail::fake();
        Queue::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create([
            'type' => InviteTypeEnum::SHARE_SCANS->value,
        ]));

        $invite->update([
            'accepted_at' => now(),
        ]);

        Queue::assertPushed(ShareOrgKits::class);
    }

    #[Group('observers')]
    #[Group('invites')]
    public function test_creates_client_org_access_on_client_scans_acceptance(): void
    {
        Mail::fake();

        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
        ]));

        $invite->update(['accepted_at' => now()]);

        $this->assertEquals(
            1,
            ClientOrgAccess::query()
                ->where('client_org_id', $clientOrg->id)
                ->where('repo_org_id', $repoOrg->id)
                ->count()
        );

        $this->assertTrue($clientOrg->fresh()->isClientPortalEnabled());
    }

    #[Group('observers')]
    #[Group('invites')]
    public function test_client_scans_access_is_idempotent(): void
    {
        Mail::fake();

        $repoOrg = Org::factory()->create();
        $sender = User::factory()->forOrg($repoOrg)->create();
        $clientOrg = Org::factory()->client()->create();

        $first = Invite::withoutEvents(fn () => Invite::factory()->create([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
        ]));
        $first->update(['accepted_at' => now()]);

        $second = Invite::withoutEvents(fn () => Invite::factory()->create([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
        ]));
        $second->update(['accepted_at' => now()]);

        $this->assertEquals(
            1,
            ClientOrgAccess::query()
                ->where('client_org_id', $clientOrg->id)
                ->where('repo_org_id', $repoOrg->id)
                ->count()
        );
    }
}
