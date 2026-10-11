<?php

namespace App\Support\Otto;

use App\Ai\Surface;
use App\Models\User;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Production-only DogStatsd emitters for Otto observability.
 *
 * Same metric schema as Guardian Otto (`overwatch.otto_*` in insight-lpr/overwatch
 * `App\Support\Otto\OttoMetrics`), with the Clarity product prefix `clarity.otto_*`.
 * Stricter than the generic logDatadog* helpers: requires both
 * app()->isProduction() and config('services.datadog_forwarding').
 * Lean tags: surface / tool / rating / user_id / user — never org_id.
 */
final class OttoMetrics
{
    /** Datadog tag values are capped; keep readable labels bounded. */
    private const USER_TAG_MAX_LENGTH = 100;

    public static function enabled(): bool
    {
        return app()->isProduction() && (bool) config('services.datadog_forwarding');
    }

    /**
     * Record one completed Otto turn.
     *
     * Token, cost, and message metrics are tagged with user_id + user for
     * per-user dashboard tables. Tool-call metrics stay surface/tool only.
     *
     * @param  iterable<int, object|string>  $toolCalls  ToolCall-like objects with ->name, or tool name strings
     */
    public static function recordTurn(Surface $surface, User $user, ?Usage $usage = null, iterable $toolCalls = []): void
    {
        if (! self::enabled()) {
            return;
        }

        $surfaceTag = $surface->value;
        $userTags = self::userTags($user);
        $baseTags = array_merge(['surface' => $surfaceTag], $userTags);

        logDatadogCount('clarity.otto_message', 1, $baseTags);

        $promptTokens = $usage?->promptTokens ?? 0;
        $completionTokens = $usage?->completionTokens ?? 0;
        $cacheReadTokens = $usage?->cacheReadInputTokens ?? 0;

        if ($promptTokens > 0) {
            logDatadogCount('clarity.otto_tokens_prompt', (float) $promptTokens, $baseTags);
        }

        if ($completionTokens > 0) {
            logDatadogCount('clarity.otto_tokens_completion', (float) $completionTokens, $baseTags);
        }

        $cost = self::estimateCostUsd($promptTokens, $completionTokens, $cacheReadTokens);
        if ($cost > 0) {
            logDatadogCount('clarity.otto_estimated_cost_usd', $cost, $baseTags);
        }

        foreach ($toolCalls as $toolCall) {
            $name = is_string($toolCall)
                ? $toolCall
                : (is_object($toolCall) && isset($toolCall->name) ? (string) $toolCall->name : null);

            if ($name === null || $name === '') {
                continue;
            }

            logDatadogCount('clarity.otto_tool_call', 1, [
                'surface' => $surfaceTag,
                'tool' => $name,
            ]);
        }
    }

    public static function recordFeedback(bool $helpful): void
    {
        if (! self::enabled()) {
            return;
        }

        logDatadogCount('clarity.otto_feedback', 1, [
            'rating' => $helpful ? 'positive' : 'negative',
        ]);
    }

    public static function estimateCostUsd(int $promptTokens, int $completionTokens, int $cacheReadTokens = 0): float
    {
        $promptRate = (float) config('otto.pricing.prompt_usd_per_million', 2.20);
        $completionRate = (float) config('otto.pricing.completion_usd_per_million', 6.60);
        $cacheReadRate = (float) config('otto.pricing.cache_read_usd_per_million', 0.55);

        return ($promptTokens / 1_000_000) * $promptRate
            + ($completionTokens / 1_000_000) * $completionRate
            + ($cacheReadTokens / 1_000_000) * $cacheReadRate;
    }

    /**
     * @return array{user_id: string, user: string}
     */
    public static function userTags(User $user): array
    {
        $id = (string) $user->id;

        $label = filled($user->email)
            ? (string) $user->email
            : (filled($user->name) ? (string) $user->name : 'user_'.$id);

        return [
            'user_id' => $id,
            'user' => self::sanitizeTagValue($label),
        ];
    }

    /**
     * Datadog tag values: lowercase, invalid chars → `_`, truncated.
     */
    public static function sanitizeTagValue(string $value): string
    {
        $sanitized = strtolower($value);
        $sanitized = preg_replace('/[^a-z0-9_\\-:\\.\\/]/', '_', $sanitized) ?? '';
        $sanitized = preg_replace('/_+/', '_', $sanitized) ?? '';
        $sanitized = trim($sanitized, '_');

        if ($sanitized === '') {
            return 'unknown';
        }

        if (strlen($sanitized) > self::USER_TAG_MAX_LENGTH) {
            $sanitized = substr($sanitized, 0, self::USER_TAG_MAX_LENGTH);
            $sanitized = rtrim($sanitized, '_');
        }

        return $sanitized;
    }
}
