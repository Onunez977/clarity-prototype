<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Events\OttoWidgetCreated;
use App\Models\Investigation;
use App\Models\User;
use App\Models\Zone;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\OttoScoutAccess;
use App\Support\Otto\SearchActivityLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class ZoneSearchTool implements RegisteredTool
{
    use ResolvesToolAvailability;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    /**
     * Registered for every Otto user so the model can call this and relay the
     * Scout / admin soft message instead of claiming zones are unavailable.
     */
    public function authorizedFor(User $user): bool
    {
        return true;
    }

    public function description(): Stringable|string
    {
        return 'Search this organization\'s Scout patrol zones by name, no-scan flag, or assigned driver. Call this on the same turn the user asks what zones they have, which zones are no-scan, or which driver a zone is assigned to, including a bare "what zones do I have?". Never tell the user this search is unavailable. Returns a page of the most recent matching zones. Do not use this to create or edit zones — use ProposeCreateZone / ProposeUpdateZone for that. Soft-deleted zones are never returned.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Zone name; partial, case-insensitive. Optional.'),
            'is_no_scan_zone' => $schema->boolean()->description('true = only no-scan zones; false = only patrol (scan) zones. Omit to search both.'),
            'assigned_driver' => $schema->string()->description('Assigned driver display name; partial, case-insensitive. Optional.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->investigation->toolCallLimitReached()) {
            return 'Tool call limit reached for this message. Summarize what you have.';
        }

        $denied = OttoScoutAccess::zoneDeniedMessage($this->user);

        if ($denied !== null) {
            return $denied;
        }

        $this->investigation->incrementToolCalls();

        $name = trim((string) ($request['name'] ?? ''));
        $driver = trim((string) ($request['assigned_driver'] ?? ''));
        $rawNoScan = $request['is_no_scan_zone'] ?? null;
        $isNoScan = ($rawNoScan === null || $rawNoScan === '') ? null : (bool) $rawNoScan;

        SearchActivityLog::record(SearchActivityLog::ZONES, $this->user, [
            'name' => $name !== '' ? $name : null,
            'isNoScanZone' => $isNoScan,
            'assignedDriver' => $driver !== '' ? [$driver] : null,
        ], $this->investigation->activeConversationId());

        $query = Zone::query()
            ->forOrg((int) $this->user->org_id)
            ->with('users')
            ->when($name !== '', fn ($q) => $q->where('name', 'ilike', '%'.$name.'%'))
            ->when($isNoScan !== null, fn ($q) => $q->where('is_no_scan_zone', $isNoScan))
            ->when($driver !== '', fn ($q) => $q->whereHas(
                'users',
                fn ($users) => $users->where('users.name', 'ilike', '%'.$driver.'%'),
            ))
            ->orderByDesc('created_at')
            ->limit(LimitedToolResultMessage::SEARCH_LIMIT);

        $rows = $query->get();
        $resultIds = LimitedToolResultMessage::capResultIds($rows->pluck('id'));

        $searchId = Str::lower(Str::random(12));
        $title = $this->titleFor($name, $isNoScan, $driver);

        $this->recordSearch($searchId, $title, [
            'name' => $name !== '' ? $name : null,
            'is_no_scan_zone' => $isNoScan,
            'assigned_driver' => $driver !== '' ? $driver : null,
        ], $resultIds);

        $conversationId = $this->investigation->activeConversationId();
        if ($conversationId) {
            broadcast(new OttoWidgetCreated(
                (string) $conversationId,
                '',
                SearchType::Zone->value,
                $searchId,
                $title,
            ));
        }

        return LimitedToolResultMessage::forOrderedSearch(
            count($resultIds),
            LimitedToolResultMessage::SEARCH_LIMIT,
            'zone',
        );
    }

    private function titleFor(string $name, ?bool $isNoScan, string $driver): string
    {
        if ($name !== '') {
            return 'Zone search: '.$name;
        }

        if ($driver !== '') {
            return 'Zone search: assigned to '.$driver;
        }

        if ($isNoScan === true) {
            return 'Zone search: no-scan';
        }

        if ($isNoScan === false) {
            return 'Zone search: patrol';
        }

        return 'Zone search';
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<int>  $resultIds
     */
    private function recordSearch(string $searchId, string $title, array $parameters, array $resultIds): void
    {
        $props = $this->investigation->data ?? new AgentInvestigationProperties;
        $searches = $props->searches;
        $searches[] = new Search(
            searchId: $searchId,
            messageId: '',
            parameters: $parameters,
            searchType: SearchType::Zone,
            title: $title,
            createdBy: $this->user->id,
            conversationId: (string) $this->investigation->activeConversationId(),
        );

        $results = $props->searchResults;
        foreach ($resultIds as $resultId) {
            $results[] = new SearchResult(searchId: $searchId, resultId: $resultId);
        }

        $this->investigation->data = new AgentInvestigationProperties(
            searches: $searches,
            searchResults: $results,
        );
    }
}
