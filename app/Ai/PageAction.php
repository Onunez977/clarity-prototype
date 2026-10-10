<?php

namespace App\Ai;

use App\Events\OttoPageActionProposed;
use Illuminate\Support\Str;

/**
 * An action Otto proposes to perform on the current page — e.g. "set the plate
 * and use the last 7 days". An action bundles one or more {@see PageActionChange}es
 * so Otto can change several parameters at once. The interaction bridge follows a
 * propose-then-confirm model: the action is broadcast to the chat as a suggestion
 * (see {@see OttoPageActionProposed}), where the user approves; only
 * then does the Filament page adapter apply the filters.
 */
class PageAction
{
    /**
     * @param  string  $name  Machine name the page adapter dispatches on (e.g. "apply-scan-filters").
     * @param  string  $summary  Human-readable description of the action as a whole.
     * @param  PageActionChange[]  $changes  The individual changes the user can approve/select.
     */
    public function __construct(
        public string $pageType,
        public string $name,
        public string $summary,
        public array $changes = [],
        public bool $requiresConfirmation = true,
        public string $actionId = '',
    ) {
        if ($this->actionId === '') {
            $this->actionId = (string) Str::ulid();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'page_type' => $this->pageType,
            'name' => $this->name,
            'summary' => $this->summary,
            'changes' => array_map(fn (PageActionChange $change): array => $change->toArray(), $this->changes),
            'requires_confirmation' => $this->requiresConfirmation,
        ];
    }
}
