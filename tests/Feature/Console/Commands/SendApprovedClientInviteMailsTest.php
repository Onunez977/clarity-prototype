<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\SendApprovedClientInviteMails;
use App\Enums\InviteTypeEnum;
use App\Mail\Invites\NewInvite;
use App\Models\Invite;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SendApprovedClientInviteMailsTest extends TestCase
{
    #[Group('commands')]
    public function test_queues_mail_and_stamps_timestamps_for_approved_invites(): void
    {
        Mail::fake();

        $sender = User::factory()->create();
        $clientOrg = Org::factory()->client()->create();

        $approvedAt = now()->subMinutes(2);

        $invite = Invite::factory()->state([
            'type' => InviteTypeEnum::CLIENT_SCANS->value,
            'user_id' => $sender->id,
            'invitable_type' => 'org',
            'invitable_id' => $clientOrg->id,
            'meta' => [
                'code' => 'ABC123',
                'to' => 'newclient@example.com',
            ],
            'approved_at' => $approvedAt,
            'notification_sent_at' => null,
            'expires_at' => null,
        ])->create();

        Artisan::call(SendApprovedClientInviteMails::class);

        Mail::assertQueued(NewInvite::class, fn (NewInvite $mail) => $mail->hasTo('newclient@example.com'));

        $invite->refresh();
        $this->assertNotNull($invite->notification_sent_at);
        $this->assertNotNull($invite->expires_at);
        $this->assertEqualsWithDelta(
            $approvedAt->copy()->addDays(7)->timestamp,
            $invite->expires_at->timestamp,
            1,
        );
    }

    #[Group('commands')]
    public function test_is_idempotent_when_notification_already_sent(): void
    {
        Mail::fake();

        $sender = User::factory()->create();
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
            'notification_sent_at' => now()->subMinutes(30),
            'expires_at' => now()->addHours(23),
        ])->create();

        Artisan::call(SendApprovedClientInviteMails::class);

        Mail::assertNotQueued(NewInvite::class);
    }

    #[Group('commands')]
    public function test_skips_unapproved_invites(): void
    {
        Mail::fake();

        $sender = User::factory()->create();
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
        ])->create();

        Artisan::call(SendApprovedClientInviteMails::class);

        Mail::assertNotQueued(NewInvite::class);
    }

    #[Group('commands')]
    public function test_skips_already_accepted_invites(): void
    {
        Mail::fake();

        $sender = User::factory()->create();
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
            'notification_sent_at' => null,
            'accepted_at' => now()->subMinutes(10),
        ])->create();

        Artisan::call(SendApprovedClientInviteMails::class);

        Mail::assertNotQueued(NewInvite::class);
    }
}
