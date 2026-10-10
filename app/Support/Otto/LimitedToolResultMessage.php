<?php

declare(strict_types=1);

namespace App\Support\Otto;

use Illuminate\Support\Str;

/**
 * Consistent LLM-facing copy when Otto tools cap how many rows they return.
 */
final class LimitedToolResultMessage
{
    public const SEARCH_LIMIT = 50;

    public const FLEET_DRIVER_SUMMARY_LIMIT = 8;

    /**
     * Keep at most SEARCH_LIMIT ids from an already-fetched page.
     *
     * @param  iterable<mixed>  $ids
     * @return list<int>
     */
    public static function capResultIds(iterable $ids): array
    {
        $capped = [];

        foreach ($ids as $id) {
            $capped[] = (int) $id;

            if (count($capped) === self::SEARCH_LIMIT) {
                break;
            }
        }

        return $capped;
    }

    /**
     * Message after scan, hit, or assignment searches (ordered most-recent-first).
     *
     * $count is the length of the limited page, never a warehouse total.
     * Mention the cap / "there may be more" only when $count === $limit.
     */
    public static function forOrderedSearch(
        int $count,
        int $limit,
        string $singular,
        string $scopeClause = '',
    ): string {
        $widget = 'An interactive results table is displayed below.';
        $suffix = 'Summarize patterns in 3–5 sentences; do not list individual records. Never say "I found N" or state a total number of matching records in Clarity.';

        if ($count === 0) {
            return 'No matching '.Str::plural($singular).$scopeClause.". {$widget} {$suffix}";
        }

        $shown = min($count, $limit);
        $noun = Str::plural($singular, $shown);

        if ($count >= $limit) {
            return "Here are the most recent {$limit} {$noun}{$scopeClause}. {$widget} "
                ."Each search returns up to {$limit} of the most recent matches and there may be more in Clarity. "
                ."If the user asks whether that is everything, explain this per-search cap. {$suffix}";
        }

        $useThisCount = $shown === 1
            ? "Repeat the one most recent {$noun} in your reply — that is this search's returned page. Do not use a different count."
            : "Repeat the {$shown} most recent {$noun} in your reply — that is this search's returned page. Do not use a different count.";

        if ($shown === 1) {
            return "Here is the most recent {$noun}{$scopeClause}. {$widget} {$useThisCount} {$suffix}";
        }

        return "Here are the most recent {$shown} {$noun}{$scopeClause}. {$widget} {$useThisCount} {$suffix}";
    }

    /**
     * Prefix for fleet-wide Scout driver summaries (not a full dump).
     */
    public static function fleetDriverSummaryIntro(int $shown, int $limit): string
    {
        if ($shown >= $limit) {
            return "Recent Scout positions (showing the {$limit} most recent drivers; not a full fleet dump):";
        }

        return 'Recent Scout positions (not a full fleet dump):';
    }

    /**
     * Trailing note when more drivers exist beyond the fleet summary cap.
     */
    public static function fleetDriverSummaryOverflow(int $extra, int $limit): string
    {
        if ($extra <= 0) {
            return '';
        }

        return "…and {$extra} more with a recent position. Each fleet summary shows up to {$limit} drivers; ask for a specific driver or kit for details.";
    }

    /**
     * Message after knowledge-base article retrieval.
     */
    public static function forKnowledgeArticles(int $count, int $limit, string $query, string $sections): string
    {
        $header = $count >= $limit
            ? "Here are the top {$limit} knowledge-base articles matching \"{$query}\" (up to {$limit} per lookup; there may be more documentation). "
            : "Here is the relevant Clarity knowledge for \"{$query}\". ";

        return $header.'Answer using only this material, prefer the most specific article when more than one applies, '
            .'and respond in your own words without mentioning documents, articles, or how you found this. '
            .'If the user asks whether that is all available documentation, explain the per-lookup cap:'."\n\n"
            .$sections;
    }
}
