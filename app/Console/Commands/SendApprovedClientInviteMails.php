<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InviteTypeEnum;
use App\Mail\Invites\NewInvite;
use App\Models\Invite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendApprovedClientInviteMails extends Command
{
    protected $signature = 'invites:send-approved-client-mails';

    protected $description = 'Queue NewInvite mail for CLIENT_SCANS invites that Backyard has approved.';

    public function handle(): int
    {
        $invites = Invite::query()
            ->where('type', InviteTypeEnum::CLIENT_SCANS->value)
            ->whereNotNull('approved_at')
            ->whereNull('notification_sent_at')
            ->whereNull('accepted_at')
            ->get();

        foreach ($invites as $invite) {
            $recipient = $invite->meta['to'] ?? null;

            if (! $recipient) {
                continue;
            }

            // 7-day redemption clock starts at approval, not at admin-send.
            $invite->updateQuietly([
                'expires_at' => $invite->approved_at->copy()->addDays(7),
                'notification_sent_at' => now(),
            ]);

            Mail::to($recipient)->queue(new NewInvite($invite));
        }

        return self::SUCCESS;
    }
}
