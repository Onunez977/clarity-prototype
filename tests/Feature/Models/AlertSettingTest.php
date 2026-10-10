<?php

namespace Tests\Feature\Models;

use App\Models\AlertSetting;
use App\Models\Hit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alerts')]
#[Group('alert-settings')]
class AlertSettingTest extends TestCase
{
    private function rawType(AlertSetting $alertSetting, string $channel): ?string
    {
        return DB::selectOne(
            "select jsonb_typeof(settings->'notifications'->'{$channel}') as t from alert_settings where id = ?",
            [$alertSetting->id]
        )->t;
    }

    private function rawChannel(AlertSetting $alertSetting, string $channel): mixed
    {
        $settings = DB::table('alert_settings')->where('id', $alertSetting->id)->value('settings');

        return \json_decode($settings, true)['notifications'][$channel];
    }

    public function test_remove_email_from_the_middle_stores_a_list(): void
    {
        $alertSetting = AlertSetting::factory()
            ->emailNotification(['a@x.com', 'b@x.com', 'c@x.com'])
            ->create();

        $alertSetting->removeEmail('b@x.com');

        $this->assertSame('array', $this->rawType($alertSetting, 'emails'));
        $this->assertSame(['a@x.com', 'c@x.com'], $this->rawChannel($alertSetting, 'emails'));
    }

    public function test_remove_email_from_the_start_stores_a_list(): void
    {
        $alertSetting = AlertSetting::factory()
            ->emailNotification(['a@x.com', 'b@x.com', 'c@x.com'])
            ->create();

        $alertSetting->removeEmail('A@x.com');

        $this->assertSame('array', $this->rawType($alertSetting, 'emails'));
        $this->assertSame(['b@x.com', 'c@x.com'], $this->rawChannel($alertSetting, 'emails'));
    }

    public function test_subscribers_resolve_after_an_email_is_removed(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);

        $alertSetting = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification(['a@x.com', 'b@x.com', 'c@x.com'])
            ->create();

        $hit = Hit::factory()->live()->forOrg($user->org)->create();

        $this->assertSame([$alertSetting->id], $hit->getSubscribers()->pluck('id')->all());

        $alertSetting->removeEmail('a@x.com');

        $subscribers = $hit->getSubscribers();

