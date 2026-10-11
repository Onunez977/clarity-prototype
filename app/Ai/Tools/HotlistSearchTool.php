<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\DefersToPageSearch;
use App\Ai\Tools\Concerns\ResolvesOttoDateRange;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\PageAwareTool;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Enums\HotlistStatusEnum;
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

class HotlistSearchTool implements PageAwareTool, RegisteredTool
{
    use DefersToPageSearch;
    use ResolvesOttoDateRange;
    use ResolvesToolAvailability;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    protected function deferralPageType(): string
    {
        return ApplyHotlistSearchFiltersTool::PAGE_TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapToPageFilterArguments(Request $request): array
    {
        return $this->mapFilledToolArguments($request, [
            'plate',
            'vin',
            'status',
            'order_id',
            'client',
        ]);
    }

    protected function pageFilterTool(): object
    {
        return new ApplyHotlistSearchFiltersTool($this->user, $this->investigation);
    }

    /**
     * Same gate the Filament hotlist assignment list page enforces via its policy. Otto is a
     * second door onto the same records — it must not be a wider one.
     */
    public function authorizedFor(User $user): bool
    {
        return OttoToolAuth::userCan($user, PermissionEnum::ViewAny_Hotlist->value);
    }

    public function description(): Stringable|string
    {
        $base = 'Search Clarity hotlist assignments (wanted vehicles) for the user\'s organization, including direct assignments and staging assignments the org is approved to see. Use when the user asks what they are looking for, open assignments, order status, VIN or plate on a hotlist, or client assignments. Do not use this for hit or match events — use HitSearch for those. Plate is optional. Returns a page of the most recent matches. There is no default date range; assignments stay open for a long time.';

        if ($this->pageContext?->pageType === ApplyHotlistSearchFiltersTool::PAGE_TYPE) {
            return $base.' IMPORTANT: the user is currently ON the Hotlist (assignments) page, which filters this exact kind of record itself. For ANY request that can be expressed as that page\'s filters — a plate, VIN, order ID, status, client, FlexSearch, or missing-plate — prefer the Hotlist page\'s own capability to propose those filters. If you call THIS tool on that page anyway, it will automatically propose page filters and will NEVER return in-chat assignment results, UNLESS the request is only a date window (this page has no date-range filter — then this tool may run in chat).';
        }

        return $base.$this->offPageAvailabilityClause();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plate' => $schema->string()->description('License plate; * wildcards allowed. Optional so open-assignment searches work.'),
            'vin' => $schema->string()->description('Vehicle identification number (partial match).'),
            'status' => $schema->string()->description('Assignment status name or value (Open, Hold, Repossessed, ClosedPositive, Closed, NewFromClient, Invalid, Other).'),
            'order_id' => $schema->string()->description('External order id (remote_order_id).'),
            'client' => $schema->string()->description('Client name or client org id from the org\'s hotlist clients.'),
            'source' => $schema->string()->description('Assignment source: direct or staging.'),
            'start_date' => $schema->string()->description($this->ottoStartDateSchemaDescription().' Optional. No default range.'),
            'end_date' => $schema->string()->description($this->ottoEndDateSchemaDescription().' Optional. No default range.'),
            'time_range' => $schema->string()->description($this->ottoTimeRangeSchemaDescription().' Omit to search all open history.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->mustDeferToPageSearch()) {
            if ($this->mapToPageFilterArguments($request) !== []) {
                return $this->forwardToPageFiltersOrDefer($request);
            }

            if (! $this->requestHasDateWindow($request)) {
                return $this->pageDeferralMessage($request)
                    ?? 'Use that page\'s own search capability to propose filters.';
            }
        }

        if ($this->investigation->toolCallLimitReached()) {
            return 'Tool call limit reached for this message. Summarize what you have.';
        }

        $statusInput = $request['status'] ?? null;
        $status = null;
        if ($statusInput !== null && $statusInput !== '') {
            $status = $this->resolveStatus($statusInput);
            if ($status === null) {
                $valid = collect(HotlistStatusEnum::cases())->map(fn (HotlistStatusEnum $case): string => $case->name)->implode(', ');

                return "I don't recognize that assignment status. Valid statuses: {$valid}.";
            }
        }

        $this->investigation->incrementToolCalls();

        $orgId = (int) $this->user->org_id;
        $tz = $this->user->timezone ?? 'UTC';
        [$start, $end] = $this->resolveOttoRange($request, $tz, defaultLast30Days: false);

        $plate = PlatePattern::normalize((string) ($request['plate'] ?? ''));
        $vin = trim((string) ($request['vin'] ?? ''));
        $orderId = trim((string) ($request['order_id'] ?? ''));
        $client = trim((string) ($request['client'] ?? ''));
        $source = strtolower(trim((string) ($request['source'] ?? '')));
        $clientIds = $this->resolveClientIds($client);

        $query = ClickhouseHotlist::query()
            ->visibleToOrg($orgId, $this->stagingOrgIds());

        if ($plate !== '') {
            $query = PlatePattern::apply($query, 'plate', $plate);
        }

        $query = $query
            ->when($vin !== '', fn ($q) => $q->forVin($vin))
            ->when($status !== null, fn ($q) => $q->forStatus($status))
            ->when($orderId !== '', fn ($q) => $q->forOrderId($orderId))
            ->when($client !== '' && $clientIds !== [], fn ($q) => $q->forClients($clientIds))
            ->when($source === 'direct', fn ($q) => $q->direct())
            ->when($source === 'staging', fn ($q) => $q->staged())
            ->when($start && $end, fn ($q) => $q->between($start, $end, $tz))
            ->when($start && ! $end, fn ($q) => $q->createdAfter(Carbon::parse($start, $tz)->utc()->toDateTimeString()))
            ->when($end && ! $start, fn ($q) => $q->where('created_at', '<=', Carbon::parse($end, $tz)->utc()->toDateTimeString()))
            ->orderByDesc('created_at')
            ->limit(LimitedToolResultMessage::SEARCH_LIMIT);

        SearchActivityLog::record(SearchActivityLog::HOTLISTS, $this->user, [
            'plateNumber' => $plate !== '' ? [$plate] : null,
            'vin' => $vin !== '' ? [$vin] : null,
            'status' => $status,
            'orderId' => $orderId !== '' ? $orderId : null,
            'client' => $client !== '' ? $client : null,
            'source' => in_array($source, ['direct', 'staging'], true) ? $source : null,
            'startDate' => $start,
            'endDate' => $end,
        ], $this->investigation->activeConversationId());

        $rows = ($client !== '' && $clientIds === [])
            ? collect()
            : $query->get()->take(LimitedToolResultMessage::SEARCH_LIMIT);

        $searchId = Str::lower(Str::random(12));
        $messageId = (string) Str::uuid();
        $title = $this->titleFor($plate, $orderId, $status);

        $resultIds = LimitedToolResultMessage::capResultIds($rows->pluck('id'));

        $this->recordSearch($searchId, $messageId, $title, [
            'plate' => $plate !== '' ? $plate : null,
            'vin' => $vin !== '' ? $vin : null,
            'status' => $status,
            'order_id' => $orderId !== '' ? $orderId : null,
            'client' => $client !== '' ? $client : null,
            'source' => in_array($source, ['direct', 'staging'], true) ? $source : null,
            'start_date' => $start,
            'end_date' => $end,
        ], $resultIds);

        $conversationId = $this->investigation->activeConversationId();
        if ($conversationId) {
            broadcast(new OttoWidgetCreated($conversationId, $messageId, 'hotlist', $searchId, $title));
        }

        $scopeClause = $plate !== '' ? ' for '.$plate : '';

        return LimitedToolResultMessage::forOrderedSearch(
            count($resultIds),
            LimitedToolResultMessage::SEARCH_LIMIT,
            'assignment',
            $scopeClause,
        );
    }

    protected function resolveStatus(mixed $status): ?int
    {
        if ($status === null || $status === '') {
            return null;
        }

        if (is_numeric($status)) {
            return HotlistStatusEnum::tryFrom((int) $status)?->value;
        }

        $normalized = Str::studly((string) $status);

        foreach (HotlistStatusEnum::cases() as $case) {
            if (strcasecmp($case->name, (string) $status) === 0 || strcasecmp($case->name, $normalized) === 0) {
                return $case->value;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    protected function resolveClientIds(string $client): array
    {
        if ($client === '') {
            return [];
        }

        $clients = $this->user->org?->getHotlistClients() ?? collect();

        if (is_numeric($client)) {
            $id = (int) $client;

            return $clients->has($id) ? [$id] : [];
        }

        $needle = strtolower($client);

        return $clients
            ->filter(fn ($name) => str_contains(strtolower((string) $name), $needle))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function stagingOrgIds(): array
    {
        return ($this->user->org?->stagingOrgIds() ?? collect())
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function titleFor(string $plate, string $orderId, ?int $status): string
    {
        if ($plate !== '') {
            return 'Assignment search: '.$plate;
        }

        if ($orderId !== '') {
            return 'Assignment search: order '.$orderId;
        }

        if ($status !== null) {
            return 'Assignment search: '.(HotlistStatusEnum::tryFrom($status)?->name ?? 'status');
        }

        return 'Assignment search';
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
            searchType: SearchType::Hotlist,
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

    protected function requestHasDateWindow(Request $request): bool
    {
        return trim((string) ($request['time_range'] ?? '')) !== ''
            || trim((string) ($request['start_date'] ?? '')) !== ''
            || trim((string) ($request['end_date'] ?? '')) !== '';
    }
}
