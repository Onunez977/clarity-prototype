<?php

namespace Tests\Filament\Forms;

use App\Enums\HitTypeEnum;
use App\Filament\Forms\AlertSettingsForm;
use App\Models\AlertSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AlertSettingsFormTest extends TestCase
{
    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_can_save_new(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        $this->assertDatabaseCount('alert_settings', 0);

        AlertSettingsForm::save($user, [
            'name' => 'Test Alert',
            'types' => [HitTypeEnum::Live->value],
            'clients' => [],
            'personal_email' => true,
            'personal_phone' => true,
        ]);

        $this->assertDatabaseCount('alert_settings', 1);

        $alert = AlertSetting::query()
            ->where('alertable_type', 'user')
            ->where('alertable_id', $user->id)
            ->first();

        $this->assertNotNull($alert);
        $this->assertEquals('Test Alert', $alert->name);
        $this->assertEquals($user->org_id, $alert->org_id);
        $this->assertEquals([HitTypeEnum::Live->value], $alert->settings->types);

        $this->assertEquals([$user->email], $alert->routeNotificationForMail()->toArray());
        $this->assertEquals(['1234567890'], $alert->routeNotificationForSms()->toArray());
        $this->assertEmpty($alert->routeNotificationForSlack()->toArray());
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_can_update(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create([
                'name' => 'New Alert',
            ]);

        $this->assertEquals('New Alert', $alert->name);
        $this->assertEquals($user->org_id, $alert->org_id);
        $this->assertEquals(['L'], $alert->settings->types);

        $this->assertDatabaseCount('alert_settings', 1);

        AlertSettingsForm::save($user, [
            'name' => 'Updated Alert',
            'types' => [HitTypeEnum::Missed_Opportunity->value],
            'clients' => [],
            'personal_email' => false,
        ], $alert);

        $this->assertDatabaseCount('alert_settings', 1);

        $alert->refresh();

        $this->assertEquals('Updated Alert', $alert->name);
        $this->assertEquals($user->org_id, $alert->org_id);
        $this->assertEquals([HitTypeEnum::Missed_Opportunity->value], $alert->settings->types);
        $this->assertEmpty($alert->routeNotificationForMail()->toArray());
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_will_add_mobile_phone(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => null])
        );

        $this->assertNull($user->mobile_phone);

        $this->assertDatabaseCount('alert_settings', 0);

        AlertSettingsForm::save($user, [
            'name' => 'Phone Alert',
            'types' => ['L'],
            'clients' => [],
            'mobile_phone' => '(123) 456-7890',
        ]);

        $this->assertDatabaseCount('alert_settings', 1);

        $user->refresh();

        $this->assertNotNull($user->mobile_phone);
        $this->assertEquals('(123) 456-7890', $user->mobile_phone);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_can_fill_empty(): void
    {
        $this->actingAs(
            $user = $this->createUser()
        );

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create();

        $filled = AlertSettingsForm::fill($alert);

        $this->assertEquals([
            'name' => $alert->name,
            'types' => $alert->settings->types,
            'personal_email' => false,
            'personal_phone' => false,
            'mobile_phone' => null,
            'clients' => [],
            'slack_webhooks' => [],
            'webhook_url' => '',
            'email_users' => [],
            'external_email_users' => [],
            'sms_users' => [],
            'external_sms_users' => [],
        ], $filled);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_can_fill_with_data(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        $otherUser = User::factory()
            ->forOrg($user->org)
            ->create([
                'mobile_phone' => '0987654321',
            ]);

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([
                $user->email,
                $otherUser->email,
                'external@email.com',
            ])
            ->smsNotification([
                $user->mobile_phone,
                $otherUser->mobile_phone,
                '1-800-555-1234',
            ])
            ->slackNotification([
                'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX',
            ])
            ->create();

        $filled = AlertSettingsForm::fill($alert);

        $this->assertEquals([
            'name' => $alert->name,
            'types' => $alert->settings->types,
            'personal_email' => true,
            'personal_phone' => true,
            'mobile_phone' => '(123) 456-7890',
            'clients' => [],
            'slack_webhooks' => [
                'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX',
            ],
            'webhook_url' => '',
            'email_users' => [
                $otherUser->email,
            ],
            'external_email_users' => [
                'external@email.com',
            ],
            'sms_users' => [
                '0987654321',
            ],
            'external_sms_users' => [
                '18005551234',
            ],
        ], $filled);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_can_format_sms(): void
    {
        $this->actingAs(
            $this->createUser()
        );

        $formatted = AlertSettingsForm::formatSms([
            'sms_users' => [
                '(123) 456-7890',
                '1-800-567-1234',
            ],
            'external_sms_users' => [
                '1234567890', // Duplicate
            ],
        ]);

        // Formatted and removed duplicates.
        $this->assertEquals([
            '1234567890',
            '18005671234',
        ], $formatted);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_adds_own_sms(): void
    {
        $this->actingAs(
            $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        $formatted = AlertSettingsForm::formatSms([
            'personal_phone' => true,
        ]);

        // Formatted and removed duplicates.
        $this->assertEquals([
            '1234567890',
        ], $formatted);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_removes_own_sms_when_toggled_off(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        $formatted = AlertSettingsForm::formatSms([
            'personal_phone' => false,
            'sms_users' => [
                $user->formatted_mobile_phone,
            ],
        ]);

        $this->assertEmpty($formatted);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_can_format_emails(): void
    {
        $this->actingAs(
            $this->createUser()
        );

        $formatted = AlertSettingsForm::formatEmails([
            'email_users' => [
                'dwayne@therock.com',
                'steve@stonecold.com',
            ],
            'external_email_users' => [
                'dwayne@therock.com', // Duplicate
            ],
        ]);

        // Formatted and removed duplicates.
        $this->assertEquals([
            'dwayne@therock.com',
            'steve@stonecold.com',
        ], $formatted);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_adds_own_email(): void
    {
        $this->actingAs(
            $user = $this->createUser()
        );

        $formatted = AlertSettingsForm::formatEmails([
            'personal_email' => true,
        ]);

        // Formatted and removed duplicates.
        $this->assertEquals([
            $user->email,
        ], $formatted);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_removes_own_email_when_toggled_off(): void
    {
        $this->actingAs(
            $user = $this->createUser()
        );

        $formatted = AlertSettingsForm::formatEmails([
            'personal_email' => false,
            'email_users' => [
                $user->email,
            ],
        ]);

        $this->assertEmpty($formatted);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_saves_webhook_url_and_generates_secret(): void
    {
        $this->actingAs(
            $user = $this->createUser()
        );

        AlertSettingsForm::save($user, [
            'name' => 'Webhook Alert',
            'types' => [HitTypeEnum::Live->value],
            'clients' => [],
            'webhook_url' => 'https://example.com/hooks/clarity',
        ]);

        $alert = AlertSetting::query()
            ->where('alertable_type', 'user')
            ->where('alertable_id', $user->id)
            ->first();

        $this->assertSame('https://example.com/hooks/clarity', $alert->settings->webhook_url);
        $this->assertNotNull($alert->webhook_secret);
        $this->assertSame(40, \strlen($alert->webhook_secret));
        $this->assertSame(['https://example.com/hooks/clarity'], $alert->routeNotificationForWebhook()->toArray());
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_does_not_regenerate_webhook_secret_on_update(): void
    {
        $this->actingAs(
            $user = $this->createUser()
        );

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->webhookNotification('https://example.com/hooks/clarity', secret: 'original-secret')
            ->create();

        AlertSettingsForm::save($user, [
            'name' => 'Updated',
            'types' => [HitTypeEnum::Live->value],
            'clients' => [],
            'webhook_url' => 'https://example.com/hooks/updated',
        ], $alert);

        $alert->refresh();

        $this->assertSame('https://example.com/hooks/updated', $alert->settings->webhook_url);
        $this->assertSame('original-secret', $alert->webhook_secret);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_format_webhook_trims_value(): void
    {
        $this->assertSame('https://example.com/hook', AlertSettingsForm::formatWebhook([
            'webhook_url' => '  https://example.com/hook  ',
        ]));

        $this->assertSame('', AlertSettingsForm::formatWebhook([]));
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_can_format_slack(): void
    {
        $this->actingAs(
            $this->createUser()
        );

        $formatted = AlertSettingsForm::formatSlack([
            'slack_webhooks' => [
                'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX',
                'https://invalid.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX', // Bad
                'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX', // Duplicate
            ],
        ]);

        // Formatted and removed duplicates.
        $this->assertEquals([
            'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX',
        ], $formatted);
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_format_methods_return_lists_when_a_middle_entry_is_dropped(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        $sms = AlertSettingsForm::formatSms(['sms_users' => ['1112223333', '1112223333', '4445556666']]);
        $this->assertSame(['1112223333', '4445556666'], $sms);
        $this->assertTrue(\array_is_list($sms));

        $smsPersonalOff = AlertSettingsForm::formatSms([
            'personal_phone' => false,
            'sms_users' => [$user->formatted_mobile_phone, '4445556666'],
        ]);
        $this->assertSame(['4445556666'], $smsPersonalOff);
        $this->assertTrue(\array_is_list($smsPersonalOff));

        $emails = AlertSettingsForm::formatEmails(['email_users' => ['a@x.com', 'a@x.com', 'b@x.com']]);
        $this->assertSame(['a@x.com', 'b@x.com'], $emails);
        $this->assertTrue(\array_is_list($emails));

        $emailsPersonalOff = AlertSettingsForm::formatEmails([
            'personal_email' => false,
            'email_users' => [$user->email, 'b@x.com'],
        ]);
        $this->assertSame(['b@x.com'], $emailsPersonalOff);
        $this->assertTrue(\array_is_list($emailsPersonalOff));
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_format_slack_returns_a_list_when_the_first_url_is_invalid(): void
    {
        $this->actingAs($this->createUser());

        $good = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX';

        $formatted = AlertSettingsForm::formatSlack([
            'slack_webhooks' => ['https://invalid.slack.com/services/T/B/X', $good],
        ]);

        $this->assertSame([$good], $formatted);
        $this->assertTrue(\array_is_list($formatted));
    }

    #[Group('forms')]
    #[Group('alerts')]
    #[Group('alert-settings')]
    public function test_save_persists_every_channel_as_a_json_array(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        AlertSettingsForm::save($user, [
            'name' => 'Lists',
            'types' => [HitTypeEnum::Live->value],
            'clients' => [],
            'personal_phone' => false,
            'personal_email' => false,
            'sms_users' => [$user->formatted_mobile_phone, '4445556666', '4445556666', '7778889999'],
            'email_users' => [$user->email, 'b@x.com', 'b@x.com', 'c@x.com'],
            'slack_webhooks' => [
                'https://invalid.slack.com/services/T/B/X',
                'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXX',
            ],
        ]);

        $alert = AlertSetting::query()->where('alertable_id', $user->id)->firstOrFail();

        foreach (['sms', 'emails', 'slack'] as $channel) {
            $type = DB::selectOne(
                "select jsonb_typeof(settings->'notifications'->'{$channel}') as t from alert_settings where id = ?",
                [$alert->id]
            )->t;

            $this->assertSame('array', $type, $channel);
        }

        $this->assertSame(['4445556666', '7778889999'], $alert->settings->notifications['sms']);
        $this->assertSame(['b@x.com', 'c@x.com'], $alert->settings->notifications['emails']);
    }
}
