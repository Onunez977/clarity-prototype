<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\DefersToPageSearch;
use App\Ai\Tools\Concerns\ResolvesOttoDateRange;
use App\Ai\Tools\Concerns\ResolvesOttoKitFilter;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\PageAwareTool;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Clickhouse\Models\Scan;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Events\OttoWidgetCreated;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\OttoToolAuth;
use App\Support\Otto\PlatePattern;
use App\Support\Otto\SearchActivityLog;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use Stringable;

class ScanSearchTool implements PageAwareTool, RegisteredTool
{
    use DefersToPageSearch;
    use ResolvesOttoDateRange;
    use ResolvesOttoKitFilter;
    use ResolvesToolAvailability;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    protected function deferralPageType(): string
    {
        return ApplyScanSearchFiltersTool::PAGE_TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapToPageFilterArguments(Request $request): array
    {
        return $this->mapFilledToolArguments($request, [
            'plate',
            'driver_name',
            'kit_name',
            'kit_id',
            'time_range',
            'start_date',
            'end_date',
        ]);
    }

    protected function pageFilterTool(): object
    {
        return new ApplyScanSearchFiltersTool($this->user, $this->investigation);
    }

    /**
     * Same gate the Filament scan list page enforces via its policy. Otto is a
     * second door onto the same records — it must not be a wider one.
     */
    public function authorizedFor(User $user): bool
    {
        return OttoToolAuth::userCan($user, PermissionEnum::ViewAny_SCAN->value);
    }

    public function description(): Stringable|string
    {
        $base = 'Search Clarity scans (plate reads) for the user\'s organization. Use for plate lookups, driver/kit-specific scan history, and time-bounded scan searches — including recent or today\'s scans with no plate or kit. When the user names a driver or kit, pass driver_name and/or kit_name (or kit_id) — do not use plate * alone for that. driver_name is the assigned person; kit_name is the kit login. Returns a page of the most recent matches. Default range is the last 30 days when dates are omitted. Plate, driver, and kit are all optional.';

        if ($this->pageContext?->pageType === ApplyScanSearchFiltersTool::PAGE_TYPE) {
            return $base.' IMPORTANT: the user is currently ON the Scans page, which runs this exact kind of search itself — do NOT use this tool for requests that page can express. For ANY request that can be expressed as that page\'s filters — a plate, kit/driver, FlexSearch, invalid-scans toggle, or time range, INCLUDING a brand-new "search for…"/"find…"/"look up…" request the user has not yet typed into the page — prefer the Scans page\'s own search capability to propose those filters. If you call THIS tool on that page anyway, it will automatically propose page filters and will NEVER return in-chat scan results (no argument can override that).';
        }

        return $base.$this->offPageAvailabilityClause();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plate' => $schema->string()->description('License plate; * wildcards allowed. Optional for broad or time-bounded scan searches.'),
            'driver_name' => $schema->string()->description('Assigned driver display name (the person), case-insensitive; partial OK.'),
            'kit_name' => $schema->string()->description('Kit name / kit login, case-insensitive; partial OK.'),
            'kit_id' => $schema->integer()->description('Exact kit ID when known.'),
            'start_date' => $schema->string()->description($this->ottoStartDateSchemaDescription()),
            'end_date' => $schema->string()->description($this->ottoEndDateSchemaDescription()),
            'time_range' => $schema->string()->description($this->ottoTimeRangeSchemaDescription()),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->mustDeferToPageSearch()) {
            return $this->forwardToPageFiltersOrDefer($request);
        }

        if ($this->investigation->toolCallLimitReached()) {
            return 'Tool call limit reached for this message. Summarize what you have.';
        }

        $this->investigation->incrementToolCalls();

        $orgId = (int) $this->user->org_id;
        $tz = $this->user->timezone ?? 'UTC';
        [$start, $end] = $this->resolveOttoRange($request, $tz);

        $kitFilter = $this->resolveOttoKitFilter($this->user, $request);
        $plate = PlatePattern::normalize((string) ($request['plate'] ?? ''));

        SearchActivityLog::record(SearchActivityLog::SCANS, $this->user, [
            'plateNumber' => $plate !== '' ? [$plate] : null,
            'driverName' => trim((string) ($request['driver_name'] ?? '')) ?: null,
            'kitName' => trim((string) ($request['kit_name'] ?? '')) ?: null,
            'kitId' => isset($request['kit_id']) ? (int) $request['kit_id'] : null,
            'startDate' => $start,
            'endDate' => $end,
        ], $this->investigation->activeConversationId());

        if ($kitFilter['error'] !== null) {
            return $kitFilter['error'];
        }

        $useHot = $start && Carbon::parse($start)->utc()->gte(now()->subDays(7));
        $query = ($useHot ? Scan::hot() : Scan::query())
            ->forOrg($orgId);

        if ($kitFilter['kit_ids'] !== []) {
            $query->whereIntegerInRaw('kit_id', $kitFilter['kit_ids']);
        }

        if ($plate !== '') {
            $query = PlatePattern::apply($query, 'ocr', $plate);
        }

        $query = $query
            ->between($start, $end, $tz)
            ->orderByDesc('scanned_at')
            ->limit(LimitedToolResultMessage::SEARCH_LIMIT);

        $rows = $query->get()->take(LimitedToolResultMessage::SEARCH_LIMIT);

        $searchId = Str::lower(Str::random(12));
        $title = $this->buildScanSearchTitle($plate, $kitFilter['label']);

        $resultIds = LimitedToolResultMessage::capResultIds($rows->pluck('id'));

        $this->recordSearch($searchId, '', $title, [
            'plate' => $plate !== '' ? $plate : null,
            'driver_name' => trim((string) ($request['driver_name'] ?? '')) ?: null,
            'kit_name' => trim((string) ($request['kit_name'] ?? '')) ?: null,
            'kit_id' => isset($request['kit_id']) ? (int) $request['kit_id'] : null,
            'start_date' => $start,
            'end_date' => $end,
        ], $resultIds);

        $conversationId = $this->investigation->activeConversationId();
        if ($conversationId) {
            broadcast(new OttoWidgetCreated($conversationId, (string) Str::uuid(), 'scan', $searchId, $title));
        }

        return LimitedToolResultMessage::forOrderedSearch(
            count($resultIds),
            LimitedToolResultMessage::SEARCH_LIMIT,
            'scan',
            $this->searchScopeClause($kitFilter['label'], $plate),
        );
    }

    protected function searchScopeClause(?string $kitLabel, string $plate): string
    {
        if ($kitLabel !== null && $kitLabel !== '') {
            return ' for '.$kitLabel;
        }

        if ($plate !== '' && $plate !== '*') {
            return ' for '.$plate;
        }

        return '';
    }

    protected function buildScanSearchTitle(string $plate, ?string $kitLabel): string
    {
        $parts = array_values(array_filter([
            $kitLabel,
            $plate !== '' && $plate !== '*' ? $plate : null,
        ]));

        if ($parts === []) {
            return 'Scan search';
        }

        return 'Scan search: '.implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<int>  $resultIds
     */
    protected function recordSearch(string $searchId, string $messageId, string $title, array $parameters, array $resultIds): void
    {
        $props = $this->investigation->data ?? new AgentInvestigationProperties;
        $searches = $props->searches;
        $searches[] = new Search(
            searchId: $searchId,
            messageId: $messageId,
            parameters: $parameters,
            searchType: SearchType::Scan,
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
