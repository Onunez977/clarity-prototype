<?php

namespace Database\Factories;

use App\Models\Address;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = fake()->latitude(31, 48);
        $long = fake()->longitude(-123, -75);

        return [
            'house_number' => fake()->randomNumber(3),
            'road' => fake()->streetName(),
            'city' => fake()->city(),
            'state' => fake()->randomElement([
                'IN',
                'FL',
                'TX',
                'NY',
                'CA',
                'TN',
            ]),
            'postcode' => Str::limit(fake()->postcode(), 5, ''),
            'geo' => Point::makeGeodetic($lat, $long),
            'orig' => fake()->address(),
            'type_id' => null,
        ];
    }

    public function recoveryAddress(): self
    {
        return $this->state(function (array $attributes) {
            $attributes['type_id'] = 2;

            return $attributes;
        });
    }
}
