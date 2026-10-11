<?php

namespace App\Providers;

use App\Ai\BedrockVehicleAnalysis;
use App\Contracts\VehicleAnalyzer;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VehicleAnalyzer::class, BedrockVehicleAnalysis::class);
    }
}
