<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Names an Otto thread for the history list. One prompt, no tools, no memory.
 */
#[UseCheapestModel]
class ConversationTitle implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Generate a concise 3-5 word title for a conversation that starts with the following message. Respond with only the title, no quotes or punctuation.';
    }

    public function provider(): string
    {
        return (string) config('otto.provider', 'bedrock');
    }

    public function timeout(): int
    {
        return 15;
    }
}
