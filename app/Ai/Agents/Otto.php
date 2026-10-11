<?php

namespace App\Ai\Agents;

use App\Ai\Knowledge\KnowledgeArticle;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Middleware\SanitizeToolNames;
use App\Ai\Middleware\SanitizeToolParameters;
use App\Ai\PageContext;
use App\Ai\Surface;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Ai\Tools\ToolRegistry;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\BedrockToolIdentifiers;
use Illuminate\Support\Str;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Stringable;

class Otto implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
        protected Surface $surface = Surface::Chat,
        protected ?PageContext $pageContext = null,
        protected ?string $correctionNote = null,
    ) {}

    public function middleware(): array
    {
        return [
            new SanitizeToolNames,
            new SanitizeToolParameters,
        ];
    }

    public function messages(): iterable
    {
        if (! $this->conversationId) {
            return [];
        }

        return resolve(ConversationStore::class)
            ->getLatestConversationMessages(
                $this->conversationId,
                $this->maxConversationMessages()
            )
            ->map(fn (Message $message): Message => $this->sanitizeBedrockToolIdentifiers($message))
            ->all();
    }

    protected function sanitizeBedrockToolIdentifiers(Message $message): Message
    {
        if ($message instanceof AssistantMessage) {
            $calls = $message->toolCalls;
            if ($calls->isNotEmpty()) {
                $calls = $calls->map(function (ToolCall $call): ToolCall {
                    return new ToolCall(
                        id: BedrockToolIdentifiers::sanitizeId($call->id),
                        name: BedrockToolIdentifiers::sanitizeName($call->name),
                        arguments: $call->arguments,
                        resultId: $call->resultId,
                        reasoningId: $call->reasoningId,
                        reasoningSummary: $call->reasoningSummary,
                        reasoningEncryptedContent: $call->reasoningEncryptedContent,
                    );
                });
            }

            $blocks = BedrockToolIdentifiers::sanitizeProviderContentBlocks(
                $message->providerContentBlocks ?? [],
            );

            return new AssistantMessage($message->content, $calls, $blocks);
        }

        if ($message instanceof ToolResultMessage && $message->toolResults->isNotEmpty()) {
            $results = $message->toolResults->map(function (ToolResult $result): ToolResult {
                return new ToolResult(
                    id: BedrockToolIdentifiers::sanitizeId($result->id),
                    name: BedrockToolIdentifiers::sanitizeName($result->name),
                    arguments: $result->arguments,
                    result: $result->result,
                    resultId: $result->resultId,
                );
            });

            return new ToolResultMessage($results);
        }

        return $message;
    }

    public function provider(): string
    {
        return config('otto.provider', 'bedrock');
    }

    public function model(): string
    {
        return config('otto.model', 'us.xai.grok-4.6');
    }

    public function instructions(): Stringable|string
    {
        return view('ai.otto.system-prompt', [
            'correctionNote' => $this->correctionNote,
            'userName' => $this->user->name,
            'orgName' => $this->user->org?->name ?? 'your organization',
            'timezone' => $this->user->timezone,
            'now' => now()->setTimezone($this->user->timezone)->format('l, F j, Y g:i A T'),
            'investigationResults' => $this->investigationResultsSummary(),
            'knowledgeBaseIndex' => $this->knowledgeBaseIndex(),
            'surface' => $this->surface,
            'pageContext' => $this->pageContext,
            'pageHasActions' => $this->pageHasActions(),
        ])->render();
    }

    protected function knowledgeBaseIndex(): string
    {
        $max = (int) config('otto.knowledge.index_summary_length', 160);

        return app(KnowledgeBase::class)
            ->accessibleTo($this->user, $this->surface)
            ->map(function (KnowledgeArticle $article) use ($max): string {
                $summary = Str::limit($article->summary, $max);

                return "- {$article->title}: {$summary}";
            })
            ->implode("\n");
    }

    protected function investigationResultsSummary(): string
    {
        $searches = $this->investigation->data?->searches ?? [];

        if ($searches === []) {
            return 'No searches have been run in this conversation yet.';
        }

        $lines = [];
        foreach ($searches as $search) {
            $title = $search->title ?: $search->searchType->value.' search';
            $lines[] = "- {$title} (most recent page; not a full result count)";
        }

        return implode("\n", $lines);
    }

    public function tools(): iterable
    {
        return app(ToolRegistry::class)->resolve(
            $this->surface,
            $this->user,
            $this->investigation,
            $this->pageContext,
        );
    }

    /**
     * Whether the current page has any page-specific capability available to this
     * user — a resolved tool that only exists because of the page context. The
     * prompt uses this to decide whether Otto can act on the page, vs. that it is
     * page-aware but cannot change this page.
     */
    protected function pageHasActions(): bool
    {
        if (! $this->pageContext instanceof PageContext) {
            return false;
        }

        foreach ($this->tools() as $tool) {
            if ($tool instanceof RegisteredTool && ! $tool->availableOnPage(null)) {
                return true;
            }
        }

        return false;
    }
}
