<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\AlertSettingSettingsDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class AlertSettingSettingsDtoTest extends TestCase
{
    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_constructor_sets_default_values(): void
    {
        $dto = new AlertSettingSettingsDto;

        $this->assertEquals([], $dto->types);
        $this->assertEquals([], $dto->clients);
        $this->assertEquals([
            'sms' => [],
            'emails' => [],
            'slack' => [],
        ], $dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_constructor_accepts_custom_values(): void
    {
        $dto = new AlertSettingSettingsDto(
            types: ['live', 'historical'],
            clients: ['client1', 'client2'],
            notifications: [
                'sms' => ['+1234567890'],
                'emails' => ['admin@example.com'],
                'slack' => ['#alerts'],
            ]
        );

        $this->assertEquals(['live', 'historical'], $dto->types);
        $this->assertEquals(['client1', 'client2'], $dto->clients);
        $this->assertEquals([
            'sms' => ['+1234567890'],
            'emails' => ['admin@example.com'],
            'slack' => ['#alerts'],
        ], $dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_with_full_data(): void
    {
        $data = [
            'types' => ['live', 'historical'],
            'clients' => ['client1', 'client2'],
            'notifications' => [
                'sms' => ['+1234567890', '+0987654321'],
                'emails' => ['admin@example.com', 'test@example.com'],
                'slack' => ['#alerts', '#notifications'],
            ],
        ];

        $dto = AlertSettingSettingsDto::fromArray($data);

        $this->assertEquals(['live', 'historical'], $dto->types);
        $this->assertEquals(['client1', 'client2'], $dto->clients);
        $this->assertEquals([
            'sms' => ['+1234567890', '+0987654321'],
            'emails' => ['admin@example.com', 'test@example.com'],
            'slack' => ['#alerts', '#notifications'],
        ], $dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_with_missing_data(): void
    {
        $data = [
            'types' => ['live'],
            // Missing clients and notifications
        ];

        $dto = AlertSettingSettingsDto::fromArray($data);

        $this->assertEquals(['live'], $dto->types);
        $this->assertEquals([], $dto->clients);
        $this->assertEquals([
            'sms' => [],
            'emails' => [],
            'slack' => [],
        ], $dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_with_partial_notifications(): void
    {
        $data = [
            'types' => ['live'],
            'clients' => ['client1'],
            'notifications' => [
                'sms' => ['+1234567890'],
                // Missing emails and slack
            ],
        ];

        $dto = AlertSettingSettingsDto::fromArray($data);

        $this->assertEquals(['live'], $dto->types);
        $this->assertEquals(['client1'], $dto->clients);
        $this->assertEquals([
            'sms' => ['+1234567890'],
            'emails' => [],
            'slack' => [],
        ], $dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_with_empty_array(): void
    {
        $dto = AlertSettingSettingsDto::fromArray([]);

        $this->assertEquals([], $dto->types);
        $this->assertEquals([], $dto->clients);
        $this->assertEquals([
            'sms' => [],
            'emails' => [],
            'slack' => [],
        ], $dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_to_json_returns_valid_json(): void
    {
        $dto = new AlertSettingSettingsDto(
            types: ['live', 'historical'],
            clients: ['client1'],
            notifications: [
                'sms' => ['+1234567890'],
                'emails' => ['admin@example.com'],
                'slack' => ['#alerts'],
            ]
        );

        $json = $dto->toJson();

        $this->assertIsString($json);
        $decodedJson = json_decode($json, true);
        $this->assertIsArray($decodedJson);
        $this->assertEquals(['live', 'historical'], $decodedJson['types']);
        $this->assertEquals(['client1'], $decodedJson['clients']);
        $this->assertEquals([
            'sms' => ['+1234567890'],
            'emails' => ['admin@example.com'],
            'slack' => ['#alerts'],
        ], $decodedJson['notifications']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_to_json_with_empty_dto(): void
    {
        $dto = new AlertSettingSettingsDto;

        $json = $dto->toJson();

        $this->assertIsString($json);
        $decodedJson = json_decode($json, true);
        $this->assertIsArray($decodedJson);
        $this->assertEquals([], $decodedJson['types']);
        $this->assertEquals([], $decodedJson['clients']);
        $this->assertEquals([
            'sms' => [],
            'emails' => [],
            'slack' => [],
        ], $decodedJson['notifications']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_round_trip_from_array_to_json(): void
    {
        $originalData = [
            'types' => ['live', 'historical'],
            'clients' => ['client1', 'client2'],
            'notifications' => [
                'sms' => ['+1234567890'],
                'emails' => ['admin@example.com', 'test@example.com'],
                'slack' => ['#alerts', '#notifications'],
            ],
            'webhook_url' => 'https://example.com/hooks/clarity',
        ];

        $dto = AlertSettingSettingsDto::fromArray($originalData);
        $json = $dto->toJson();
        $decodedData = json_decode($json, true);

        $this->assertEquals($originalData, $decodedData);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_webhook_url_defaults_to_empty_string_and_round_trips(): void
    {
        $this->assertSame('', (new AlertSettingSettingsDto)->webhook_url);
        $this->assertSame('', AlertSettingSettingsDto::fromArray([])->webhook_url);

        $dto = AlertSettingSettingsDto::fromArray(['webhook_url' => 'https://example.com/hook']);
        $this->assertSame('https://example.com/hook', $dto->webhook_url);
        $this->assertSame('https://example.com/hook', json_decode($dto->toJson(), true)['webhook_url']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_notifications_structure_is_always_consistent(): void
    {
        // Test that notifications always have the expected keys
        $dto1 = AlertSettingSettingsDto::fromArray([]);
        $dto2 = AlertSettingSettingsDto::fromArray(['notifications' => ['sms' => ['123']]]);

        foreach ([$dto1, $dto2] as $dto) {
            $this->assertArrayHasKey('sms', $dto->notifications);
            $this->assertArrayHasKey('emails', $dto->notifications);
            $this->assertArrayHasKey('slack', $dto->notifications);
            $this->assertIsArray($dto->notifications['sms']);
            $this->assertIsArray($dto->notifications['emails']);
            $this->assertIsArray($dto->notifications['slack']);
        }
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_properties_are_public(): void
    {
        $dto = new AlertSettingSettingsDto(
            types: ['type1'],
            clients: ['client1'],
            notifications: ['sms' => ['123']]
        );

        // Should be able to access properties directly
        $this->assertEquals(['type1'], $dto->types);
        $this->assertEquals(['client1'], $dto->clients);
        $this->assertIsArray($dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_turns_gapped_and_object_shaped_lists_into_lists(): void
    {
        $dto = AlertSettingSettingsDto::fromArray([
            'types' => [0 => 'L', 2 => 'S'],
            'clients' => ['a' => 1, 'b' => 2],
            'notifications' => [
                'sms' => [1 => '5551112222', 3 => '5553334444'],
                'emails' => ['x' => 'a@x.com', 'y' => 'b@x.com'],
                'slack' => [2 => 'https://hooks.slack.com/services/T/B/X'],
            ],
        ]);

        $this->assertSame(['L', 'S'], $dto->types);
        $this->assertSame([1, 2], $dto->clients);
        $this->assertSame(['5551112222', '5553334444'], $dto->notifications['sms']);
        $this->assertSame(['a@x.com', 'b@x.com'], $dto->notifications['emails']);
        $this->assertSame(['https://hooks.slack.com/services/T/B/X'], $dto->notifications['slack']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_maps_empty_and_non_scalar_channel_values_to_empty_lists(): void
    {
        foreach ([null, '', true] as $value) {
            $dto = AlertSettingSettingsDto::fromArray([
                'types' => $value,
                'clients' => $value,
                'notifications' => ['sms' => $value, 'emails' => $value, 'slack' => $value],
            ]);

            $this->assertSame([], $dto->types);
            $this->assertSame([], $dto->clients);
            $this->assertSame(['sms' => [], 'emails' => [], 'slack' => []], $dto->notifications);
        }
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_maps_non_empty_scalar_channel_values_to_empty_lists(): void
    {
        $dto = AlertSettingSettingsDto::fromArray([
            'types' => 'L',
            'clients' => 5,
            'notifications' => ['sms' => '555', 'emails' => 'a@x.com', 'slack' => 5],
        ]);

        $this->assertSame([], $dto->types);
        $this->assertSame([], $dto->clients);
        $this->assertSame(['sms' => [], 'emails' => [], 'slack' => []], $dto->notifications);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_drops_null_and_empty_members(): void
    {
        $dto = AlertSettingSettingsDto::fromArray([
            'notifications' => ['emails' => [0 => null, 1 => 'a@x.com', 2 => '']],
        ]);

        $this->assertSame(['a@x.com'], $dto->notifications['emails']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_coerces_non_string_webhook_url_to_empty_string(): void
    {
        foreach ([['x'], true, 5, null] as $value) {
            $this->assertSame('', AlertSettingSettingsDto::fromArray(['webhook_url' => $value])->webhook_url);
        }

        $this->assertSame('https://e.com/h', AlertSettingSettingsDto::fromArray(['webhook_url' => 'https://e.com/h'])->webhook_url);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_from_array_with_non_array_notifications_yields_empty_channels(): void
    {
        foreach (['x', 5, null, true] as $value) {
            $dto = AlertSettingSettingsDto::fromArray(['notifications' => $value]);

            $this->assertSame(['sms' => [], 'emails' => [], 'slack' => []], $dto->notifications);
        }
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_to_json_emits_lists_after_direct_mutation(): void
    {
        $dto = new AlertSettingSettingsDto(types: [1 => 'L'], clients: [2 => 'c']);
        $dto->notifications['emails'] = [1 => 'b@x.com'];
        $dto->notifications['sms'] = ['a' => '555'];

        $decoded = json_decode($dto->toJson(), true);

        $this->assertSame(['L'], $decoded['types']);
        $this->assertSame(['c'], $decoded['clients']);
        $this->assertSame(['b@x.com'], $decoded['notifications']['emails']);
        $this->assertSame(['555'], $decoded['notifications']['sms']);
        $this->assertSame([], $decoded['notifications']['slack']);
        $this->assertStringContainsString('"emails":["b@x.com"]', $dto->toJson());
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_normalisation_round_trip_is_idempotent_and_keeps_webhook_url(): void
    {
        $dto = AlertSettingSettingsDto::fromArray([
            'types' => [1 => 'L'],
            'notifications' => ['emails' => [2 => 'b@x.com']],
            'webhook_url' => 'https://example.com/hook',
        ]);

        $first = $dto->toJson();
        $second = AlertSettingSettingsDto::fromArray(json_decode($first, true))->toJson();

        $this->assertSame($first, $second);
        $this->assertSame('https://example.com/hook', json_decode($second, true)['webhook_url']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_to_list_keeps_only_non_empty_strings_and_numbers_as_text(): void
    {
        $dto = AlertSettingSettingsDto::fromArray([
            'notifications' => [
                'sms' => [5551112222, '', null, false, true, ['x'], '5553334444'],
                'emails' => [[''], false],
                'slack' => ['a' => null, 'b' => ''],
            ],
        ]);

        $this->assertSame(['5551112222', '5553334444'], $dto->notifications['sms']);
        $this->assertSame([], $dto->notifications['emails']);
        $this->assertSame([], $dto->notifications['slack']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_numeric_members_of_a_list_become_text_entries(): void
    {
        $dto = AlertSettingSettingsDto::fromArray(['notifications' => ['sms' => [5551112222], 'emails' => [1.5]]]);

        $this->assertSame(['5551112222'], $dto->notifications['sms']);
        $this->assertSame(['1.5'], $dto->notifications['emails']);
    }

    #[Group('dto')]
    #[Group('alert-settings')]
    public function test_unset_personal_contact_never_leaves_a_null_member(): void
    {
        $dto = AlertSettingSettingsDto::fromArray(['notifications' => ['sms' => [null], 'emails' => [null, 'a@x.com']]]);

        $this->assertSame('{"sms":[],"emails":["a@x.com"],"slack":[]}', json_encode(json_decode($dto->toJson(), true)['notifications']));
    }
}
