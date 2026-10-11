<?php

namespace App\Ai\Tools\Concerns;

use App\Ai\PageAction;
use App\Events\OttoPageActionProposed;

/**
 * Lets a page-action tool propose an action on the user's current page.
 *
 * The tool never changes the page directly — it broadcasts a PENDING suggestion
 * to the chat and returns the model a string that forbids claiming the action
 * was applied. The user must Accept the chip in the chat before the page
 * adapter runs it.
 *
 * Consuming tools must expose `$this->investigation` so the active conversation
 * can be resolved.
 */
trait ProposesPageActions
{
    /**
     * Broadcast a proposed page action and return the LLM-facing result string.
     * Returns a non-committal string if there is no active conversation to surface
     * the proposal in.
     */
    protected function proposePageAction(PageAction $action): string
    {
        $conversationId = $this->investigation->activeConversationId();

        if (! $conversationId) {
            return 'Page actions can only be proposed inside an active Otto conversation.';
        }

        $this->investigation->recordProposedPageAction($action);

        broadcast(new OttoPageActionProposed($conversationId, $action));

        if (! $action->requiresConfirmation) {
            return "Applied on the page: {$action->summary}.";
        }

        return "Proposed to the user: {$action->summary}. This is a SUGGESTION awaiting their confirmation — they must Accept it on the page before anything changes. Do NOT tell the user it has been applied, searched, or changed; say you have suggested it and it is waiting for their review. Do NOT list or restate the individual filters/changes in your reply — each is already shown as its own item in the confirmation card below your message, so naming them again only duplicates the card. Keep your reply to a brief one-line confirmation and do not explain how you interpreted the request. Never say you used the closest preset, approximated their time range, or changed the window they asked for — the card already shows the exact range. Never emit HTML markup — the confirmation card is rendered for you automatically.";
    }
}
