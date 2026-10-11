<?php

namespace Tests\Feature\Models\Concerns;

use App\Enums\HitTypeEnum;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\Org;
use App\Models\User;
use App\Notifications\Hits\HitNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alerts')]
#[Group('alert-settings')]
class HitAlertSettingsTest extends TestCase
{
    public static function hitStates(): array
    {
        return [
            'live' => ['live'],
            'staging live' => ['stagingLive'],
            'missed opportunity' => ['missedOpportunity'],
        ];
    }

    public static function notifiableHitStates(): array
    {
        return [
            'live' => ['live'],
            'staging live' => ['stagingLive'],
        ];
    }

    private function seedRow(Org $org, array $channels, ?string $rawSettings = null, ?string $webhook = null): AlertSetting
    {
        $user = User::factory()->forOrg($org)->create();

        $factory = AlertSetting::factory()
            ->forUser($user)
            ->forTypes(types: [
                HitTypeEnum::Live->value,
                HitTypeEnum::Staging_Live->value,
                HitTypeEnum::Missed_Opportunity->value,
            ]);

        if (isset($channels['emails'])) {
            $factory = $factory->emailNotification($channels['emails']);
        }

        if ($webhook !== null) {
            $factory = $factory->webhookNotification($webhook);
        }

        $alertSetting = $factory->create();

        if ($rawSettings !== null) {
            DB::table('alert_settings')
                ->where('id', $alertSetting->id)
                ->update(['settings' => $rawSettings]);
        }

        return $alertSetting;
    }

    private function raw(string $channelsJson): string
    {
        return '{"types":["L","S","M"],"clients":[],"notifications":'.$channelsJson.'}';
    }

    /**
     * @return array<string, AlertSetting>
     */
    private function seedMatrix(Org $org): array
    {
        return [
            'A' => $this->seedRow($org, ['emails' => ['a@x.com']]),
            'B' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":{"1":"b@x.com"},"slack":[]}')),
            'C' => $this->seedRow($org, [], $this->raw('{"sms":"555","emails":[],"slack":[]}')),
            'D' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":[],"slack":null}')),
            'E' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":{},"slack":[]}')),
            'F' => $this->seedRow($org, [], '{"types":["L","S","M"],"clients":[]}'),
            'G' => $this->seedRow($org, [], webhook: 'https://example.com/hooks/clarity'),
            'H' => $this->seedRow($org, [], $this->raw('{"sms":"555","emails":["h@x.com"],"slack":[]}')),
            'I' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":"i@x.com","slack":[]}')),
            'J' => $this->seedRow($org, [], $this->raw('{"sms":5551112222,"emails":[],"slack":[]}')),
            'K' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":[""],"slack":[]}')),
            'L' => $this->seedRow($org, [], $this->raw('{"sms":[null],"emails":[],"slack":[]}')),
            'M' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":{"0":""},"slack":[]}')),
            'N' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":[false],"slack":[]}')),
            'O' => $this->seedRow($org, [], $this->raw('{"sms":"","emails":[],"slack":[]}')),
            'P' => $this->seedRow($org, [], $this->raw('{"sms":[],"emails":[["p@x.com"]],"slack":[]}')),
        ];
    }

    private function makeHit(string $state, Org $org): Hit
    {
        return Hit::factory()->{$state}()->forOrg($org)->create();
    }

    #[DataProvider('hitStates')]
    public function test_get_subscribers_survives_malformed_rows_and_keeps_valid_ones(string $state): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $rows = $this->seedMatrix($user->org);
        $hit = $this->makeHit($state, $user->org);

        $ids = $hit->getSubscribers()->pluck('id')->sort()->values()->all();

        $expected = collect(['A', 'B', 'G', 'H'])->map(fn ($k) => $rows[$k]->id)->sort()->values()->all();

        $this->assertSame($expected, $ids);
    }

    #[DataProvider('notifiableHitStates')]
    public function test_object_shaped_emails_are_notified(string $state): void
    {
        $this->freezeTime();
        Cache::flush();
        Notification::fake();

        $user = $this->createUser(pro: true);
        $this->seedMatrix($user->org);
        $hit = $this->makeHit($state, $user->org);

        $hit->notifySubscribers();

        $class = HitNotification::class;

        Notification::assertSentOnDemand(
            $class,
            fn ($notification, $channels, $notifiable) => \in_array('b@x.com', (array) ($notifiable->routes['mail'] ?? []), true)
        );
        Notification::assertSentOnDemand(
            $class,
            fn ($notification, $channels, $notifiable) => \in_array('a@x.com', (array) ($notifiable->routes['mail'] ?? []), true)
        );
    }

    public function test_scalar_sms_is_treated_as_empty_and_valid_channels_remain(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $rows = $this->seedMatrix($user->org);

        $h = $rows['H']->fresh();

        $this->assertSame([], $h->routeNotificationForSms()->all());
        $this->assertSame(['h@x.com'], $h->routeNotificationForMail()->all());
    }

    public function test_null_and_empty_members_are_dropped(): void
    {
        $user = $this->createUser(pro: true);
        $row = $this->seedRow($user->org, [], $this->raw('{"sms":[],"emails":{"0":null,"1":"a@x.com","2":""},"slack":[]}'));

        $this->assertSame(['a@x.com'], $row->fresh()->routeNotificationForMail()->all());
    }

    public function test_subscriber_lookup_is_served_from_cache_for_ten_minutes_even_at_quarter_to_the_hour(): void
    {
        $this->travelTo(now()->startOfHour()->addMinutes(45));
        Cache::flush();

        $user = $this->createUser(pro: true);
        $row = $this->seedRow($user->org, ['emails' => ['a@x.com']]);
        $hit = $this->makeHit('live', $user->org);

        $this->assertSame([$row->id], $hit->getSubscribers()->pluck('id')->all());

        DB::table('alert_settings')->where('id', $row->id)->delete();
        $this->travel(5)->minutes();

        $this->assertSame([$row->id], $hit->getSubscribers()->pluck('id')->all());
    }
}
