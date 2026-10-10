<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\AlertSettingsCast;
use App\Dto\AlertSettingSettingsDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;

class AlertSettingsCastTest extends TestCase
{
    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_get_with_valid_json_string(): void
    {
        $jsonValue = json_encode([
            'types' => ['type1', 'type2'],
            'clients' => ['client1', 'client2'],
            'notifications' => [
                'sms' => ['123456'],
                'emails' => ['test@example.com'],
                'slack' => ['#channel1'],
            ],
        ]);

        $cast = new AlertSettingsCast;
        $result = $cast->get(new stdClass, 'settings', $jsonValue, []);

        $this->assertInstanceOf(AlertSettingSettingsDto::class, $result);
        $this->assertEquals(['type1', 'type2'], $result->types);
        $this->assertEquals(['client1', 'client2'], $result->clients);
        $this->assertEquals(['123456'], $result->notifications['sms']);
        $this->assertEquals(['test@example.com'], $result->notifications['emails']);
        $this->assertEquals(['#channel1'], $result->notifications['slack']);
    }

    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_get_with_partial_data(): void
    {
        $jsonValue = json_encode([
            'types' => ['type1'],
            // Missing clients and notifications
        ]);

        $cast = new AlertSettingsCast;
        $result = $cast->get(new stdClass, 'settings', $jsonValue, []);

        $this->assertInstanceOf(AlertSettingSettingsDto::class, $result);
        $this->assertEquals(['type1'], $result->types);
        $this->assertEquals([], $result->clients);
        $this->assertEquals(['sms' => [], 'emails' => [], 'slack' => []], $result->notifications);
    }

    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_set_with_alert_setting_settings_dto(): void
    {
        $dto = new AlertSettingSettingsDto(
            types: ['type1', 'type2'],
            clients: ['client1'],
            notifications: [
                'sms' => ['123456'],
                'emails' => ['test@example.com'],
                'slack' => ['#general'],
            ]
        );

        $cast = new AlertSettingsCast;
        $result = $cast->set(new stdClass, 'settings', $dto, []);

        $this->assertIsString($result);
        $decodedResult = json_decode($result, true);
        $this->assertEquals(['type1', 'type2'], $decodedResult['types']);
        $this->assertEquals(['client1'], $decodedResult['clients']);
        $this->assertEquals(['123456'], $decodedResult['notifications']['sms']);
        $this->assertEquals(['test@example.com'], $decodedResult['notifications']['emails']);
        $this->assertEquals(['#general'], $decodedResult['notifications']['slack']);
    }

    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_set_with_array(): void
    {
        $arrayValue = [
            'types' => ['type1'],
            'clients' => ['client1'],
        ];

        $cast = new AlertSettingsCast;
        $result = $cast->set(new stdClass, 'settings', $arrayValue, []);

        $this->assertIsString($result);
        $decodedResult = json_decode($result, true);
        $this->assertEquals(['type1'], $decodedResult['types']);
        $this->assertEquals(['client1'], $decodedResult['clients']);
    }

    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_handles_incomplete_notifications_structure(): void
    {
        $jsonValue = json_encode([
            'types' => ['type1'],
            'clients' => ['client1'],
            'notifications' => [
                'sms' => ['123456'],
                // Missing emails and slack
            ],
        ]);

        $cast = new AlertSettingsCast;
        $result = $cast->get(new stdClass, 'settings', $jsonValue, []);

        $this->assertInstanceOf(AlertSettingSettingsDto::class, $result);
        $this->assertEquals(['123456'], $result->notifications['sms']);
        $this->assertEquals([], $result->notifications['emails']);
        $this->assertEquals([], $result->notifications['slack']);
    }

    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_get_with_non_array_json_returns_default_dto(): void
    {
        $cast = new AlertSettingsCast;

        foreach (['null', '"str"', '5'] as $value) {
            $result = $cast->get(new stdClass, 'settings', $value, []);

            $this->assertInstanceOf(AlertSettingSettingsDto::class, $result);
            $this->assertSame([], $result->types);
            $this->assertSame(['sms' => [], 'emails' => [], 'slack' => []], $result->notifications);
        }
    }

    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_set_with_gapped_dto_writes_json_lists(): void
    {
        $dto = new AlertSettingSettingsDto(
            types: [1 => 'L'],
            notifications: [
                'sms' => [1 => '555'],
                'emails' => [2 => 'a@x.com'],
                'slack' => [3 => '#c'],
            ],
        );

        $result = (new AlertSettingsCast)->set(new stdClass, 'settings', $dto, []);
        $decoded = json_decode($result, true);

        $this->assertSame(['L'], $decoded['types']);
        $this->assertSame(['555'], $decoded['notifications']['sms']);
        $this->assertSame(['a@x.com'], $decoded['notifications']['emails']);
        $this->assertSame(['#c'], $decoded['notifications']['slack']);
    }

    #[Group('casts')]
    #[Group('alert-settings')]
    public function test_set_with_raw_gapped_array_writes_json_lists(): void
    {
        $result = (new AlertSettingsCast)->set(new stdClass, 'settings', [
            'types' => [1 => 'L'],
            'notifications' => ['emails' => [2 => 'a@x.com'], 'sms' => ['x' => '555']],
        ], []);

        $decoded = json_decode($result, true);

        $this->assertSame(['L'], $decoded['types']);
        $this->assertSame(['a@x.com'], $decoded['notifications']['emails']);
        $this->assertSame(['555'], $decoded['notifications']['sms']);
        $this->assertSame([], $decoded['notifications']['slack']);
    }
}
