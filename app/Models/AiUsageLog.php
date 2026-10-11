<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiUsageLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'estimated_cost' => 'decimal:6',
    ];

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'org_id');
    }

    public static function logUsage(
        int $orgId,
        string $agentClass,
        int $promptTokens,
        int $completionTokens,
        string $model,
        string $provider = 'openai',
        ?Model $loggable = null,
    ): static {
        $model = self::normalizeModelName($model);

        $data = [
            'org_id' => $orgId,
            'agent_class' => $agentClass,
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'estimated_cost' => self::calculateEstimatedCost(
                $model,
                $promptTokens,
                $completionTokens,
            ),
        ];

        if ($loggable) {
            $data['loggable_type'] = $loggable->getMorphClass();
            $data['loggable_id'] = $loggable->getKey();
        }

        return static::create($data);
    }

    public static function calculateEstimatedCost(
        string $model,
        int $promptTokens,
        int $completionTokens,
    ): float {
        $pricing = config("ai.pricing.{$model}");

        if (! $pricing) {
            return 0.0;
        }

        $inputCost = ($promptTokens / 1_000_000) * $pricing['input_per_million'];
        $outputCost = ($completionTokens / 1_000_000) * $pricing['output_per_million'];

        return $inputCost + $outputCost;
    }

    public function scopeForOrg(Builder $query, int $orgId): Builder
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeForPeriod(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeForAgent(Builder $query, string $agentClass): Builder
    {
        return $query->where('agent_class', $agentClass);
    }

    /**
     * Normalize versioned model names (e.g. "gpt-4o-mini-2024-07-18") to base names ("gpt-4o-mini").
     */
    private static function normalizeModelName(string $model): string
    {
        return preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);
    }
}
