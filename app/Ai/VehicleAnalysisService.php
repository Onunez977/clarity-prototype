<?php

declare(strict_types=1);

namespace App\Ai;

use App\Contracts\VehicleAnalyzer;
use App\Models\AiUsageLog;
use App\Models\Hit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VehicleAnalysisService
{
    public function __construct(
        private VehicleAnalyzer $analyzer,
    ) {}

    public function analyze(Hit $hit, string $timezone = 'UTC'): ?array
    {
        $hit->loadMissing('scan', 'hotlist');

        $guid = $hit->scan?->guid;
        if (! $guid) {
            return null;
        }

        $hotlist = $hit->hotlist;
        if (! $hotlist?->vehicle_year && ! $hotlist?->vehicle_make && ! $hotlist?->vehicle_model) {
            return null;
        }

        return Cache::remember(
            "vehicle_analysis:{$hit->id}",
            now()->addMinutes(5),
            fn () => $this->callAgent($hit, $guid)
        );
    }

    private function callAgent(Hit $hit, string $guid): ?array
    {
        try {
            $hotlist = $hit->hotlist;
            $imageUrl = scan_img($guid, 'c');

            $result = $this->analyzer->analyze(
                imageUrl: $imageUrl,
                vehicleYear: $hotlist?->vehicle_year ?? 'Unknown',
                vehicleMake: $hotlist?->vehicle_make ?? 'Unknown',
                vehicleModel: $hotlist?->vehicle_model ?? 'Unknown',
            );

            if (! $result) {
                return null;
            }

            $this->logUsage($hit, $result['_usage'] ?? []);

            unset($result['_usage']);

            return $result;
        } catch (\Throwable $e) {
            Log::channel('hits')->warning('Vehicle AI analysis failed', [
                'hit_id' => $hit->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function logUsage(Hit $hit, array $usage): void
    {
        try {
            $hit->loadMissing('kit');

            AiUsageLog::logUsage(
                orgId: $hit->kit->org_id,
                agentClass: $this->analyzer::class,
                promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
                completionTokens: (int) ($usage['completion_tokens'] ?? 0),
                model: $usage['model'] ?? config('ai.vehicle_analysis.model'),
                provider: 'bedrock',
                loggable: $hit,
            );
        } catch (\Throwable $e) {
            Log::channel('hits')->warning('AI usage logging failed', [
                'hit_id' => $hit->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
