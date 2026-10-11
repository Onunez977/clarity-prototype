<?php

namespace Tests\Feature\Models\Concerns;

use App\Channels\Webhook\WebhookChannel;
use App\Enums\FeatureEnum;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Notifications\Hits\HitNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alerts')]
#[Group('notifications')]
#[Group('webhook')]
class HitWebhookFanOutTest extends TestCase
{
    public function test_dispatches_webhook_for_pro_plus_user(): void
    {
        Notification::fake();

        $url = 'https://example.com/hooks/clarity';
        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->webhookNotification($url)
            ->create();

        $hit->notifySubscribers();

        Notification::assertSentOnDemand(
            HitNotification::class,
            function ($notification, $channels, $notifiable) use ($url) {
                return \in_array(WebhookChannel::class, $channels)
                    && \in_array($url, (array) ($notifiable->routes['webhook'] ?? []));
            }
        );
    }

    public function test_does_not_dispatch_webhook_without_feature(): void
    {
        Notification::fake();

        // Enable Alert Settings (so the subscriber qualifies) but NOT Hit Webhooks.
        $user = $this->createUser();
        $this->makeOrgPro($user->org, FeatureEnum::ALERT_SETTINGS->value);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        AlertSetting::factory()
            ->forUser($user)
            ->webhookNotification('https://example.com/hooks/clarity')
            ->create();

        $hit->notifySubscribers();

        Notification::assertNothingSent();
    }
}
