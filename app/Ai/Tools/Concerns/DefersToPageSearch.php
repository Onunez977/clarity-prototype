<?php

namespace App\Ai\Tools\Concerns;

use App\Ai\PageContext;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A generic chat-search tool (ScanSearchTool, HitSearchTool, HotlistSearchTool)
 * must NOT run its own in-chat search when the user is on the page that runs
 * that exact search itself — it should defer to the page's propose-then-confirm
 * capability so the user stays on the page they have open.
 *
 * When the model still calls this tool on the page, {@see forwardToPageFiltersOrDefer()}
 * maps the request onto the page's Apply*Filters tool so a real proposal card is
 * broadcast.
 *
 * There is NO model-controlled escape hatch. The only ways to run an in-chat
 * search while the user is conceptually "on" the matching page are server-gated
 * in OttoConversation::send (strip page context): the "Make Otto search instead" continuation
 * prompt, or an InChatSearchIntent keyword phrase.
 */
trait DefersToPageSearch
{
    protected ?PageContext $pageContext = null;

    public function onPage(?PageContext $page): static
    {
        $this->pageContext = $page;

        return $this;
    }

    /** The page type on which this tool's search is served by the page itself. */
    abstract protected function deferralPageType(): string;

    /**
     * Map this chat-search request onto arguments the page's Apply*Filters tool
     * understands. Return [] when nothing mappable was provided (caller falls
     * back to the nudge string so the model can call the page tool itself).
     *
     * @return array<string, mixed>
     */
    abstract protected function mapToPageFilterArguments(Request $request): array;

    /**
     * The page-filter tool that should propose (and broadcast) the mapped filters.
     */
    abstract protected function pageFilterTool(): object;

    /**
     * Whether this run must defer to the page's own search. True whenever the
     * matching page context is present — model args cannot override this.
     */
    protected function mustDeferToPageSearch(): bool
    {
        return $this->pageContext?->pageType === $this->deferralPageType();
    }

    /**
     * A redirect message when this run must defer to the page's own search, or null
     * when the tool is free to run (off the matching page — including search-instead
     * turns where Chat stripped page context).
     */
    protected function pageDeferralMessage(Request $request): ?string
    {
        if (! $this->mustDeferToPageSearch()) {
            return null;
        }

        return 'The user is on the page that runs this exact search itself, so do NOT run it in the chat. '
            .'Use that page\'s own search capability to propose these filters for the user to run on the page '
            .'they are looking at. Calling this chat-search tool on that page will never return in-chat results.';
    }

    /**
     * When on the matching page: forward mappable filters to the page's
     * Apply*Filters tool (proposal card + model-facing "Proposed…" string), or
     * return the nudge when nothing can be mapped. Model args cannot opt out.
     */
    protected function forwardToPageFiltersOrDefer(Request $request): Stringable|string
    {
        $mapped = $this->mapToPageFilterArguments($request);

        if ($mapped === []) {
            return $this->pageDeferralMessage($request)
                ?? 'Use that page\'s own search capability to propose filters.';
        }

        return $this->pageFilterTool()->handle(new Request($mapped));
    }

    /**
     * Off-page reminder so the model does not treat "cannot change this page"
     * as "I have no search tool" (e.g. Company Metrics).
     */
    protected function offPageAvailabilityClause(): string
    {
        return ' Available on every page, including Company Metrics and other dashboards. For live records, call this tool — do not use the knowledge base and do not tell the user to open another page.';
    }

    /**
     * Copy non-empty tool arguments onto the Apply* request.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function mapFilledToolArguments(Request $request, array $keys): array
    {
        $mapped = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $request->all())) {
                continue;
            }

            $value = $request[$key];

            if ($value === null || $value === '') {
                continue;
            }

            $mapped[$key] = $value;
        }

        return $mapped;
    }
}
