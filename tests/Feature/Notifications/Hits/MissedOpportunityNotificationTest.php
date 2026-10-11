<?php

namespace Tests\Feature\Notifications\Hits;

use App\Channels\Twilio\Messages\TwilioMessage;
use App\Channels\Twilio\TwilioChannel;
use App\Mail\MissedOpportunities;
use App\Models\AlertSetting;
use App\Models\MissedOpportunity;
use App\Notifications\Hits\MissedOpportunityNotification;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class MissedOpportunityNotificationTest extends TestCase
{
    #[Group('notifications')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_sends_email_to_user(): void
    {
        Mail::fake();

        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([$user->email])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $mail = $notification->toMail($user);

        $this->assertEquals(MissedOpportunities\NewNotification::class, \get_class($mail));

        $this->assertEquals([
            'name' => null,
            'address' => $user->email,
        ], $mail->to[0]);
    }

    #[Group('notifications')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_sends_email_to_external_email(): void
    {
        Mail::fake();

        $email = fake()->safeEmail();

        $missedOpportunity = MissedOpportunity::factory()->create();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([
                fake()->safeEmail(),
                $email,
                fake()->safeEmail(),
            ])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $notifiable = (object) ['routes' => ['mail' => [$email]]];
        $mail = $notification->toMail($notifiable);

        $this->assertEquals(MissedOpportunities\NewNotification::class, \get_class($mail));

        $this->assertEquals([
            'name' => null,
            'address' => $email,
        ], $mail->to[0]);
    }

    #[Group('notifications')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_sends_sms_to_user(): void
    {
        Mail::fake();

        $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->smsNotification([$user->mobile_phone])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: [TwilioChannel::class]
        );

        $sms = $notification->toTwilio($user);

        $this->assertEquals(TwilioMessage::class, \get_class($sms));

        $this->assertStringContainsString('Plate: '.$missedOpportunity->hotlist->plate.' '.$missedOpportunity->hotlist->us_state, $sms->content);
        $this->assertStringContainsString('Hotlist: '.$missedOpportunity->hotlist->integration->name, $sms->content);
        $this->assertStringContainsString('Client: '.$missedOpportunity->hotlist->client->name, $sms->content);

        $vehicle = $missedOpportunity->hotlist->vehicle_year.' '.$missedOpportunity->hotlist->vehicle_make.' '.$missedOpportunity->hotlist->vehicle_model;
        $this->assertStringContainsString('Vehicle: '.$vehicle, $sms->content);

        $this->assertStringContainsString('https://www.google.com/maps/place/'.$missedOpportunity->scan->lat.','.$missedOpportunity->scan->lng, $sms->content);
        $this->assertStringContainsString('Powered by Insight LPR + Clarity', $sms->content);
    }

    #[Group('notifications')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_sends_filament_notification_to_user(): void
    {
        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([$user->email])
            ->create();

        $this->actingAs($user);

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $notification->toMail($user);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
        ]);
    }

    #[Group('notifications')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_does_not_send_filament_notification_for_non_pro_user(): void
    {
        $user = $this->createUser(pro: false);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([$user->email])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $notification->toMail($user);

        Notification::assertNotNotified();
    }

    #[Group('notifications')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_does_not_send_filament_notification_for_non_user(): void
    {
        $email = fake()->safeEmail();

        $missedOpportunity = MissedOpportunity::factory()->create();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$email])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: ['mail']
        );

        $notifiable = (object) ['routes' => ['mail' => [$email]]];
        $notification->toMail($notifiable);

        Notification::assertNotNotified();
    }
}
