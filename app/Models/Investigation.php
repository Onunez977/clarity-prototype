<?php

namespace App\Models;

use App\Ai\PageAction;
use App\Data\AgentInvestigation\AgentInvestigationProperties;

/**
 * In-memory Otto run state for the chat surface (not a persisted Investigate workspace).
 */
class Investigation
{
    public ?AgentInvestigationProperties $data = null;

    public ?string $title = null;

    protected int $toolCallCount = 0;

    protected int $searchCountAtRunStart = 0;

    protected ?string $activeConversationId = null;

    protected ?string $activePrompt = null;

    /**
     * Page-filter proposals from this run, persisted onto the assistant message at
     * stream end so the confirmation card survives a refresh.
     *
     * @var list<PageAction>
     */
    protected array $proposedPageActions = [];

    public function incrementToolCalls(): int
    {
        return ++$this->toolCallCount;
    }

    public function toolCallLimitReached(?int $max = null): bool
    {
        $max ??= (int) config('otto.tool_call_limit', 50);

        return $this->toolCallCount >= $max;
    }

    public function resetToolCalls(): void
    {
        $this->toolCallCount = 0;
    }

    public function beginToolOutcomeTracking(): void
    {
        $this->searchCountAtRunStart = count($this->data?->searches ?? []);
    }

    public function hasRecordedSearchThisRun(): bool
    {
        return count($this->data?->searches ?? []) > $this->searchCountAtRunStart;
    }

    public function setActiveConversation(?string $conversationId): void
    {
        $this->activeConversationId = $conversationId;
    }

    public function activeConversationId(): ?string
    {
        return $this->activeConversationId;
    }

    public function setActivePrompt(?string $prompt): void
    {
        $this->activePrompt = $prompt;
    }

    public function activePrompt(): ?string
    {
        return $this->activePrompt;
    }

    public function recordProposedPageAction(PageAction $action): void
    {
        $this->proposedPageActions[] = $action;
    }

    public function hasProposedPageActions(): bool
    {
        return $this->proposedPageActions !== [];
    }

    /**
     * Return the page actions proposed this run and clear the buffer.
     *
     * @return list<PageAction>
     */
    public function pullProposedPageActions(): array
    {
        $actions = $this->proposedPageActions;
        $this->proposedPageActions = [];

        return $actions;
    }
}
