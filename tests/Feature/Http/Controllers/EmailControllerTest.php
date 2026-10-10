<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AlertSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmailControllerTest extends TestCase
{
    #[Group('controllers')]
    #[Group('email')]
    public function test_can_remove_single_email_from_alert(): void
    {
        $email = fake()->safeEmail();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$email])
            ->create();

        $this->assertContains($email, $alertSetting->routeNotificationForMail());

        $signedUrl = URL::temporarySignedRoute(
            'email.unsubscribe.alert',
            now()->addDay(),
            [
                'alert_setting_id' => $alertSetting->id,
                'email' => $email,
            ]
        );

        $this->get($signedUrl)
            ->assertStatus(200)
            ->assertSeeText('You have been unsubscribed from this alert.');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Alert Settings Unsubscribe',
            'description' => 'Unsubscribed from alert',
        ]);

        $activity = Activity::query()
            ->where('log_name', 'Alert Settings Unsubscribe')
            ->first();

        $this->assertArrayHasKey('email', $activity->properties);
        $this->assertEquals($email, $activity->properties['email']);

        $this->assertNotContains($email, $alertSetting->fresh()->routeNotificationForMail());
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_cannot_remove_single_email_from_expired_link(): void
    {
        $email = fake()->safeEmail();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$email])
            ->create();

        $this->assertContains($email, $alertSetting->routeNotificationForMail());

        $signedUrl = URL::temporarySignedRoute(
            'email.unsubscribe.alert',
            now()->subDay(),
            [
                'alert_setting_id' => $alertSetting->id,
                'email' => $email,
            ]
        );

        $this->get($signedUrl)
            ->assertStatus(403)
            ->assertDontSeeText('You have been unsubscribed from this alert.');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'Alert Settings Unsubscribe',
            'description' => 'Unsubscribed from alert',
        ]);

        // Still there
        $this->assertContains($email, $alertSetting->fresh()->routeNotificationForMail());
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_cannot_remove_single_email_from_invalid_alert(): void
    {
        $email = fake()->safeEmail();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$email])
            ->create();

        $this->assertContains($email, $alertSetting->routeNotificationForMail());

        $alertSetting->delete();

        $signedUrl = URL::temporarySignedRoute(
            'email.unsubscribe.alert',
            now()->addDay(),
            [
                'alert_setting_id' => $alertSetting->id,
                'email' => $email,
            ]
        );

        $this->get($signedUrl)
            ->assertStatus(404)
            ->assertDontSeeText('You have been unsubscribed from this alert.');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'Alert Settings Unsubscribe',
            'description' => 'Unsubscribed from alert',
        ]);
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_can_remove_email_from_all_alerts(): void
    {
        $email = fake()->safeEmail();

        $alertSetting1 = AlertSetting::factory()
            ->emailNotification([$email])
            ->create();

        $alertSetting2 = AlertSetting::factory()
            ->emailNotification([$email])
            ->create();

        $this->assertContains($email, $alertSetting1->routeNotificationForMail());
        $this->assertContains($email, $alertSetting2->routeNotificationForMail());

        $signedUrl = URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->addDay(),
            [
                'email' => $email,
            ]
        );

        $this->get($signedUrl)
            ->assertStatus(200)
            ->assertSeeText('You have been unsubscribed from 2 alerts.');

        $activities = Activity::query()
            ->where('log_name', 'Alert Settings Unsubscribe')
            ->where('description', 'Unsubscribed from all alerts')
            ->get();

        $this->assertCount(2, $activities);

        foreach ([$alertSetting1, $alertSetting2] as $alertSetting) {
            $activity = $activities->firstWhere('subject_id', $alertSetting->id);

            $this->assertNotNull($activity);
            $this->assertSame($alertSetting->getMorphClass(), $activity->subject_type);
            $this->assertSame($email, $activity->properties['email']);
        }

        $this->assertNotContains($email, $alertSetting1->fresh()->routeNotificationForMail());
        $this->assertNotContains($email, $alertSetting2->fresh()->routeNotificationForMail());
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_cannot_remove_email_from_alerts_with_expired_link(): void
    {
        $email = fake()->safeEmail();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$email])
            ->create();

        $this->assertContains($email, $alertSetting->routeNotificationForMail());

        $signedUrl = URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->subDay(),
            [
                'email' => $email,
            ]
        );

        $this->get($signedUrl)
            ->assertStatus(403)
            ->assertDontSeeText('You have been unsubscribed from 0 alerts.');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'Alert Settings Unsubscribe',
            'description' => 'Unsubscribed from all alerts',
        ]);

        // Still there
        $this->assertContains($email, $alertSetting->fresh()->routeNotificationForMail());
    }

    private function rawEmails(AlertSetting $alertSetting): array
    {
        $settings = DB::table('alert_settings')->where('id', $alertSetting->id)->value('settings');

        return \json_decode($settings, true)['notifications']['emails'];
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_unsubscribing_first_or_middle_email_keeps_a_json_list(): void
    {
        foreach (['a@x.com', 'b@x.com'] as $removed) {
            $alertSetting = AlertSetting::factory()
                ->emailNotification(['a@x.com', 'b@x.com', 'c@x.com'])
                ->create();

            $signedUrl = URL::temporarySignedRoute(
                'email.unsubscribe.alert',
                now()->addDay(),
                ['alert_setting_id' => $alertSetting->id, 'email' => $removed]
            );

            $this->get($signedUrl)->assertStatus(200);

            $emails = $this->rawEmails($alertSetting);

            $this->assertTrue(\array_is_list($emails));
            $this->assertNotContains($removed, $emails);
            $this->assertCount(2, $emails);
        }
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_unsubscribe_all_still_finds_the_row_after_a_prior_removal(): void
    {
        $alertSetting = AlertSetting::factory()
            ->emailNotification(['a@x.com', 'b@x.com', 'c@x.com'])
            ->create();

        $this->get(URL::temporarySignedRoute(
            'email.unsubscribe.alert',
            now()->addDay(),
            ['alert_setting_id' => $alertSetting->id, 'email' => 'a@x.com']
        ))->assertStatus(200);

        $this->get(URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->addDay(),
            ['email' => 'c@x.com']
        ))
            ->assertStatus(200)
            ->assertSeeText('You have been unsubscribed from 1 alerts.');

        $this->assertSame(['b@x.com'], $this->rawEmails($alertSetting));
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_unsubscribe_all_finds_and_cleans_object_shaped_email_lists(): void
    {
        $alertSetting = AlertSetting::factory()->emailNotification(['x@x.com'])->create();

        DB::table('alert_settings')->where('id', $alertSetting->id)->update([
            'settings' => '{"types":["L"],"clients":[],"notifications":{"sms":[],"emails":{"1":"c@x.com","2":"d@x.com"},"slack":[]}}',
        ]);

        $this->get(URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->addDay(),
            ['email' => 'c@x.com']
        ))
            ->assertStatus(200)
            ->assertSeeText('You have been unsubscribed from 1 alerts.');

        $this->assertSame(['d@x.com'], $this->rawEmails($alertSetting));
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_unsubscribe_all_matches_stored_addresses_case_insensitively(): void
    {
        $mixed = AlertSetting::factory()->emailNotification(['Dalton@X.com', 'keep@x.com'])->create();
        $object = AlertSetting::factory()->emailNotification(['x@x.com'])->create();

        DB::table('alert_settings')->where('id', $object->id)->update([
            'settings' => '{"types":["L"],"clients":[],"notifications":{"sms":[],"emails":{"1":"DALTON@x.com","2":"d@x.com"},"slack":[]}}',
        ]);

        $this->get(URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->addDay(),
            ['email' => 'dalton@x.com']
        ))
            ->assertStatus(200)
            ->assertSeeText('You have been unsubscribed from 2 alerts.');

        $this->assertSame(['keep@x.com'], $this->rawEmails($mixed));
        $this->assertSame(['d@x.com'], $this->rawEmails($object));
    }

    #[Group('controllers')]
    #[Group('email')]
    public function test_unsubscribe_all_logs_nothing_when_no_alert_matches(): void
    {
        $this->get(URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->addDay(),
            ['email' => 'nobody@x.com']
        ))
            ->assertStatus(200)
            ->assertSeeText('You have been unsubscribed from 0 alerts.');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'Alert Settings Unsubscribe',
        ]);
    }
}
