<?php

declare(strict_types=1);

namespace App\Support\Geocoding;

use App\Http\Controllers\GeocoderController;
use Geocodio\Geocodio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side reverse geocode via Geocodio, then Google Maps, then (local only)
 * Nominatim (DEV-2321).
 *
 * Returns a plain-language label, or null on failure — never invents an
 * address. Successful labels are cached ~24h like
 * {@see GeocoderController::getCachedGeocode()}.
 */
class ReverseGeocoder
{
    public function __construct(
        private readonly Geocodio $geocodio,
    ) {}

    public function label(float $lat, float $lng): ?string
    {
        $roundedLat = round($lat, 5);
        $roundedLng = round($lng, 5);
        $cacheKey = 'geocode:reverse:label:'.sha1(sprintf('%.5f,%.5f', $roundedLat, $roundedLng));

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }

        $label = $this->fromGeocodio($roundedLat, $roundedLng)
            ?? $this->fromGoogle($roundedLat, $roundedLng)
            ?? $this->fromNominatim($roundedLat, $roundedLng);

        if ($label !== null) {
            Cache::put($cacheKey, $label, now()->addDay());
        }

        return $label;
    }

    private function fromGeocodio(float $lat, float $lng): ?string
    {
        if (! $this->geocodioConfigured()) {
            return null;
        }

        $coordinates = sprintf('%.5f,%.5f', $lat, $lng);

        try {
            $data = $this->geocodio->reverse($coordinates, [], 1);
        } catch (\Throwable $e) {
            Log::warning('reverse geocode failed', [
                'provider' => 'geocodio',
                'lat' => $lat,
                'lng' => $lng,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->trimmedAddress(data_get($data, 'results.0.formatted_address'));
    }

    private function fromGoogle(float $lat, float $lng): ?string
    {
        $key = (string) config('services.google.maps.key', '');
        if ($key === '') {
            return null;
        }

        try {
            $response = Http::timeout(3)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => sprintf('%.5f,%.5f', $lat, $lng),
                'key' => $key,
            ]);
        } catch (\Throwable $e) {
            Log::warning('reverse geocode failed', [
                'provider' => 'google',
                'lat' => $lat,
                'lng' => $lng,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        $status = (string) $response->json('status', '');
        if (! $response->ok() || $status !== 'OK') {
            if ($status !== 'ZERO_RESULTS') {
                Log::warning('reverse geocode failed', [
                    'provider' => 'google',
                    'lat' => $lat,
                    'lng' => $lng,
                    'http' => $response->status(),
                    'status' => $status !== '' ? $status : null,
                ]);
            }

            return null;
        }

        return $this->trimmedAddress($response->json('results.0.formatted_address'));
    }

    private function fromNominatim(float $lat, float $lng): ?string
    {
        if (! (bool) config('services.geocoding.nominatim', false)) {
            return null;
        }

        try {
            $response = Http::timeout(3)
                ->withHeaders([
                    'User-Agent' => 'Clarity-Otto/1.0 (local reverse geocode)',
                    'Accept-Language' => 'en',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => sprintf('%.5f', $lat),
                    'lon' => sprintf('%.5f', $lng),
                    'format' => 'jsonv2',
                    'addressdetails' => 0,
                ]);
        } catch (\Throwable $e) {
            Log::warning('reverse geocode failed', [
                'provider' => 'nominatim',
                'lat' => $lat,
                'lng' => $lng,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->ok()) {
            Log::warning('reverse geocode failed', [
                'provider' => 'nominatim',
                'lat' => $lat,
                'lng' => $lng,
                'http' => $response->status(),
            ]);

            return null;
        }

        return $this->trimmedAddress($response->json('display_name'));
    }

    private function geocodioConfigured(): bool
    {
        $key = trim((string) config('geocodio.api_key', ''));

        return $key !== '' && strcasecmp($key, 'Geocodio') !== 0;
    }

    private function trimmedAddress(mixed $address): ?string
    {
        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        return trim($address);
    }
}
