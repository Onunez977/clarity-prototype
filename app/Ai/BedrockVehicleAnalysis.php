<?php

declare(strict_types=1);

namespace App\Ai;

use App\Contracts\VehicleAnalyzer;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BedrockVehicleAnalysis implements VehicleAnalyzer
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'vehicle_match' => [
                'type' => 'string',
                'enum' => ['match', 'mismatch', 'uncertain'],
                'description' => 'Whether the vehicle in the image matches the expected make/model/year',
            ],
            'observed_color' => [
                'type' => 'string',
                'description' => 'The color of the vehicle as seen in the image',
            ],
            'observed_vehicle' => [
                'type' => 'string',
                'description' => "What the AI identifies the vehicle as (e.g. '2019 Honda Civic sedan')",
            ],
            'vehicle_status' => [
                'type' => 'string',
                'enum' => ['moving', 'parked', 'unknown'],
                'description' => 'Whether the vehicle appears to be moving, parked, or cannot be determined',
            ],
        ],
        'required' => ['vehicle_match', 'observed_color', 'observed_vehicle', 'vehicle_status'],
        'additionalProperties' => false,
    ];

    public function analyze(string $imageUrl, string $vehicleYear, string $vehicleMake, string $vehicleModel): ?array
    {
        try {
            $config = config('ai.vehicle_analysis');
            $providerConfig = config('ai.providers.bedrock');

            $imageResponse = Http::timeout($config['timeout'])->get($imageUrl);
            if (! $imageResponse->successful()) {
                Log::channel('hits')->warning('Bedrock vehicle analysis image fetch failed', [
                    'image_url' => $imageUrl,
                    'status' => $imageResponse->status(),
                ]);

                return null;
            }

            $clientArgs = [
                'version' => 'latest',
                'region' => $providerConfig['region'],
            ];

            $accessKeyId = $providerConfig['access_key_id'] ?? null;
            $secretAccessKey = $providerConfig['secret_access_key'] ?? null;

            if (! empty($accessKeyId) && ! empty($secretAccessKey)) {
                $clientArgs['credentials'] = [
                    'key' => $accessKeyId,
                    'secret' => $secretAccessKey,
                ];

                if (! empty($providerConfig['session_token'])) {
                    $clientArgs['credentials']['token'] = $providerConfig['session_token'];
                }
            } elseif (! empty($providerConfig['profile'])) {
                $clientArgs['profile'] = $providerConfig['profile'];
            }

            $client = new BedrockRuntimeClient($clientArgs);

            $result = $client->converse([
                'modelId' => $config['model'],
                'system' => [['text' => $config['system_instructions']]],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['text' => $this->buildPrompt($vehicleYear, $vehicleMake, $vehicleModel)],
                        ['image' => [
                            'format' => $this->detectFormat($imageUrl),
                            'source' => ['bytes' => $imageResponse->body()],
                        ]],
                    ],
                ]],
                'inferenceConfig' => [
                    'maxTokens' => $config['max_tokens'],
                    'temperature' => $config['temperature'],
                ],
                'toolConfig' => [
                    'tools' => [[
                        'toolSpec' => [
                            'name' => 'vehicle_analysis',
                            'description' => 'Return structured analysis of the vehicle image.',
                            'inputSchema' => ['json' => self::RESPONSE_SCHEMA],
                        ],
                    ]],
                    'toolChoice' => ['tool' => ['name' => 'vehicle_analysis']],
                ],
            ]);

            $contentBlocks = $result['output']['message']['content'] ?? [];
            $parsed = null;
            foreach ($contentBlocks as $block) {
                if (isset($block['toolUse']['input']) && is_array($block['toolUse']['input'])) {
                    $parsed = $block['toolUse']['input'];
                    break;
                }
            }

            if (! is_array($parsed)
                || ! isset($parsed['vehicle_match'], $parsed['observed_color'], $parsed['observed_vehicle'], $parsed['vehicle_status'])) {
                Log::channel('hits')->warning('Bedrock vehicle analysis returned invalid response', [
                    'content' => $contentBlocks,
                ]);

                return null;
            }

            return [
                'vehicle_match' => $parsed['vehicle_match'],
                'observed_color' => $parsed['observed_color'],
                'observed_vehicle' => $parsed['observed_vehicle'],
                'vehicle_status' => $parsed['vehicle_status'],
                '_usage' => [
                    'prompt_tokens' => (int) ($result['usage']['inputTokens'] ?? 0),
                    'completion_tokens' => (int) ($result['usage']['outputTokens'] ?? 0),
                    'model' => $config['model'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::channel('hits')->warning('Bedrock vehicle analysis error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildPrompt(string $vehicleYear, string $vehicleMake, string $vehicleModel): string
    {
        return "Analyze this vehicle image from an LPR (License Plate Recognition) scan.\n\n"
            ."Expected vehicle from hotlist records:\n"
            ."- Year: {$vehicleYear}\n"
            ."- Make: {$vehicleMake}\n"
            ."- Model: {$vehicleModel}\n\n"
            ."1. Does the vehicle in the image match the expected make/model/year?\n"
            ."2. What color is the vehicle in the image?\n"
            ."3. What vehicle do you observe in the image?\n"
            .'4. Is the vehicle moving or parked?';
    }

    private function detectFormat(string $imageUrl): string
    {
        $extension = strtolower(pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            default => 'jpeg',
        };
    }
}
