<?php

namespace App\Support\Otto;

use App\Ai\Agents\ConversationTitle;
use App\Models\AgentConversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores a short history title for an Otto conversation.
 *
 * New threads start with a local placeholder so the list is never blank.
 * After the first reply, the cheapest text model replaces that placeholder
 * with a 3–5 word summary. Later turns leave a real title alone.
 */
class ConversationTitleGenerator
{
    private const LEADING_FILLER = '/^(?:(?:hey|hi|hello|please|thanks|thank you|otto)[,!]?\s+|(?:can you|could you|would you|will you|i want to|i need to|i\'d like to|help me|show me|tell me|find me)\s+)/iu';

    public function generateFor(string $conversationId, string $prompt): void
    {
        $conversation = AgentConversation::query()->find($conversationId);

        if (! $conversation instanceof AgentConversation) {
            return;
        }

        if (! $this->shouldReplaceTitle($conversation->title, $prompt)) {
            return;
        }

        $title = $this->summarize($prompt, $conversationId);

        if ($title === '' || $title === $conversation->title) {
            return;
        }

        $conversation->forceFill(['title' => $title])->save();
    }

    public function placeholder(string $prompt): string
    {
        $normalized = $this->withoutLeadingFiller($this->normalize($prompt));

        if ($normalized === '') {
            return 'New chat';
        }

        $title = trim(Str::words($normalized, 8, ''), " \t\"'`“”‘’.,;:!?-–—");
        $title = $this->capitalize($title);

        return $title !== '' ? $title : 'New chat';
    }

    private function summarize(string $prompt, string $conversationId): string
    {
        $fallback = $this->placeholder($prompt);
        $normalized = $this->normalize($prompt);

        if ($normalized === '' || ! (bool) config('ai.conversations.generate_title', true)) {
            return $fallback;
        }

        try {
            $response = (new ConversationTitle)->prompt(Str::limit($normalized, 500));
            $title = $this->cleanModelTitle($response->text, $normalized);

            return $title !== '' ? $title : $fallback;
        } catch (Throwable $exception) {
            Log::channel('otto')->warning('Otto conversation title summary failed', [
                'conversation_id' => $conversationId,
                'exception' => $exception::class,
            ]);

            return $fallback;
        }
    }

    private function shouldReplaceTitle(?string $currentTitle, string $prompt): bool
    {
        $current = trim((string) $currentTitle);

        if ($current === '') {
            return true;
        }

        return $current === $this->placeholder($prompt)
            || $current === Str::limit($prompt, 60);
    }

    private function cleanModelTitle(string $text, string $normalizedPrompt): string
    {
        $line = preg_split('/\R/u', trim($text))[0] ?? '';
        $line = trim((string) $line);
        $line = trim($line, " \t\"'`“”‘’");
        $line = (string) preg_replace('/^(?:title\s*:\s*)+/i', '', $line);
        $line = rtrim($line, '.!?:;,-');
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));
        $line = $this->capitalize($line);

        if ($line === '') {
            return '';
        }

        $line = Str::limit($line, 80, '');

        if ($this->isRawExcerpt($line, $normalizedPrompt)) {
            return '';
        }

        return $line;
    }

    private function isRawExcerpt(string $title, string $prompt): bool
    {
        $title = mb_strtolower($title);
        $prompt = mb_strtolower($prompt);

        if ($title === $prompt) {
            return mb_strlen($prompt) > 48;
        }

        if (mb_strlen($prompt) <= mb_strlen($title) + 20) {
            return false;
        }

        return str_starts_with($prompt, $title);
    }

    private function withoutLeadingFiller(string $prompt): string
    {
        $text = $prompt;

        do {
            $next = preg_replace(self::LEADING_FILLER, '', $text, 1);

            if (! is_string($next) || $next === $text) {
                break;
            }

            $text = trim($next);
        } while ($text !== '');

        return $text;
    }

    private function normalize(string $prompt): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $prompt));
    }

    private function capitalize(string $title): string
    {
        if ($title === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1);
    }
}
