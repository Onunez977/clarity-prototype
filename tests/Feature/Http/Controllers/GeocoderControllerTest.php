<?php

namespace Tests\Feature\Http\Controllers;

use App\Dto\ScanMetaDataDto;
use App\Models\Scan;
use Geocodio\Geocodio;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\ScanMetaType;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\HasSuperUser;
use Tests\TestCase;

class GeocoderControllerTest extends TestCase
{
    use HasSuperUser;

    #[Group('controllers')]
    #[Group('geocodio')]
    public function test_can_geocode_address(): void
    {
        $address = Str::replace(['\n', PHP_EOL], '', fake()->address());

        $geocode = [
            'results' => [
                [
                    'formatted_address' => $address,
                    'location' => [
                        'lat' => 0,
                        'lng' => 0,
                    ],
                    'accuracy' => 1,
                ],
            ],
        ];

        $this->instance(
            Geocodio::class,
            Mockery::mock(Geocodio::class, fn (MockInterface $mock) => $mock->shouldReceive('geocode')
                ->once()
                ->withArgs([$address, [], 1])
                ->andReturn($geocode)
            )
        );

        $response = $this->post(route('api.geocode'), [
            'data' => $address,
        ])
            ->assertSuccessful();

        $this->assertEquals($geocode, $response->json());
    }

    #[Group('controllers')]
    #[Group('geocodio')]
    public function test_can_reverse_geocode_coordinates(): void
    {
        $lat = fake()->latitude();
        $lng = fake()->longitude();
        $coordinates = $lat.','.$lng;

        $geocode = [
            'results' => [
                [
                    'formatted_address' => Str::replace(['\n', PHP_EOL], '', fake()->address()),
                    'location' => [
                        'lat' => $lat,
                        'lng' => $lng,
                    ],
                    'accuracy' => 1,
                ],
            ],
        ];

        $this->instance(
            Geocodio::class,
            Mockery::mock(Geocodio::class, fn (MockInterface $mock) => $mock->shouldReceive('reverse')
                ->once()
                ->withArgs([$coordinates, [], 1])
                ->andReturn($geocode)
            )
        );

        $response = $this->post(route('api.geocode.reverse'), [
            'data' => $coordinates,
        ])
            ->assertSuccessful();

        $this->assertEquals($geocode, $response->json());
    }

    #[Group('controllers')]
    #[Group('geocodio')]
    public function test_can_reverse_geocode_and_save_scan_metadata(): void
    {
        $lat = fake()->latitude();
        $lng = fake()->longitude();
        $coordinates = $lat.','.$lng;
        $address = Str::replace(['\n', PHP_EOL], '', fake()->address());

        $geocode = [
            'results' => [
                [
                    'formatted_address' => $address,
                    'location' => [
                        'lat' => $lat,
                        'lng' => $lng,
                    ],
                    'accuracy' => 1,
                ],
            ],
        ];

        $this->instance(
            Geocodio::class,
            Mockery::mock(Geocodio::class, fn (MockInterface $mock) => $mock->shouldReceive('reverse')
                ->once()
                ->withArgs([$coordinates, [], 1])
                ->andReturn($geocode)
            )
        );

        $scan = Scan::factory()->create();

        $response = $this->post(route('api.geocode.reverse'), [
            'data' => $coordinates,
            'scan_id' => $scan->id,
        ])
            ->assertSuccessful();

        $this->assertEquals($geocode, $response->json());

        $scan->refresh();

        $this->assertNotNull($scan->scanMetaData);

        $metaData = $scan->scanMetaData->meta;
        $this->assertInstanceOf(ScanMetaDataDto::class, $metaData);

        $this->assertObjectHasProperty('address', $metaData);
        $this->assertEquals($address, $metaData->address);
    }

    #[Group('controllers')]
    #[Group('geocodio')]
    public function test_can_reverse_geocode_and_update_scan_metadata(): void
    {
        $lat = fake()->latitude();
        $lng = fake()->longitude();
        $coordinates = $lat.','.$lng;

        $address1 = Str::replace(['\n', PHP_EOL], '', fake()->address());
        $address2 = Str::replace(['\n', PHP_EOL], '', fake()->address());

        $geocode = [
            'results' => [
                [
                    'formatted_address' => $address2,
                    'location' => [
                        'lat' => $lat,
                        'lng' => $lng,
                    ],
                    'accuracy' => 1,
                ],
            ],
        ];

        $this->instance(
            Geocodio::class,
            Mockery::mock(Geocodio::class, fn (MockInterface $mock) => $mock->shouldReceive('reverse')
                ->once()
                ->withArgs([$coordinates, [], 1])
                ->andReturn($geocode)
            )
        );

        $scan = Scan::factory()
            ->withMeta([
                'meta' => [
                    '6' => $address1,
                ],
            ])
            ->create();

        $response = $this->post(route('api.geocode.reverse'), [
            'data' => $coordinates,
            'scan_id' => $scan->id,
        ])
            ->assertSuccessful();

        $this->assertEquals($geocode, $response->json());

        $scan->refresh();

        $this->assertNotNull($scan->scanMetaData);

        $metaData = $scan->scanMetaData->meta;
        $this->assertInstanceOf(ScanMetaDataDto::class, $metaData);

        $this->assertObjectHasProperty('address', $metaData);
        $this->assertNotEquals($address1, $metaData->address);
        $this->assertEquals($address2, $metaData->address);
    }

    #[Group('controllers')]
    #[Group('geocodio')]
    public function test_geocode_doesnt_override_existing_scan_metadata(): void
    {
        $lat = fake()->latitude();
        $lng = fake()->longitude();
        $coordinates = $lat.','.$lng;
        $address = Str::replace(['\n', PHP_EOL], '', fake()->address());

        $geocode = [
            'results' => [
                [
                    'formatted_address' => $address,
                    'location' => [
                        'lat' => $lat,
                        'lng' => $lng,
                    ],
                    'accuracy' => 1,
                ],
            ],
        ];

        $this->instance(
            Geocodio::class,
            Mockery::mock(Geocodio::class, fn (MockInterface $mock) => $mock->shouldReceive('reverse')
                ->once()
                ->withArgs([$coordinates, [], 1])
                ->andReturn($geocode)
            )
        );

        $scan = Scan::factory()
            ->withMeta([
                'meta' => [
                    ScanMetaType::VehicleColor->value => 'Blue',
                    ScanMetaType::VehicleMake->value => 'Toyota',
                ],
            ])
            ->create();

        $response = $this->post(route('api.geocode.reverse'), [
            'data' => $coordinates,
            'scan_id' => $scan->id,
        ])
            ->assertSuccessful();

        $this->assertEquals($geocode, $response->json());

        $scan->refresh();

        $this->assertNotNull($scan->scanMetaData);

        $metaData = $scan->scanMetaData->meta;
        $this->assertInstanceOf(ScanMetaDataDto::class, $metaData);

        $this->assertObjectHasProperty('vehicle', $metaData);
        $this->assertObjectHasProperty('color', $metaData->vehicle);
        $this->assertEquals('Blue', $metaData->vehicle->color);
        $this->assertObjectHasProperty('make', $metaData->vehicle);
        $this->assertEquals('Toyota', $metaData->vehicle->make);

        $this->assertObjectHasProperty('address', $metaData);
        $this->assertEquals($address, $metaData->address);
    }
}
