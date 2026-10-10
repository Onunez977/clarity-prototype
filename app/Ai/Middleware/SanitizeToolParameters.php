<?php

namespace App\Ai\Middleware;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class SanitizeToolParameters
{
    protected array $overrides = [
        'start_date' => 'start date',
        'end_date' => 'end date',
        'time_range' => 'time range',
    ];

    protected array $knownParams = [];

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $this->knownParams = $this->discoverParameters($prompt->agent);

        return $next($prompt)->then(function (AgentResponse $response) {
            $response->text = $this->sanitize($response->text);
        });
    }

    protected function discoverParameters($agent): array
    {
        $params = [];
        $schema = new JsonSchemaTypeFactory;

        if (method_exists($agent, 'tools')) {
            foreach ($agent->tools() as $tool) {
                if (method_exists($tool, 'schema')) {
                    foreach (array_keys($tool->schema($schema)) as $param) {
                        $params[$param] = $this->overrides[$param] ?? $this->snakeToFriendly($param);
                    }
                }
            }
        }

        foreach ($this->overrides as $param => $friendly) {
            $params[$param] = $friendly;
        }

        return $params;
    }

    protected function sanitize(string $text): string
    {
        $sorted = $this->knownParams;
        uksort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sorted as $param => $friendly) {
            $escaped = preg_quote($param, '/');
            $text = preg_replace('/[`"\']'.$escaped.'[`"\']/', $friendly, $text);
            $text = preg_replace('/\b'.$escaped.'\b/', $friendly, $text);
        }

        return $text;
    }

    protected function snakeToFriendly(string $name): string
    {
        return Str::of($name)->replace('_', ' ')->lower()->toString();
    }
}
