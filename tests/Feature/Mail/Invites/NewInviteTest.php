<?php

namespace Tests\Feature\Mail\Invites;

use App\Filament\Resources\InviteResource\Pages\ListInvites;
use App\Mail\Invites\NewInvite;
use App\Models\Invite;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class NewInviteTest extends TestCase
{
    #[Group('mail')]
    public function test_can_render_without_code(): void
    {
        Mail::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create());

        Mail::to($invite->invitable->email)->queue(new NewInvite(invite: $invite));

        Mail::assertQueued(NewInvite::class, function (NewInvite $mail) use ($invite) {
            $mail->build();

            $mail->assertTo($invite->invitable->email)
                ->assertHasSubject('You have a new Invite from '.$invite->user->email.' to "'.$invite->type.'"')
                ->assertSeeInText('You have been invited by '.$invite->user->name.' to "'.$invite->type.'."')
                ->assertDontSeeInText('Please use the following code to confirm the invitation:')
                ->assertSeeInText(ListInvites::getUrl().'?tableFilters[filters][direction]=received');

            return true;
        });
    }

    #[Group('mail')]
    public function test_can_render_with_code(): void
    {
        Mail::fake();

        $invite = Invite::withoutEvents(fn () => Invite::factory()->create([
            'meta' => [
                'code' => '123ABC',
            ],
        ]));

        Mail::to($invite->invitable->email)->queue(new NewInvite(invite: $invite));

        Mail::assertQueued(NewInvite::class, function (NewInvite $mail) use ($invite) {
            $mail->build();

            $mail->assertTo($invite->invitable->email)
                ->assertHasSubject('You have a new Invite from '.$invite->user->email.' to "'.$invite->type.'"')
                ->assertSeeInText('You have been invited by '.$invite->user->name.' to "'.$invite->type.'."')
                ->assertSeeInText('Please use the following code to confirm the invitation:')
                ->assertSeeInText('123ABC')
                ->assertSeeInText(ListInvites::getUrl().'?tableFilters[filters][direction]=received');

            return true;
        });
    }
}
