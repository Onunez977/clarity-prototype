<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GeocodeRecoveryAddress;
use App\Models\Address;
use Geocodio\GeocodioFacade;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GeocodeRecoveryAddressTest extends TestCase
{
    #[Group('jobs')]
    public function test_it_can_run(): void
    {
        $address = Address::factory()->create();

        GeocodioFacade::shouldReceive('geocode')
            ->once()
            ->with($address->orig, [], 1)
            ->andReturn([
                'results' => [
                    [
                        'location' => (object) ['lat' => 0, 'lng' => 0],
                        'address_components' => [
                            'number' => '123',
                            'street' => 'Maine Street',
                            'city' => 'SoMeWhErE',
                            'state' => 'XY',
                            'zip' => '12345',
                        ],
                    ],
                ],
            ]);

        $this->assertNotEquals('123', $address->house_number);
        $this->assertNotEquals('Maine Street', $address->road);
        $this->assertNotEquals('SoMeWhErE', $address->city);
        $this->assertNotEquals('XY', $address->state);
        $this->assertNotEquals('12345', $address->postcode);

        $job = (new GeocodeRecoveryAddress(address: $address))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        $this->assertEquals('123', $address->house_number);
        $this->assertEquals('Maine Street', $address->road);
        $this->assertEquals('SoMeWhErE', $address->city);
        $this->assertEquals('XY', $address->state);
        $this->assertEquals('12345', $address->postcode);
    }

    #[Group('jobs')]
    public function test_it_can_run_with_id(): void
    {
        $address = Address::factory()->create();

        GeocodioFacade::shouldReceive('geocode')
            ->once()
            ->with($address->orig, [], 1)
            ->andReturn([
                'results' => [
                    [
                        'location' => (object) ['lat' => 0, 'lng' => 0],
                        'address_components' => [
                            'number' => '123',
                            'street' => 'Maine Street',
                            'city' => 'SoMeWhErE',
                            'state' => 'XY',
                            'zip' => '12345',
                        ],
                    ],
                ],
            ]);

        $this->assertNotEquals('123', $address->house_number);
        $this->assertNotEquals('Maine Street', $address->road);
        $this->assertNotEquals('SoMeWhErE', $address->city);
        $this->assertNotEquals('XY', $address->state);
        $this->assertNotEquals('12345', $address->postcode);

        $job = (new GeocodeRecoveryAddress(addressId: $address->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        $address->refresh();

        $this->assertEquals('123', $address->house_number);
        $this->assertEquals('Maine Street', $address->road);
        $this->assertEquals('SoMeWhErE', $address->city);
        $this->assertEquals('XY', $address->state);
        $this->assertEquals('12345', $address->postcode);
    }

    #[Group('jobs')]
    public function test_fails_without_an_address(): void
    {
        $job = (new GeocodeRecoveryAddress)->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailedWith('Address not found');
    }
}
