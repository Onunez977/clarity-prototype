<?php

namespace Tests\Feature\Listeners;

use App\Events\HitEvent;
use App\Listeners\HitEventListener;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\MissedOpportunity;
use App\Notifications\Hits\HitNotification;
use App\Notifications\Hits\MissedOpportunityNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Spatie\SlackAlerts\Facades\SlackAlert;
use Tests\TestCase;

#[Group('alerts')]
class HitEventListenerTest extends TestCase
{
    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_is_listening(): void
    {
        Event::fake();

        Event::assertListening(
            HitEvent::class,
            HitEventListener::class
        );
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_sends_email_notification_on_missed_opportunity(): void
    {
        Notification::fake();

        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($missedOpportunity)
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($missedOpportunity));

        Notification::assertSentOnDemand(
            MissedOpportunityNotification::class,
            function (MissedOpportunityNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->routes['mail'][0] === $user->email;
            }
        );
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_sends_sms_notification_on_missed_opportunity(): void
    {
        Notification::fake();

        $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($missedOpportunity)
            ->smsNotification([$user->mobile_phone])
            ->create();

        event(new HitEvent($missedOpportunity));

        Notification::assertSentOnDemand(
            MissedOpportunityNotification::class,
            function (MissedOpportunityNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->routes['sms'][0] === $user->formatted_mobile_phone;
            }
        );
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_sends_slack_notification_on_missed_opportunity(): void
    {
        SlackAlert::fake();

        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $webhook = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX';

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($missedOpportunity)
            ->slackNotification([
                $webhook,
            ])
            ->create();

        event(new HitEvent($missedOpportunity));

        SlackAlert::expectMessagesSent(function ($message) use ($webhook) {
            $this->assertEquals($webhook, $message['webhookUrl']);
            $this->assertEquals('Insight LPR + Clarity', $message['username']);
            $this->assertNotEmpty($message['blocks']);

            return true;
        });
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_does_not_send_notification_on_missed_opportunity_for_non_missed_opportunity_type_alert_settings(): void
    {
        Notification::fake();

        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            // Different type
            ->forTypes(types: ['L'])
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($missedOpportunity));

        Notification::assertNothingSent();
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_sends_email_notification_on_hit(): void
    {
        Notification::fake();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($hit)
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($hit));

        Notification::assertSentOnDemand(
            HitNotification::class,
            function (HitNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->routes['mail'][0] === $user->email;
            }
        );
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_sends_sms_notification_on_hit(): void
    {
        Notification::fake();

        $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($hit)
            ->smsNotification([$user->mobile_phone])
            ->create();

        event(new HitEvent($hit));

        Notification::assertSentOnDemand(
            HitNotification::class,
            function (HitNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->routes['sms'][0] === $user->formatted_mobile_phone;
            }
        );
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_sends_slack_notification_on_hit(): void
    {
        SlackAlert::fake();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        $webhook = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX';

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($hit)
            ->slackNotification([
                $webhook,
            ])
            ->create();

        event(new HitEvent($hit));

        SlackAlert::expectMessagesSent(function ($message) use ($webhook) {
            $this->assertEquals($webhook, $message['webhookUrl']);
            $this->assertEquals('Insight LPR + Clarity', $message['username']);
            $this->assertNotEmpty($message['blocks']);

            return true;
        });
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_does_not_send_notification_on_hit_for_non_hit_type_alert_settings(): void
    {
        Notification::fake();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            // Different type
            ->forTypes(types: ['M'])
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($hit));

        Notification::assertNothingSent();
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_does_not_send_notification_on_hit_without_alert_settings(): void
    {
        Notification::fake();

        $hit = Hit::factory()->create();

        event(new HitEvent($hit));

        Notification::assertNothingSent();
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_does_not_send_notification_on_hit_for_other_org_user(): void
    {
        Notification::fake();

        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()->create();

        AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($missedOpportunity));

        Notification::assertNothingSent();
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_does_not_send_notification_for_hit_missing_a_kit(): void
    {
        Notification::fake();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        $hit->kit->delete();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($hit)
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($hit));

        Notification::assertNothingSent();
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_passes_scans_through_event(): void
    {
        Notification::fake();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($hit)
            ->emailNotification([$user->email])
            ->create();

        $scans = [
            ['id' => 1, 'plate' => 'ABC123'],
            ['id' => 2, 'plate' => 'DEF456'],
        ];

        event(new HitEvent($hit, scans: $scans));

        Notification::assertSentOnDemand(
            HitNotification::class,
            function (HitNotification $notification) use ($scans) {
                return $notification->scans === $scans;
            }
        );
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_prevents_duplicate_notification_for_historical_via_event(): void
    {
        Notification::fake();
        Cache::flush();

        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->historical()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($hit)
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($hit));
        event(new HitEvent($hit));

        Notification::assertSentOnDemandTimes(HitNotification::class, 1);
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_prevents_duplicate_notification_for_missed_opportunity_via_event(): void
    {
        Notification::fake();
        Cache::flush();

        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->forTypes($missedOpportunity)
            ->emailNotification([$user->email])
            ->create();

        event(new HitEvent($missedOpportunity));
        event(new HitEvent($missedOpportunity));

        Notification::assertSentOnDemandTimes(MissedOpportunityNotification::class, 1);
    }
}
