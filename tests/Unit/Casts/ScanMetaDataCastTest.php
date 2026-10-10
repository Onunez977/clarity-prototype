<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\ScanMetaDataCast;
use App\Dto\ScanMetaDataDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;

class ScanMetaDataCastTest extends TestCase
{
    #[Group('casts')]
    #[Group('scans')]
    public function test_get_with_valid_json_string(): void
    {
        $jsonValue = json_encode([
            'vehicle' => (object) ['type' => 'sedan', 'make' => 'Toyota', 'color' => 'blue'],
            'notPlate' => false,
            'address' => '123 Main St',
            'simpleAddress' => 'Main St',
            'plateState' => 'CA',
            'disposition' => 'valid',
        ]);

        $cast = new ScanMetaDataCast;
        $result = $cast->get(new stdClass, 'metadata', $jsonValue, []);

        $this->assertInstanceOf(ScanMetaDataDto::class, $result);
        $this->assertEquals('sedan', $result->vehicle->type);
        $this->assertEquals('Toyota', $result->vehicle->make);
        $this->assertEquals('blue', $result->vehicle->color);
        $this->assertFalse($result->notPlate);
        $this->assertEquals('123 Main St', $result->address);
        $this->assertEquals('Main St', $result->simpleAddress);
        $this->assertEquals('CA', $result->plateState);
        $this->assertEquals('valid', $result->disposition);
    }

    #[Group('casts')]
    #[Group('scans')]
    public function test_get_with_numeric_keys(): void
    {
        // Test with data using numeric keys (legacy format style)
        $jsonValue = json_encode([
            1 => false,
            7 => 'Simple Address Test',
            'plateState' => 'TX',
            'disposition' => 'test',
        ]);

        $cast = new ScanMetaDataCast;
        $result = $cast->get(new stdClass, 'metadata', $jsonValue, []);

        $this->assertInstanceOf(ScanMetaDataDto::class, $result);
        // Check that the DTO can handle mixed key formats
        $this->assertIsObject($result->vehicle);
        $this->assertEquals('TX', $result->plateState);
        $this->assertEquals('test', $result->disposition);
        $this->assertEquals('Simple Address Test', $result->simpleAddress);
    }

    #[Group('casts')]
    #[Group('scans')]
    public function test_get_with_partial_data(): void
    {
        $jsonValue = json_encode([
            'address' => '456 Oak Ave',
            'plateState' => 'NY',
            // Missing other fields
        ]);

        $cast = new ScanMetaDataCast;
        $result = $cast->get(new stdClass, 'metadata', $jsonValue, []);

        $this->assertInstanceOf(ScanMetaDataDto::class, $result);
        $this->assertIsObject($result->vehicle);
        $this->assertFalse($result->notPlate);
        $this->assertEquals('456 Oak Ave', $result->address);
        $this->assertNull($result->simpleAddress);
        $this->assertEquals('NY', $result->plateState);
        $this->assertNull($result->disposition);
    }

    #[Group('casts')]
    #[Group('scans')]
    public function test_set_with_scan_meta_data_dto(): void
    {
        $dto = new ScanMetaDataDto(
            vehicle: (object) ['type' => 'SUV', 'make' => 'Honda', 'color' => 'red'],
            notPlate: true,
            address: '789 Pine St',
            simpleAddress: 'Pine St',
            plateState: 'TX',
            disposition: 'reviewed',
            is_daytime: null
        );

        $cast = new ScanMetaDataCast;
        $result = $cast->set(new stdClass, 'metadata', $dto, []);

        $this->assertIsString($result);
        $decodedResult = json_decode($result, true);

        // Check that null values are filtered out in toJson method
        $this->assertArrayHasKey('disposition', $decodedResult);
        $this->assertEquals('reviewed', $decodedResult['disposition']);
    }

    #[Group('casts')]
    #[Group('scans')]
    public function test_set_with_array(): void
    {
        $arrayValue = [
            'address' => '999 Elm St',
            'plateState' => 'FL',
        ];

        $cast = new ScanMetaDataCast;
        $result = $cast->set(new stdClass, 'metadata', $arrayValue, []);

        $this->assertIsString($result);
        $decodedResult = json_decode($result, true);
        $this->assertEquals('999 Elm St', $decodedResult['address']);
        $this->assertEquals('FL', $decodedResult['plateState']);
    }

    #[Group('casts')]
    #[Group('scans')]
    public function test_handles_empty_vehicle_object(): void
    {
        $jsonValue = json_encode([
            'vehicle' => (object) [],
            'plateState' => 'OR',
        ]);

        $cast = new ScanMetaDataCast;
        $result = $cast->get(new stdClass, 'metadata', $jsonValue, []);

        $this->assertInstanceOf(ScanMetaDataDto::class, $result);
        $this->assertIsObject($result->vehicle);
        $this->assertEquals('OR', $result->plateState);
    }

    #[Group('casts')]
    #[Group('scans')]
    public function test_handles_boolean_not_plate_values(): void
    {
        $jsonValue = json_encode([
            'notPlate' => true,
        ]);

        $cast = new ScanMetaDataCast;
        $result = $cast->get(new stdClass, 'metadata', $jsonValue, []);

        $this->assertInstanceOf(ScanMetaDataDto::class, $result);
        $this->assertTrue($result->notPlate);

        $jsonValue2 = json_encode([
            'notPlate' => false,
        ]);

        $cast2 = new ScanMetaDataCast;
        $result2 = $cast2->get(new stdClass, 'metadata', $jsonValue2, []);
        $this->assertFalse($result2->notPlate);
    }
}
