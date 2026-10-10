<?php

namespace App\Contracts;

interface VehicleAnalyzer
{
    /**
     * Analyze a vehicle image against expected hotlist vehicle data.
     *
     * @return array{
     *     vehicle_match: 'match'|'mismatch'|'uncertain',
     *     observed_color: string,
     *     observed_vehicle: string,
     *     vehicle_status: 'moving'|'parked'|'unknown',
     *     _usage?: array{prompt_tokens: int, completion_tokens: int, model: string},
     * }|null
     */
    public function analyze(
        string $imageUrl,
        string $vehicleYear,
        string $vehicleMake,
        string $vehicleModel,
    ): ?array;
}
