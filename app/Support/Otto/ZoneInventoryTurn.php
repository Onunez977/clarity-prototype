<?php

declare(strict_types=1);

namespace App\Support\Otto;

use App\Ai\Tools\ZoneSearchTool;
use App\Data\AgentInvestigation\Search;
use App\Datatype\SearchType;
use App\Models\Investigation;
use App\Models\User;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Tools\Request;

/**
 * Invokes ZoneSearchTool when a zone question finishes with no zone-search call.
 *
 * ZoneSearchTool is already registered. The model can still answer "what zones
 * do I have?" with empty tool_calls and claim the search is unavailable.
 * Prompt text does not close that gap, so the turn runs the tool itself before
 * the pending-prompt key drops. The replacement reply never names tool classes.
 * A recorded search keeps its <!--otto_table:zone:...--> marker.
 */
final class ZoneInventoryTurn
{
    /**
     * Run zone search when this turn did not, and return the reply that should
     * replace the model text. Null leaves the model reply in place.
     */
    public static function ensureSearched(
        User $user,
        Investigation $investigation,
        string $prompt,
        StreamedAgentResponse $response,
    ): ?string {
        if (! RecordLookupAsk::isZoneInventory($prompt) || self::responseCalledZoneSearch($response)) {
            return null;
        }

        if (self::unattributedZoneSearch($investigation) instanceof Search) {
            return null;
        }

        $tool = new ZoneSearchTool($user, $investigation);
        $result = (string) $tool->handle(new Request(self::arguments($prompt)));

        return self::userFacingReply($result, $investigation);
    }

    /**
     * @return array<string, mixed>
     */
    public static function arguments(string $prompt): array
    {
        $normalized = mb_strtolower(trim($prompt));
        $normalized = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        $arguments = [];

        if (preg_match('/\bno[-\s]?scan\b/', $normalized) === 1) {
            $arguments['is_no_scan_zone'] = true;
        }

        if (preg_match('/\bassigned to\s+([a-z0-9 .\'-]{1,80})/', $normalized, $matches) === 1) {
            $driver = trim($matches[1], ' ?.!');

            if ($driver !== '') {
                $arguments['assigned_driver'] = $driver;
            }
        }

        return $arguments;
    }

    private static function responseCalledZoneSearch(StreamedAgentResponse $response): bool
    {
        foreach ($response->toolCalls as $call) {
            if (str_contains((string) $call->name, 'ZoneSearch')) {
                return true;
            }
        }

        return false;
    }

    private static function userFacingReply(string $toolResult, Investigation $investigation): string
    {
        if ($toolResult === OttoScoutAccess::ZONE_NEEDS_SCOUT || $toolResult === OttoScoutAccess::ZONE_NEEDS_ADMIN) {
            return $toolResult;
        }

        $search = self::unattributedZoneSearch($investigation);

        if (! $search instanceof Search) {
            return 'I couldn’t look up Scout patrol zones just now. Please try again.';
        }

        $count = 0;

        foreach ($investigation->data->searchResults ?? [] as $result) {
            if ($result->searchId === $search->searchId) {
                $count++;
            }
        }

        $intro = $count === 0
            ? 'I didn’t find any matching Scout patrol zones.'
            : self::introFor($search);

        return $intro."\n\n<!--otto_table:zone:{$search->searchId}-->";
    }

    private static function introFor(Search $search): string
    {
        $parameters = $search->parameters;

        if (is_string($parameters['assigned_driver'] ?? null) && $parameters['assigned_driver'] !== '') {
            return 'Here are the Scout patrol zones assigned to that driver.';
        }

        if (($parameters['is_no_scan_zone'] ?? null) === true) {
            return 'Here are your no-scan zones.';
        }

        if (is_string($parameters['name'] ?? null) && $parameters['name'] !== '') {
            return 'Here are the Scout patrol zones matching that name.';
        }

        return 'Here are your Scout patrol zones.';
    }

    private static function unattributedZoneSearch(Investigation $investigation): ?Search
    {
        $found = null;

        foreach ($investigation->data->searches ?? [] as $search) {
            if ($search->searchType === SearchType::Zone && $search->messageId === '') {
                $found = $search;
            }
        }

        return $found;
    }
}
