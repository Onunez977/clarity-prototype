<?php

namespace Tests\Feature\Mail\Invites;

use App\Filament\Resources\InviteResource\Pages\ListInvites;
use App\Mail\Invites\AcceptedInvite;
use App\Models\Invite;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AcceptedInviteTest extends TestCase
{
    #[Group('mail')]
    public function test_can_render(): void
    {
        Mail::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create());

        Mail::to($invite->user->email)->queue(new AcceptedInvite(invite: $invite));

        Mail::assertQueued(AcceptedInvite::class, function (AcceptedInvite $mail) use ($invite) {
            $mail->build();

            $mail->assertTo($invite->user->email)
                ->assertHasSubject($invite->invitable->name.' accepted your Invite for "'.$invite->type.'"')
                ->assertSeeInText('Hi '.$invite->user->name.',')
                ->assertSeeInText($invite->invitable->name.' has accepted your invite to "'.$invite->type.'."')
                ->assertSeeInText('To revoke access at anytime, you can delete the invite.')
                ->assertSeeInText(ListInvites::getUrl().'?tableFilters[filters][direction]=sent');

            return true;
        });
    }
}
