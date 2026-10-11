<?php

namespace Tests\Feature\Mail\Invites;

use App\Mail\Invites\DeclinedInvite;
use App\Models\Invite;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class DeclinedInviteTest extends TestCase
{
    #[Group('mail')]
    public function test_can_render(): void
    {
        Mail::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create());

        Mail::to($invite->user->email)->queue(new DeclinedInvite(invite: $invite));

        Mail::assertQueued(DeclinedInvite::class, function (DeclinedInvite $mail) use ($invite) {
            $mail->build();

            $mail->assertTo($invite->user->email)
                ->assertHasSubject($invite->invitable->name.' declined your Invite for "'.$invite->type.'"')
                ->assertSeeInText('Hi '.$invite->user->name.',')
                ->assertSeeInText($invite->invitable->name.' has declined your invite to "'.$invite->type.'."');

            return true;
        });
    }
}
