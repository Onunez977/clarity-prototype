<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'openai',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            // laravel/ai: use access_key_id/secret_access_key for IAM SigV4.
            // Do NOT set `key` here — CreatesBedrockClient treats `key` as a
            // Bedrock bearer API key (httpBearerAuth), which breaks IAM keys
            // with "Invalid API Key format: Must start with pre-defined prefix".
            // Fall back to legacy AWS_BEDROCK_* names used by vehicle analysis.
            'access_key_id' => env('BEDROCK_KEY', env('AWS_BEDROCK_ACCESS_KEY_ID')),
            'secret_access_key' => env('BEDROCK_SECRET', env('AWS_BEDROCK_SECRET_ACCESS_KEY')),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_BEDROCK_USE_DEFAULT_CREDENTIALS', true),
            'region' => env('AWS_BEDROCK_REGION', env('AWS_DEFAULT_REGION', 'us-east-2')),
            'profile' => env('AWS_BEDROCK_PROFILE'),
            'max_tokens' => env('AWS_BEDROCK_MAX_TOKENS', 16_384),
            'models' => [
                'text' => [
                    'default' => env('AWS_BEDROCK_TEXT_MODEL', 'anthropic.claude-sonnet-4-5-20250929-v1:0'),
                    'cheapest' => env('AWS_BEDROCK_CHEAPEST_MODEL', 'moonshotai.kimi-k2.5'),
                    'smartest' => env('AWS_BEDROCK_SMARTEST_MODEL', 'anthropic.claude-opus-4-6-v1:0'),
                ],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Model Pricing
    |--------------------------------------------------------------------------
    |
    | Token pricing per million tokens for each model. Used to calculate
    | estimated costs in the ai_usage_logs table.
    |
    */

    'pricing' => [
        'us.anthropic.claude-haiku-4-5-20251001-v1:0' => [
            'input_per_million' => 1.00,
            'output_per_million' => 5.00,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vehicle Analysis
    |--------------------------------------------------------------------------
    |
    | Configuration for the Bedrock-based vehicle analysis AI agent that
    | compares LPR scan images against expected hotlist vehicle data.
    |
    */

    'vehicle_analysis' => [
        'system_instructions' => "You are a vehicle image analyst for a License Plate Recognition (LPR) system.\n\nYour job:\n1. Analyze the attached vehicle image.\n2. Compare what you see to the expected vehicle (make/model/year provided in the prompt).\n3. Report whether the image matches the expected vehicle.\n4. Identify the vehicle's color from the image.\n5. Determine if the vehicle appears to be moving, parked, or unknown.\n\nBe professional and factual. Only describe what is clearly visible in the image.\nDo not speculate beyond what can be reasonably observed.",
        'model' => env('VEHICLE_ANALYSIS_MODEL', 'us.anthropic.claude-haiku-4-5-20251001-v1:0'),
        'max_tokens' => 512,
        'temperature' => 0.3,
        'timeout' => 20,
    ],

];
