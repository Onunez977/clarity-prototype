<?php

namespace App\Ai;

use App\Models\Investigation;
use Laravel\Ai\Responses\StreamedAgentResponse;

/**
 * Detects replies that claim search results or page-filter proposals without
 * the authoritative artifact that proves the action happened. Tool-call events
 * are attempts only: a blocked or failed call may create neither a Search nor a
 * PageAction. The system prompt forbids false claims, but this is the
 * deterministic backstop (see RunOttoChat).
 *
 * Detection is intentionally conservative — a false positive would discard a
 * legitimate reply — so it fires only when the text asserts results or proposals
 * and the current run has neither a newly recorded Search nor a PageAction in
 * the proposal buffer. Either artifact clears the guard. Prior-turn searches
 * do not count.
 */
class FabricationGuard
{
    public const UNFULFILLED_ACTION_RESPONSE = 'I couldn’t complete that search or create the requested filter proposal. '
        .'No filters were changed and no search was run. Please try again.';

    /**
     * Phrases that assert concrete, just-retrieved scan/hit results. Kept tight so
     * capability answers ("I can search your scans and hits") and empty-result
     * replies ("I didn't find any scans") never match. Prior searches on the
     * investigation do NOT excuse these — only a Search recorded this run does.
     */
    protected const RESULT_CLAIM_PATTERNS = [
        '/\bhere (?:are|is)\b[^.?!]*\b(?:scan|hit|result|sighting|record)s?\b/i',
        // "Here are the 25 most recent — …" (noun elided after a count)
        '/\bhere (?:are|is)\b[^.?!]*\b\d+\s+(?:most recent|recent)\b/i',
        '/\b\d+\s+(?:most recent\s+|recent\s+)?(?:scan|hit|result|sighting|record)s?\b/i',
        '/\b\d+\s+most recent\b/i',
        '/\bthe (?:most recent|latest)\b[^.?!]*\b(?:scan|hit)s?\b/i',
        '/\bI(?:\'ve| have)?\s+(?:searched|found|looked up)\b/i',
        '/\bactivity\b[^.?!]*\b(?:captured|concentrated|spans?|spread|shows?|was seen)\b/i',
    ];

    /**
     * Claims that a page-filter proposal exists. These only trigger when the
     * investigation's per-run action buffer is empty.
     */
    protected const PAGE_ACTION_CLAIM_PATTERNS = [
        '/\b(?:I(?:\'ve| have)?\s+)?(?:suggested|proposed)\b[^.?!]*\b(?:filter|search|change)s?\b/i',
        '/\b(?:filter|search)\s+(?:change|proposal)\b[^.?!]*\b(?:below|review|accept)\b/i',
        '/\b(?:review|accept)\b[^.?!]*\b(?:filter|search|change)s?\b/i',
        '/\b(?:updated|applied|changed|set)\b[^.?!]*\bfilters?\b/i',
    ];

    public function looksFabricated(StreamedAgentResponse $response, Investigation $investigation): bool
    {
        // This-run artifacts are authoritative. A real PageAction or Search clears
        // fabrication even when the model also narrates results (common on the page
        // path: propose filters, then "I've looked up…" / "here are the scans").
        if ($investigation->hasProposedPageActions() || $investigation->hasRecordedSearchThisRun()) {
            return false;
        }

        // No artifact: tool-call events are attempts only. Prior-turn searches
        // also do not excuse a this-run claim.
        $text = (string) $response->text;

        return $this->assertsPageAction($text) || $this->assertsResults($text);
    }

    protected function assertsPageAction(string $text): bool
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];

        foreach ($sentences as $sentence) {
            if (preg_match('/\b(?:not|never|cannot|can\'t|couldn\'t|didn\'t|haven\'t|hasn\'t)\b/i', $sentence) === 1) {
                continue;
            }

            foreach (self::PAGE_ACTION_CLAIM_PATTERNS as $pattern) {
                if (preg_match($pattern, $sentence) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function assertsResults(string $text): bool
    {
        foreach (self::RESULT_CLAIM_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
