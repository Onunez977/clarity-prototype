<?php

namespace App\Ai\Middleware;

use App\Support\Otto\ToolInvocationNarration;
use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class SanitizeToolNames
{
    protected array $overrides = [];

    protected array $knownNames = [];

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $this->knownNames = $this->discoverToolNames($prompt->agent);

        return $next($prompt)->then(function (AgentResponse $response) {
            $response->text = $this->sanitize($response->text);
        });
    }

    protected function discoverToolNames($agent): array
    {
        $names = [];

        if (method_exists($agent, 'tools')) {
            foreach ($agent->tools() as $tool) {
                $class = class_basename($tool);
                $base = Str::endsWith($class, 'Tool') ? Str::beforeLast($class, 'Tool') : $class;
                $names[$base] = $this->overrides[$base] ?? $this->camelToFriendly($base);
            }
        }

        return $names;
    }

    protected function sanitize(string $text): string
    {
        $sorted = $this->knownNames;
        uksort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sorted as $base => $friendly) {
            $text = preg_replace('/\b'.preg_quote($base, '/').'Tool\b/', $friendly, $text);
            $text = preg_replace('/\b'.preg_quote($base, '/').'\b/', $friendly, $text);
        }

        $text = preg_replace_callback('/\b([A-Z][a-zA-Z]+?)Tool\b/', function ($matches) {
            return $this->camelToFriendly($matches[1]);
        }, $text);

        return ToolInvocationNarration::withoutNarration($text);
    }

    protected function camelToFriendly(string $name): string
    {
        return Str::of($name)
            ->kebab()
            ->replace('-', ' ')
            ->lower()
            ->toString();
    }
}
