<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Geocoding;

use App\Support\Geocoding\ReverseGeocoder;
use Geocodio\Geocodio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ReverseGeocoderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geocodio.api_key', 'test-geocodio');
        config()->set('services.google.maps.key', '');
        config()->set('services.geocoding.nominatim', false);
    }

    public function test_returns_formatted_address_from_geocodio(): void
    {
        Cache::flush();

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldReceive('reverse')
            ->once()
            ->with('32.78000,-96.80000', [], 1)
            ->andReturn([
                'results' => [
                    ['formatted_address' => 'Main St & 5th, Dallas, TX'],
                ],
            ]);

        $label = (new ReverseGeocoder($geocodio))->label(32.78, -96.80);

        $this->assertSame('Main St & 5th, Dallas, TX', $label);
    }

    public function test_caches_successful_geocodio_label(): void
    {
        Cache::flush();

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldReceive('reverse')
            ->once()
            ->with('32.78000,-96.80000', [], 1)
            ->andReturn([
                'results' => [
                    ['formatted_address' => 'Main St & 5th, Dallas, TX'],
                ],
            ]);

        $geocoder = new ReverseGeocoder($geocodio);

        $this->assertSame('Main St & 5th, Dallas, TX', $geocoder->label(32.78, -96.80));
        $this->assertSame('Main St & 5th, Dallas, TX', $geocoder->label(32.78, -96.80));
    }

    public function test_returns_null_when_geocodio_fails(): void
    {
        Cache::flush();

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldReceive('reverse')
            ->once()
            ->andThrow(new \RuntimeException('geocodio down'));

        $label = (new ReverseGeocoder($geocodio))->label(32.78, -96.80);

        $this->assertNull($label);
    }

    public function test_returns_null_when_results_are_empty(): void
    {
        Cache::flush();

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldReceive('reverse')
            ->once()
            ->andReturn(['results' => []]);

        $label = (new ReverseGeocoder($geocodio))->label(32.78, -96.80);

        $this->assertNull($label);
    }

    public function test_skips_geocodio_when_api_key_is_missing(): void
    {
        Cache::flush();
        config()->set('geocodio.api_key', '');

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldNotReceive('reverse');

        $label = (new ReverseGeocoder($geocodio))->label(32.78, -96.80);

        $this->assertNull($label);
    }

    public function test_falls_back_to_google_when_geocodio_fails(): void
    {
        Cache::flush();
        config()->set('services.google.maps.key', 'test-key');

        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    ['formatted_address' => '123 N Meridian St, Indianapolis, IN, USA'],
                ],
            ]),
        ]);

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldReceive('reverse')
            ->once()
            ->andThrow(new \RuntimeException('geocodio down'));

        $label = (new ReverseGeocoder($geocodio))->label(39.7684, -86.1581);

        $this->assertSame('123 N Meridian St, Indianapolis, IN, USA', $label);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'maps.googleapis.com/maps/api/geocode/json')
                && $request['latlng'] === '39.76840,-86.15810'
                && $request['key'] === 'test-key';
        });
    }

    public function test_does_not_invent_an_address_when_google_also_fails(): void
    {
        Cache::flush();
        config()->set('services.google.maps.key', 'test-key');

        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ]),
        ]);

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldReceive('reverse')
            ->once()
            ->andReturn(['results' => []]);

        $label = (new ReverseGeocoder($geocodio))->label(32.78, -96.80);

        $this->assertNull($label);
    }

    public function test_falls_back_to_nominatim_when_commercial_providers_are_unconfigured(): void
    {
        Cache::flush();
        config()->set('geocodio.api_key', '');
        config()->set('services.google.maps.key', '');
        config()->set('services.geocoding.nominatim', true);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => 'Monument Circle, Indianapolis, Indiana, United States',
            ]),
        ]);

        $geocodio = Mockery::mock(Geocodio::class);
        $geocodio->shouldNotReceive('reverse');

        $label = (new ReverseGeocoder($geocodio))->label(39.7684, -86.1581);

        $this->assertSame('Monument Circle, Indianapolis, Indiana, United States', $label);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'nominatim.openstreetmap.org/reverse')
                && $request['lat'] === '39.76840'
                && $request['lon'] === '-86.15810';
        });
    }
}
