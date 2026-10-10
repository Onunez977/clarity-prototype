<?php

namespace App\Ai\Tools;

use App\Ai\Knowledge\KnowledgeArticle;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\RecordLookupAsk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets Otto answer Clarity product/how-to questions by retrieving curated
 * knowledge-base articles on demand. Results are scoped to what THIS user is
 * permitted to see. This tool does not touch ClickHouse/Postgres and is
 * intentionally exempt from the per-message search rate limit.
 */
class KnowledgeBaseSearchTool implements RegisteredTool
{
    use ResolvesToolAvailability;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    /**
     * Open to every Otto user. This tool reads only `resources/otto-knowledge/`
     * — customer-facing product help, no scan/hit/hotlist records — so the
     * `use-otto` gate that admitted them to the chat is the whole requirement.
     */
    public function authorizedFor(User $user): bool
    {
        return true;
    }

    public function description(): Stringable|string
    {
        return 'Look up how Clarity works to answer the user\'s product, how-to, or support questions (e.g. "how do I search for a plate?", "what is a hotlist?", "what is a hit?"). Use this only when the user asks about using Clarity or about license plate recognition concepts, rather than guessing. NEVER use this instead of ScanSearch / HitSearch / HotlistSearch when they ask for live records (today\'s scans, recent hits, a plate lookup). NEVER use this instead of ZoneSearch when they ask which zones they have. Returns reference material scoped to what this user is allowed to see.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The product question, feature, or concept to look up (e.g. "searching by plate", "what is a hotlist", "wildcards"). Use the user\'s own words.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Tell me the topic or question you want to look up in the Clarity knowledge base.';
        }

        $userPrompt = (string) ($this->investigation->activePrompt() ?? '');
        if (RecordLookupAsk::matches($userPrompt)) {
            return RecordLookupAsk::knowledgeBaseRedirect($userPrompt);
        }

        $limit = (int) config('otto.knowledge.max_results', 3);
        $maxChars = (int) config('otto.knowledge.max_article_characters', 1500);

        $articles = app(KnowledgeBase::class)->search($query, $this->user, surface: null, limit: $limit);

        if ($articles->isEmpty()) {
            return "I couldn't find anything in the Clarity knowledge base about \"{$query}\". "
                .'Let the user know you do not have documentation covering that and, if appropriate, suggest they contact support — do not guess, and do not invent product internals or engineering details.';
        }

        $sections = $articles
            ->map(fn (KnowledgeArticle $article): string => "## {$article->title}\n\n".Str::limit($article->body, $maxChars, '…'))
            ->implode("\n\n---\n\n");

        return LimitedToolResultMessage::forKnowledgeArticles($articles->count(), $limit, $query, $sections);
    }
}
