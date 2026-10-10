<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\DefersToPageSearch;
use App\Ai\Tools\Concerns\ResolvesOttoDateRange;
use App\Ai\Tools\Concerns\ResolvesOttoKitFilter;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\PageAwareTool;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Clickhouse\Models\ClickhouseModel;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Enums\DispositionEnum;
use App\Enums\HotlistStatusEnum;
use App\Events\OttoWidgetCreated;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Hits\HitsListQuery;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\OttoToolAuth;
use App\Support\Otto\PlatePattern;
use App\Support\Otto\SearchActivityLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use Stringable;

class HitSearchTool implements PageAwareTool, RegisteredTool
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
        return ApplyHitSearchFiltersTool::PAGE_TYPE;
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
        return new ApplyHitSearchFiltersTool($this->user, $this->investigation);
    }

    protected function honorsLatestHitsWindow(): bool
    {
        return true;
    }

    /**
     * Same gate the Filament hit list page enforces via its policy. Otto is a
     * second door onto the same records — it must not be a wider one.
     */
    public function authorizedFor(User $user): bool
    {
        return OttoToolAuth::userCan($user, PermissionEnum::ViewAny_Hit->value);
    }

    public function description(): Stringable|string
    {
        $base = 'Search Clarity hits (hotlist matches) for the user\'s organization. Use when the user asks about hits, alerts, or hotlist matches — including recent or today\'s hits with no plate or kit. Optional driver_name, kit_name, or kit_id narrows to one kit\'s hits. Returns a page of the most recent matches. Default range is the last 30 days when dates are omitted. When the user says "latest" and names no other time range, the range is the last 12 hours (that meaning is hits only). Plate, driver, and kit are all optional.';

        if ($this->pageContext?->pageType === ApplyHitSearchFiltersTool::PAGE_TYPE) {
            return $base.' IMPORTANT: the user is currently ON the Hits page, which runs this exact kind of search itself. For ANY request that can be expressed as that page\'s filters — a plate, VIN, order ID, status, hit type, kit/driver, client, FlexSearch, or time range, INCLUDING a brand-new "search for…"/"find…"/"look up…" request the user has not yet typed into the page — prefer the Hits page\'s own search capability to propose those filters. If you call THIS tool on that page anyway, it will automatically propose page filters and will NEVER return in-chat hit results (no argument can override that).';
        }

        return $base.$this->offPageAvailabilityClause();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plate' => $schema->string()->description('License plate; * wildcards allowed. Optional for broad hit searches.'),
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

        $tz = $this->user->timezone ?? 'UTC';
        [$start, $end] = $this->resolveOttoRange($request, $tz);
        $startUtc = $start ? convertToUtc($start, $tz) : null;
        $endUtc = $end ? convertToUtc($end, $tz) : null;

        $kitFilter = $this->resolveOttoKitFilter($this->user, $request);
        $plate = PlatePattern::normalize((string) ($request['plate'] ?? ''));

        SearchActivityLog::record(SearchActivityLog::HITS, $this->user, [
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

        $query = HitsListQuery::baseQuery($this->user, $startUtc?->toDateTimeString(), HotlistStatusEnum::Open->value);

        if ($kitFilter['kit_ids'] !== []) {
            $column = $query->getModel() instanceof ClickhouseModel ? 'kit_id' : 'hits.kit_id';
            $query->whereIntegerInRaw($column, $kitFilter['kit_ids']);
        }

        if ($plate !== '') {
            $column = $query->getModel() instanceof ClickhouseModel ? 'ocr' : 'scans.ocr';
            $query = PlatePattern::apply($query, $column, $plate);
        }

        $query = $query->forScannedBetween($startUtc, $endUtc);
        $this->constrainActionable($query);
        $query = $query
            ->orderByDesc('created_at')
            ->limit(LimitedToolResultMessage::SEARCH_LIMIT);

        $rows = $query->get()->take(LimitedToolResultMessage::SEARCH_LIMIT);

        $searchId = Str::lower(Str::random(12));
        $title = $this->buildHitSearchTitle($plate, $kitFilter['label']);

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
            broadcast(new OttoWidgetCreated($conversationId, (string) Str::uuid(), 'hit', $searchId, $title));
        }

        $scopeClause = $kitFilter['label'] !== null && $kitFilter['label'] !== ''
            ? ' for '.$kitFilter['label']
            : ($plate !== '' ? ' for '.$plate : '');

        return LimitedToolResultMessage::forOrderedSearch(
            count($resultIds),
            LimitedToolResultMessage::SEARCH_LIMIT,
            'hit',
            $scopeClause,
        );
    }

    protected function buildHitSearchTitle(string $plate, ?string $kitLabel): string
    {
        $parts = array_values(array_filter([
            $kitLabel,
            $plate !== '' ? $plate : null,
        ]));

        if ($parts === []) {
            return 'Hit search';
        }

        return 'Hit search: '.implode(' · ', $parts);
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
            searchType: SearchType::Hit,
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

    private function constrainActionable(Builder $query): void
    {
        if ($query->getModel() instanceof ClickhouseModel) {
            $query->actionableDisposition();

            return;
        }

        $query->forDispositions([
            DispositionEnum::NEW_HIT->value,
            DispositionEnum::ACCESSIBLE->value,
        ]);
    }
}
