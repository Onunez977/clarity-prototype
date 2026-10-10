<?php

namespace Tests\Feature\Notifications\Hits;

use App\Channels\Twilio\Messages\TwilioMessage;
use App\Mail\Hits;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Notifications\Hits\HitNotification;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alerts')]
class HitNotificationTest extends TestCase
{
    #[Group('notifications')]
    #[Group('hits')]
    public function test_sends_email_to_user(): void
    {
        Mail::fake();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([$user->email])
            ->create();

        $notification = new HitNotification(
            hit: $hit,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $mail = $notification->toMail($user);

        $this->assertEquals(Hits\NewNotification::class, \get_class($mail));

        $this->assertEquals([
            'name' => null,
            'address' => $user->email,
        ], $mail->to[0]);
    }

    #[Group('notifications')]
    #[Group('hits')]
    public function test_sends_email_to_external_email(): void
    {
        Mail::fake();

        $email = fake()->safeEmail();

        $hit = Hit::factory()->create();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([
                fake()->safeEmail(),
                $email,
                fake()->safeEmail(),
            ])
            ->create();

        $notification = new HitNotification(
            hit: $hit,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $notifiable = (object) ['routes' => ['mail' => [$email]]];
        $mail = $notification->toMail($notifiable);

        $this->assertEquals(Hits\NewNotification::class, \get_class($mail));

        $this->assertEquals([
            'name' => null,
            'address' => $email,
        ], $mail->to[0]);
    }

    #[Group('notifications')]
    #[Group('hits')]
    public function test_sends_sms_to_user(): void
    {
        Mail::fake();

        $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->smsNotification([$user->mobile_phone])
            ->create();

        $notification = new HitNotification(
            hit: $hit,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $sms = $notification->toTwilio($user);

        $this->assertEquals(TwilioMessage::class, \get_class($sms));

        $this->assertStringContainsString('Plate: '.$hit->hotlist->plate.' '.$hit->hotlist->us_state, $sms->content);
        $this->assertStringContainsString('Hotlist: '.$hit->hotlist->integration->name, $sms->content);
        $this->assertStringContainsString('Client: '.$hit->hotlist->client->name, $sms->content);

        $vehicle = $hit->hotlist->vehicle_year.' '.$hit->hotlist->vehicle_make.' '.$hit->hotlist->vehicle_model;
        $this->assertStringContainsString('Vehicle: '.$vehicle, $sms->content);

        $this->assertStringContainsString('https://www.google.com/maps/place/'.$hit->scan->lat.','.$hit->scan->lng, $sms->content);
        $this->assertStringContainsString('Powered by Insight LPR + Clarity', $sms->content);
    }

    #[Group('notifications')]
    #[Group('hits')]
    public function test_passes_scans_to_mail(): void
    {
        Mail::fake();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([$user->email])
            ->create();

        $scans = [
            ['id' => 1, 'plate' => 'ABC123'],
            ['id' => 2, 'plate' => 'DEF456'],
        ];

        $notification = new HitNotification(
            hit: $hit,
            alertSetting: $alertSetting,
            via: ['mail'],
            scans: $scans,
        );

        $notifiable = (object) ['routes' => ['mail' => [$user->email]]];
        $mail = $notification->toMail($notifiable);

        $this->assertInstanceOf(Hits\NewNotification::class, $mail);
        $this->assertEquals($scans, $mail->scans);
    }
}
