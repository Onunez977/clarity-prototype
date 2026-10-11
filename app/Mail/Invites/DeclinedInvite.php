<?php

declare(strict_types=1);

namespace App\Mail\Invites;

use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DeclinedInvite extends Mailable implements ShouldQueue
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
        $person = $invite->invitable->name ?? $invite->invitable->email;
        $this->subject = $person.' declined your Invite for "'.$this->invite->type.'"';

        $this->onQueue('{low}');
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        return $this->markdown('mail.invites.declined-invite', [
            'invite' => $this->invite,
        ]);
    }
}
