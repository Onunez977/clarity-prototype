<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Otto AI Provider
    |--------------------------------------------------------------------------
    |
    | Must match a provider key in config/ai.php (e.g. bedrock, anthropic).
    |
    */

    'provider' => env('OTTO_PROVIDER', 'bedrock'),

    /*
    |--------------------------------------------------------------------------
    | Otto AI Model
    |--------------------------------------------------------------------------
    */

    'model' => env('OTTO_MODEL', 'us.xai.grok-4.6'),

    /*
    |--------------------------------------------------------------------------
    | Tool Call Limit
    |--------------------------------------------------------------------------
    |
    | Per-message budget for rate-limited search tools. Counter resets each
    | streaming job turn.
    |
    */

    'tool_call_limit' => (int) env('OTTO_TOOL_CALL_LIMIT', 50),

    /*
    |--------------------------------------------------------------------------
    | Otto Knowledge Base
    |--------------------------------------------------------------------------
    |
    | Customer-safe Clarity product articles (markdown + YAML frontmatter).
    | Never dump the full base into the prompt — index in-prompt, bodies on demand.
    |
    */

    'knowledge' => [
        'path' => env('OTTO_KNOWLEDGE_PATH', resource_path('otto-knowledge')),
        'max_results' => (int) env('OTTO_KNOWLEDGE_MAX_RESULTS', 3),
        'max_article_characters' => (int) env('OTTO_KNOWLEDGE_MAX_ARTICLE_CHARS', 1500),
        'index_summary_length' => (int) env('OTTO_KNOWLEDGE_INDEX_SUMMARY_LENGTH', 160),
    ],

    /*
    |--------------------------------------------------------------------------
    | Otto Token Pricing (Datadog cost estimate)
    |--------------------------------------------------------------------------
    |
    | Used by App\Support\Otto\OttoMetrics to estimate USD cost per completed
    | model turn: (prompt/1e6)*rate_in + (completion/1e6)*rate_out
    | + (cache_read/1e6)*rate_cache. Defaults are USD per 1M tokens:
    | input $2.20, output $6.60, cached read $0.55. Bedrock reports
    | cacheReadInputTokens separately from inputTokens, so cached reads are
    | not also charged at the input rate. Override when Bedrock rates change.
    |
    */

    'pricing' => [
        'prompt_usd_per_million' => (float) env('OTTO_PRICE_PROMPT_PER_M', 2.20),
        'completion_usd_per_million' => (float) env('OTTO_PRICE_COMPLETION_PER_M', 6.60),
        'cache_read_usd_per_million' => (float) env('OTTO_PRICE_CACHE_READ_PER_M', 0.55),
    ],

];
