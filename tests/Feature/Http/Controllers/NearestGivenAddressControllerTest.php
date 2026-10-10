<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Models\Address;
use App\Models\Hotlist;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('controllers')]
class NearestGivenAddressControllerTest extends TestCase
{
    public function test_null_type_id_geocoded_address_returns_the_nearest_address(): void
    {
        $user = $this->proUser();
        $hotlist = Hotlist::factory()->create();
        $this->givenAddress($hotlist, 30.2700, -97.7431, [
            'house_number' => '100',
            'road' => 'Near Rd',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78724',
            'type_id' => null,
        ]);
        $this->givenAddress($hotlist, 30.3500, -97.7431, [
            'house_number' => '900',
            'road' => 'Far Rd',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78724',
            'type_id' => 2,
        ]);
        $other = Hotlist::factory()->create();
        $this->givenAddress($other, 30.2673, -97.7431, [
            'house_number' => '1',
            'road' => 'Other Rd',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78724',
            'type_id' => null,
        ]);

        $response = $this->actingAs($user)->postJson(route('nearest-given-address'), [
            'hotlist_id' => $hotlist->id,
            'lat' => 30.2672,
            'lng' => -97.7431,
        ]);

        $response->assertOk();
        $response->assertJsonPath('address', '100 Near Rd, Austin, TX 78724');
        $miles = $response->json('distance_miles');
        $this->assertIsNumeric($miles);
        $this->assertGreaterThan(0.1, (float) $miles);
        $this->assertLessThan(0.4, (float) $miles);
    }

    public function test_type_id_two_geocoded_address_still_returns_distance(): void
    {
        $user = $this->proUser();
        $hotlist = Hotlist::factory()->create();
        $this->givenAddress($hotlist, 30.3500, -97.7431, [
            'house_number' => '5409',
            'road' => 'King Henry Dr',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78724',
            'type_id' => 2,
        ]);

        $response = $this->actingAs($user)->postJson(route('nearest-given-address'), [
            'hotlist_id' => $hotlist->id,
            'lat' => 30.2672,
            'lng' => -97.7431,
        ]);

        $response->assertOk();
        $response->assertJsonPath('address', '5409 King Henry Dr, Austin, TX 78724');
        $miles = $response->json('distance_miles');
        $this->assertIsNumeric($miles);
        $this->assertGreaterThan(5, (float) $miles);
        $this->assertLessThan(7, (float) $miles);
    }

    public function test_zero_scan_coordinates_return_empty_json(): void
    {
        $user = $this->proUser();
        $hotlist = Hotlist::factory()->create();
        $this->givenAddress($hotlist, 30.2700, -97.7431);

        $this->actingAs($user)->postJson(route('nearest-given-address'), [
            'hotlist_id' => $hotlist->id,
            'lat' => 0,
            'lng' => -97.7431,
        ])->assertOk()->assertExactJson([]);

        $this->actingAs($user)->postJson(route('nearest-given-address'), [
            'hotlist_id' => $hotlist->id,
            'lat' => 30.2672,
            'lng' => 0,
        ])->assertOk()->assertExactJson([]);
    }

    public function test_hotlist_without_an_address_returns_empty_json(): void
    {
        $user = $this->proUser();
        $hotlist = Hotlist::factory()->create();

        $this->actingAs($user)->postJson(route('nearest-given-address'), [
            'hotlist_id' => $hotlist->id,
            'lat' => 30.2672,
            'lng' => -97.7431,
        ])->assertOk()->assertExactJson([]);
    }

    public function test_address_with_null_geo_returns_empty_json(): void
    {
        $user = $this->proUser();
        $hotlist = Hotlist::factory()->create();
        $this->givenAddress($hotlist, null, null, ['geo' => null]);

        $this->actingAs($user)->postJson(route('nearest-given-address'), [
            'hotlist_id' => $hotlist->id,
            'lat' => 30.2672,
            'lng' => -97.7431,
        ])->assertOk()->assertExactJson([]);
    }

    public function test_feature_off_without_impersonator_returns_empty_json(): void
    {
        $user = $this->createUser();
        $hotlist = Hotlist::factory()->create();
        $this->givenAddress($hotlist, 30.2700, -97.7431, [
            'house_number' => '100',
            'road' => 'Near Rd',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78724',
        ]);

        $this->actingAs($user)->postJson(route('nearest-given-address'), [
            'hotlist_id' => $hotlist->id,
            'lat' => 30.2672,
            'lng' => -97.7431,
        ])->assertOk()->assertExactJson([]);
    }

    public function test_impersonator_without_the_feature_returns_the_address(): void
    {
        $user = $this->createUser();
        $hotlist = Hotlist::factory()->create();
        $this->givenAddress($hotlist, 30.2700, -97.7431, [
            'house_number' => '100',
            'road' => 'Near Rd',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78724',
            'type_id' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']])
            ->postJson(route('nearest-given-address'), [
                'hotlist_id' => $hotlist->id,
                'lat' => 30.2672,
                'lng' => -97.7431,
            ])
            ->assertOk()
            ->assertJsonPath('address', '100 Near Rd, Austin, TX 78724');
    }

    private function proUser(): User
    {
        $user = $this->createUser();
        $this->makeUserPro($user, FeatureEnum::HIT_ADDRESS_DISTANCE->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function givenAddress(Hotlist $hotlist, ?float $lat, ?float $lng, array $overrides = []): Address
    {
        $attributes = $overrides;

        if (! array_key_exists('geo', $attributes)) {
            $attributes['geo'] = Point::makeGeodetic($lat, $lng);
        }

        $address = Address::factory()->create($attributes);
        $address->hotlists()->attach($hotlist->id);

        return $address;
    }
}
