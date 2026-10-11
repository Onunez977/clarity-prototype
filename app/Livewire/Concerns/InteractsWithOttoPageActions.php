<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\On;

/**
 * Makes a Livewire page an Otto page adapter: it advertises its context to the
 * global page registry and applies the page actions Otto proposes.
 *
 * Adding a new adapter is implementing the hooks below on a Livewire/Filament
 * page and using this trait. The read path re-registers the page's current
 * state after every render. The write path listens for the same-tab
 * `otto-page-action` event the chat dispatches when the user Accepts a proposal.
 */
trait InteractsWithOttoPageActions
{
    /** The page type this adapter answers for (e.g. ApplyScanSearchFiltersTool::PAGE_TYPE). */
    abstract protected function ottoPageType(): string;

    /**
     * The current relevant state of this page (active filters) Otto should be
     * aware of. Free-form; keep it to the fields that matter.
     *
     * @return array<string, mixed>
     */
    abstract protected function ottoPageContextState(): array;

    /**
     * Apply an Otto-proposed, user-confirmed action to this page.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function applyOttoPageAction(string $name, array $payload): void;

    /** A human-readable description of this page for Otto's prompt. */
    protected function ottoPageDescription(): string
    {
        return '';
    }

    /**
     * Re-check, server-side, that the current user may drive this page. The
     * client payload is only a hint; this is the real authorization boundary.
     */
    protected function ottoPageActionAuthorized(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[On('otto-page-action')]
    public function handleOttoPageAction(string $pageType, string $name, array $payload = []): void
    {
        if ($pageType !== $this->ottoPageType()) {
            return;
        }

        if (! $this->ottoPageActionAuthorized()) {
            return;
        }

        $this->applyOttoPageAction($name, $payload);
    }

    /**
     * Livewire lifecycle hook (runs after each render): push this page's
     * descriptor + current state into the global Otto registry.
     */
    public function renderedInteractsWithOttoPageActions(): void
    {
        $descriptor = [
            'pageType' => $this->ottoPageType(),
            'description' => $this->ottoPageDescription(),
            'state' => $this->ottoPageContextState(),
        ];

        $this->js('window.Otto && window.Otto.registerPage('.json_encode($descriptor, JSON_THROW_ON_ERROR).')');
    }
}
