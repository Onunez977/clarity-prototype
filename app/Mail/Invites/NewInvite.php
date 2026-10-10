<?php

declare(strict_types=1);

namespace App\Mail\Invites;

use App\Enums\InviteTypeEnum;
use App\Filament\Resources\InviteResource\Pages\ListInvites;
use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewInvite extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        public Invite $invite,
    ) {
        $this->subject = 'You have a new Invite from '.$this->invite->user->email.' to "'.$this->invite->type.'"';

        $this->onQueue('{high}');
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        $isClientScans = $this->invite->type === InviteTypeEnum::CLIENT_SCANS->value;
        $recipientEmail = $this->invite->meta['to'] ?? ($this->invite->invitable->email ?? null);

        $redeemUrl = $isClientScans
            ? route('client.invite.redeem.show', [
                'email' => $recipientEmail,
                'code' => $this->invite->meta['code'] ?? null,
            ])
            : ListInvites::getUrl().'?tableFilters[filters][direction]=received';

        return $this->markdown('mail.invites.new-invite', [
            'invite' => $this->invite,
            'isClientScans' => $isClientScans,
            'greetingName' => $this->invite->meta['contact_person']
                ?? $this->invite->invitable->name
                ?? $this->invite->invitable->email
                ?? $recipientEmail
                ?? 'there',
            'redeemUrl' => $redeemUrl,
        ]);
    }
}
