<?php

namespace Tests\Feature\Dto;

use App\Dto\ScanMetaDataDto;
use App\Enums\DispositionEnum;
use App\Models\Scan;
use InsightLPR\BlackAdam\Enum\ScanMetaType;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ScanMetaDataDtoTest extends TestCase
{
    #[Group('dto')]
    public function test_creates_default_dto(): void
    {
        $dto = ScanMetaDataDto::fromArray([]);

        $this->assertObjectHasProperty('vehicle', $dto);

        $this->assertObjectHasProperty('type', $dto->vehicle);
        $this->assertNull($dto->vehicle->type);

        $this->assertObjectHasProperty('make', $dto->vehicle);
        $this->assertNull($dto->vehicle->make);

        $this->assertObjectHasProperty('color', $dto->vehicle);
        $this->assertNull($dto->vehicle->color);

        $this->assertObjectHasProperty('notPlate', $dto);
        $this->assertFalse($dto->notPlate);

        $this->assertObjectHasProperty('address', $dto);
        $this->assertNull($dto->address);

        $this->assertObjectHasProperty('simpleAddress', $dto);
        $this->assertNull($dto->simpleAddress);

        $this->assertObjectHasProperty('plateState', $dto);
        $this->assertNull($dto->plateState);

        $this->assertObjectHasProperty('disposition', $dto);
        $this->assertNull($dto->disposition);

        $this->assertObjectHasProperty('is_daytime', $dto);
        $this->assertNull($dto->is_daytime);
    }

    #[Group('dto')]
    public function test_converts_to_json(): void
    {
        $address = fake()->words(3, true);
        $simpleAddress = fake()->words(2, true);
        $plateState = fake()->words(1, true);

        $dto = ScanMetaDataDto::fromArray([
            'vehicle' => [
                'type' => 'Sedan',
                'make' => 'Toyota',
                'color' => 'Blue',
            ],
            'notPlate' => false,
            'address' => $address,
            'simpleAddress' => $simpleAddress,
            'plateState' => $plateState,
            'disposition' => DispositionEnum::ACCESSIBLE->value,
        ]);

        $json = $dto->toJson();

        $this->assertStringContainsString('"'.ScanMetaType::VehicleType->value.'":"Sedan"', $json);
        $this->assertStringContainsString('"'.ScanMetaType::VehicleColor->value.'":"Blue"', $json);
        $this->assertStringContainsString('"'.ScanMetaType::VehicleMake->value.'":"Toyota"', $json);
        $this->assertStringContainsString('"'.ScanMetaType::MLPlateNotPlate->value.'":false', $json);
        $this->assertStringContainsString('"'.ScanMetaType::FormattedAddress->value.'":"'.$address.'"', $json);
        $this->assertStringContainsString('"'.ScanMetaType::MLPlateState->value.'":"'.$plateState.'"', $json);
        $this->assertStringContainsString('"8":"'.$simpleAddress.'"', $json);
        $this->assertStringContainsString('"disposition":"'.DispositionEnum::ACCESSIBLE->value.'"', $json);
    }

    #[Group('dto')]
    public function test_filters_out_null_values_on_json(): void
    {
        $address = fake()->words(3, true);

        $dto = ScanMetaDataDto::fromArray([
            'disposition' => DispositionEnum::MOVING->value,
            'address' => $address,
        ]);

        $json = $dto->toJson();

        $this->assertStringContainsString('"disposition":"'.DispositionEnum::MOVING->value.'"', $json);
        $this->assertStringContainsString('"'.ScanMetaType::FormattedAddress->value.'":"'.$address.'"', $json);

        $this->assertStringNotContainsString('"simpleAddress"', $json);
        $this->assertStringNotContainsString('"'.ScanMetaType::VehicleType->value.'"', $json);
        $this->assertStringNotContainsString('"'.ScanMetaType::VehicleColor->value.'"', $json);
        $this->assertStringNotContainsString('"'.ScanMetaType::VehicleMake->value.'"', $json);
        $this->assertStringNotContainsString('"'.ScanMetaType::MLPlateState->value.'"', $json);
        // notPlate is always present in JSON (defaults to false); omit null-filter assertion for it.
        $this->assertStringContainsString('"'.ScanMetaType::MLPlateNotPlate->value.'":false', $json);
    }

    #[Group('dto1')]
    public function test_update_disposition_preserves_extra_meta_properties(): void
    {
        $user = $this->createUser();
        $scan = Scan::factory()->forOrg($user->org)->create();

        // Manually set meta with extra properties the DTO doesn't know about
        $rawMeta = [
            '1' => 'Sedan',
            '2' => 'Blue',
            'disposition' => 'valid',
            'custom_field' => 'important_data',
            'another_extra' => 42,
        ];
        $scan->scanMetaData->update(['meta' => $rawMeta]);

        $scan->scanMetaData->refresh();
        $stored = \json_decode($scan->scanMetaData->getRawOriginal('meta'), true);

        $this->assertEquals('valid', $stored['disposition']);
        $this->assertEquals('important_data', $stored['custom_field']);
        $this->assertEquals(42, $stored['another_extra']);
        $this->assertEquals('Sedan', $stored['1']);
        $this->assertEquals('Blue', $stored['2']);

        $scan->updateDisposition('invalid', $user, 'other');

        $scan->scanMetaData->refresh();
        $stored = \json_decode($scan->scanMetaData->getRawOriginal('meta'), true);

        $this->assertEquals('invalid', $stored['disposition']);
        $this->assertEquals('important_data', $stored['custom_field']);
        $this->assertEquals(42, $stored['another_extra']);
        $this->assertEquals('Sedan', $stored['1']);
        $this->assertEquals('Blue', $stored['2']);
    }

    #[Group('dto1')]
    public function test_update_address_preserves_extra_meta_properties(): void
    {
        $user = $this->createUser();
        $scan = Scan::factory()->forOrg($user->org)->create();

        $rawMeta = [
            '1' => 'SUV',
            'address' => '123 Old St',
            'custom_field' => 'keep_me',
        ];
        $scan->scanMetaData->update(['meta' => $rawMeta]);

        $scan->updateAddress('456 New Ave', 'New Ave');

        $scan->scanMetaData->refresh();
        $stored = \json_decode($scan->scanMetaData->getRawOriginal('meta'), true);

        $this->assertEquals('456 New Ave', $stored['address']);
        $this->assertEquals('New Ave', $stored['simpleAddress']);
        $this->assertEquals('keep_me', $stored['custom_field']);
        $this->assertEquals('SUV', $stored['1']);
    }
}
