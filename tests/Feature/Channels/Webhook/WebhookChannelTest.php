<?php

namespace Tests\Feature\Channels\Webhook;

use App\Channels\Webhook\WebhookChannel;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\MissedOpportunity;
use App\Notifications\Hits\HitNotification;
use App\Notifications\Hits\MissedOpportunityNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alerts')]
#[Group('notifications')]
class WebhookChannelTest extends TestCase
{
    private function makeNotification(string $url, ?string $secret): array
    {
        $user = $this->createUser(pro: true);

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->webhookNotification($url, secret: $secret)
            ->create();

        $notification = new HitNotification(
            hit: $hit,
            alertSetting: $alertSetting,
            via: [WebhookChannel::class],
        );

        $notifiable = (object) ['routes' => ['webhook' => [$url]]];

        return [$notification, $notifiable, $hit];
    }

    #[Group('webhook')]
    public function test_posts_payload_with_secret_header(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $url = 'https://example.com/hooks/clarity';
        [$notification, $notifiable, $hit] = $this->makeNotification($url, secret: 'shhhh-secret');

        (new WebhookChannel)->send($notifiable, $notification);

        Http::assertSent(function (Request $request) use ($url, $hit) {
            $payload = $request->data();

            $expectedKeys = [
                'hit_type', 'now', 'vin', 'plate', 'state', 'us_state', 'make',
                'model', 'year', 'udbsaid', 'scanning_org_id', 'hotlist_org_id',
                'color', 'ml_is_not_plate', 'ml_plate_state', 'insight_hit_id',
                'insight_hit_scan_id', 'insight_kit_id', 'insight_hotlist_id',
                'insight_org_type', 'insight_scanning_org_type',
                'insight_hotlist_org_type', 'insight_integration_id',
                'remote_order_id', 'scans', 'case_claim_url',
            ];

            foreach ($expectedKeys as $key) {
                if (! \array_key_exists($key, $payload)) {
                    return false;
                }
            }

            return $request->url() === $url
                && $request->method() === 'POST'
                && $request->hasHeader('X-Webhook-Secret', 'shhhh-secret')
                && $payload['insight_hit_id'] === $hit->id
                && $payload['insight_kit_id'] === $hit->kit_id
                && $payload['vin'] === $hit->hotlist->vin
                && $payload['scanning_org_id'] === $hit->kit->org_id
                && $payload['case_claim_url'] === ''
                && \count($payload['scans']) === 1
                && \array_key_exists('location', $payload['scans'][0])
                && \array_key_exists('guid', $payload['scans'][0]);
        });

        $this->assertDatabaseHas('notification_logs', [
            'type' => 'webhook',
            'status' => 'sent',
            'recipient' => $url,
        ]);
    }

    #[Group('webhook')]
    public function test_posts_payload_for_missed_opportunity_notification(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $url = 'https://example.com/hooks/clarity';
        $user = $this->createUser(pro: true);

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->webhookNotification($url, secret: 'shhhh-secret')
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: [WebhookChannel::class],
        );

        (new WebhookChannel)->send((object) ['routes' => ['webhook' => [$url]]], $notification);

        Http::assertSent(function (Request $request) use ($url, $missedOpportunity) {
            return $request->url() === $url
                && $request->hasHeader('X-Webhook-Secret', 'shhhh-secret')
                && $request['insight_hit_id'] === $missedOpportunity->id
                && $request['hit_type'] === 'Missed_Opportunity'
                && \array_key_exists('scans', $request->data());
        });

        $this->assertDatabaseHas('notification_logs', [
            'type' => 'webhook',
            'status' => 'sent',
            'recipient' => $url,
        ]);
    }

    #[Group('webhook')]
    public function test_logs_failure_when_endpoint_errors(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $url = 'https://example.com/hooks/broken';
        [$notification, $notifiable] = $this->makeNotification($url, secret: 'shhhh-secret');

        (new WebhookChannel)->send($notifiable, $notification);

        $this->assertDatabaseHas('notification_logs', [
            'type' => 'webhook',
            'status' => 'failed',
            'recipient' => $url,
        ]);
    }
}
