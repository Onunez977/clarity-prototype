<?php

namespace Tests\Feature\Mail;

use App\Mail\SendOneTimePassword;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SendOneTimePasswordTest extends TestCase
{
    #[Group('mail')]
    public function test_can_render(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $otp = (int) fake()->postcode();

        Mail::to($user->email)
            ->queue(new SendOneTimePassword(
                user: $user,
                otp: $otp,
            ));

        Mail::assertQueued(SendOneTimePassword::class, function (SendOneTimePassword $mail) use ($user, $otp) {
            $mail->build();

            $mail->assertTo($user->email)
                ->assertHasSubject('InsightLPR temporary login')
                ->assertSeeInText('Hi '.$user->name.',')
                ->assertSeeInText('Please use the following one-time password to login to your account on the InsightLPR app.')
                ->assertSeeInText($otp);

            return true;
        });
    }
}