        $this->assertSame([$alertSetting->id], $subscribers->pluck('id')->all());
        $this->assertSame(['b@x.com', 'c@x.com'], $subscribers->first()->routeNotificationForMail()->all());
    }

    public function test_unsubscribing_the_last_email_drops_the_row_from_a_warm_subscriber_cache(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $alertSetting = AlertSetting::factory()->forUser($user)->emailNotification(['a@x.com'])->create();
        $hit = Hit::factory()->live()->forOrg($user->org)->create();

        $this->assertCount(1, $hit->getSubscribers());

        $alertSetting->removeEmail('a@x.com');

        $this->assertCount(0, $hit->getSubscribers());
    }

    public function test_unsubscribe_invalidates_by_the_users_org_when_the_row_org_id_is_stale(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $otherOrg = $this->createUser()->org;
        $alertSetting = AlertSetting::factory()->forUser($user)->emailNotification(['a@x.com'])->create();
        DB::table('alert_settings')->where('id', $alertSetting->id)->update(['org_id' => $otherOrg->id]);
        $alertSetting->refresh();
        $hit = Hit::factory()->live()->forOrg($user->org)->create();

        $this->assertCount(1, $hit->getSubscribers());

        $alertSetting->removeEmail('a@x.com');

        $this->assertCount(0, $hit->getSubscribers());
    }

    public function test_unsubscribing_sms_invalidates_a_warm_subscriber_cache(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $alertSetting = AlertSetting::factory()->forUser($user)->smsNotification(['5551112222', '5553334444'])->create();
        $hit = Hit::factory()->live()->forOrg($user->org)->create();

        $this->assertSame(['5551112222', '5553334444'], $hit->getSubscribers()->first()->routeNotificationForSms()->all());

        $alertSetting->removeSms('(555) 111-2222');

        $this->assertSame(['5553334444'], $hit->getSubscribers()->first()->routeNotificationForSms()->all());
    }

    public function test_saving_and_deleting_a_row_invalidates_a_warm_subscriber_cache(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $alertSetting = AlertSetting::factory()->forUser($user)->emailNotification(['a@x.com'])->create();
        $hit = Hit::factory()->live()->forOrg($user->org)->create();

        $this->assertSame(['a@x.com'], $hit->getSubscribers()->first()->routeNotificationForMail()->all());

        $alertSetting->settings->notifications['emails'] = ['z@x.com'];
        $alertSetting->save();

        $this->assertSame(['z@x.com'], $hit->getSubscribers()->first()->routeNotificationForMail()->all());

        $alertSetting->delete();

        $this->assertCount(0, $hit->getSubscribers());
    }

    public function test_flushing_one_orgs_subscriber_cache_leaves_other_orgs_warm(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $other = $this->createUser(pro: true);
        AlertSetting::factory()->forUser($user)->emailNotification(['a@x.com'])->create();
        $otherRow = AlertSetting::factory()->forUser($other)->emailNotification(['o@x.com'])->create();
        $otherHit = Hit::factory()->live()->forOrg($other->org)->create();

        $this->assertCount(1, $otherHit->getSubscribers());

        DB::table('alert_settings')->where('id', $otherRow->id)->delete();
        AlertSetting::flushSubscriberCache($user->org_id);

        $this->assertCount(1, $otherHit->getSubscribers());

        AlertSetting::flushSubscriberCache($other->org_id);

        $this->assertCount(0, $otherHit->getSubscribers());
    }

    public function test_remove_sms_only_shrinks_the_sms_list(): void
    {
        $alertSetting = AlertSetting::factory()
            ->smsNotification(['5551112222', '5553334444'])
            ->emailNotification(['x@y.com'])
            ->create();

        $alertSetting->removeSms('(555) 111-2222');

        $this->assertSame(['5553334444'], $this->rawChannel($alertSetting, 'sms'));
        $this->assertSame(['x@y.com'], $this->rawChannel($alertSetting, 'emails'));
    }

    public function test_legacy_object_row_is_rewritten_as_a_list(): void
    {
        $alertSetting = AlertSetting::factory()->create();

        DB::table('alert_settings')->where('id', $alertSetting->id)->update([
            'settings' => '{"types":["L"],"clients":[],"notifications":{"sms":[],"emails":{"0":"a","2":"c"},"slack":[]}}',
        ]);

        $alertSetting->fresh()->removeEmail('a');

        $this->assertSame('array', $this->rawType($alertSetting, 'emails'));
        $this->assertSame(['c'], $this->rawChannel($alertSetting, 'emails'));
    }

    public function test_subscription_lookups_match_array_and_object_shaped_lists(): void
    {
        $array = AlertSetting::factory()->emailNotification(['a@x.com'])->smsNotification(['(555) 111-2222'])->create();
        $object = AlertSetting::factory()->create();
        $other = AlertSetting::factory()->emailNotification(['z@x.com'])->create();

        DB::table('alert_settings')->where('id', $object->id)->update([
            'settings' => '{"types":{"0":"L"},"clients":[],"notifications":{"sms":{"1":"5551112222"},"emails":{"1":"a@x.com"},"slack":[]}}',
        ]);

        $this->assertEqualsCanonicalizing([$array->id, $object->id], AlertSetting::alertsEmailIsSubscribedTo('a@x.com')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$array->id, $object->id], AlertSetting::alertsSmsIsSubscribedTo('(555) 111-2222')->pluck('id')->all());
        $this->assertNotContains($other->id, AlertSetting::alertsEmailIsSubscribedTo('a@x.com')->pluck('id')->all());
    }

    public function test_settings_list_scope_ignores_scalar_null_and_missing_paths(): void
    {
        $scalar = AlertSetting::factory()->create();
        DB::table('alert_settings')->where('id', $scalar->id)->update([
            'settings' => '{"types":"L","notifications":{"emails":"a@x.com","sms":5551112222,"slack":null}}',
        ]);

        $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('notifications,emails', 'a@x.com')->count());
        $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('types', 'L')->count());
        $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('notifications,sms', '5551112222')->count());
        $this->assertSame([], AlertSetting::alertsSmsIsSubscribedTo('(555) 111-2222')->pluck('id')->all());
        $this->assertSame([], AlertSetting::alertsEmailIsSubscribedTo('a@x.com')->pluck('id')->all());
        $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('notifications,slack', 'x')->count());
        $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('notifications,nope', 'a@x.com')->count());
    }

    public function test_settings_list_scope_ignores_empty_and_non_text_members(): void
    {
        $row = AlertSetting::factory()->create();

        foreach (['[""]', '[null]', '{"0":""}', '[false]', '[[""]]', '{"a":{"b":"x"}}', '""', 'null', 'true'] as $emails) {
            DB::table('alert_settings')->where('id', $row->id)->update([
                'settings' => '{"types":["L"],"notifications":{"emails":'.$emails.'}}',
            ]);

            $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('notifications,emails', '')->count(), $emails);
            $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('notifications,emails', 'x')->count(), $emails);
        }
    }

    public function test_settings_list_scope_rejects_bad_paths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AlertSetting::query()->whereSettingsListContains("types'}", 'L');
    }

    public function test_settings_list_scope_rejects_a_path_with_a_trailing_newline(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AlertSetting::query()->whereSettingsListContains("types\n", 'L');
    }

    public function test_email_lookup_is_case_insensitive_and_sms_lookup_is_unchanged(): void
    {
        $mixed = AlertSetting::factory()->emailNotification(['Dalton@X.com'])->smsNotification(['5551112222'])->create();
        $object = AlertSetting::factory()->create();
        $other = AlertSetting::factory()->emailNotification(['z@x.com'])->create();

        DB::table('alert_settings')->where('id', $object->id)->update([
            'settings' => '{"types":["L"],"clients":[],"notifications":{"sms":[],"emails":{"1":"DALTON@x.com"},"slack":[]}}',
        ]);

        $ids = AlertSetting::alertsEmailIsSubscribedTo('dalton@x.com')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mixed->id, $object->id], $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame([$mixed->id], AlertSetting::alertsSmsIsSubscribedTo('(555) 111-2222')->pluck('id')->all());
    }

    public function test_settings_list_scope_is_case_sensitive_by_default(): void
    {
        AlertSetting::factory()->emailNotification(['Dalton@X.com'])->create();

        $this->assertSame(0, AlertSetting::query()->whereSettingsListContains('notifications,emails', 'dalton@x.com')->count());
        $this->assertSame(1, AlertSetting::query()->whereSettingsListContains('notifications,emails', 'dalton@x.com', caseInsensitive: true)->count());
    }

    public function test_email_lookup_folds_non_ascii_case_in_sql(): void
    {
        $row = AlertSetting::factory()->emailNotification(['ÉMILE@x.com'])->create();

        $this->assertSame([$row->id], AlertSetting::alertsEmailIsSubscribedTo('émile@x.com')->pluck('id')->all());
        $this->assertSame([$row->id], AlertSetting::alertsEmailIsSubscribedTo('ÉMILE@x.com')->pluck('id')->all());
    }

    public function test_remove_email_folds_non_ascii_case_like_the_lookup(): void
    {
        $row = AlertSetting::factory()->emailNotification(['ÉMILE@x.com', 'b@x.com'])->create();

        $row->removeEmail('émile@x.com');

        $this->assertSame(['b@x.com'], $row->fresh()->routeNotificationForMail()->all());
    }

    public function test_an_evicted_version_key_cannot_resurrect_a_stale_subscriber_list(): void
    {
        $this->freezeTime();
        Cache::flush();

        $user = $this->createUser(pro: true);
        $alertSetting = AlertSetting::factory()->forUser($user)->emailNotification(['a@x.com'])->create();
        $hit = Hit::factory()->live()->forOrg($user->org)->create();

        $this->assertSame(['a@x.com'], $hit->getSubscribers()->first()->routeNotificationForMail()->all());

        Cache::forget(AlertSetting::subscriberCacheVersionKey($user->org_id));
        $alertSetting->settings->notifications['emails'] = ['z@x.com'];
        $alertSetting->save();

        $this->assertSame(['z@x.com'], $hit->getSubscribers()->first()->routeNotificationForMail()->all());

        Cache::forget(AlertSetting::subscriberCacheVersionKey($user->org_id));
        DB::table('alert_settings')->where('id', $alertSetting->id)->delete();

        $this->assertCount(0, $hit->getSubscribers());
    }
}
