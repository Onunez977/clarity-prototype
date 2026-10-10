<?php

namespace App\Ai\Tools;

use App\Ai\PageContext;
use App\Ai\Tools\Concerns\BuildsPageSearchFilters;
use App\Ai\Tools\Concerns\ProposesPageActions;
use App\Ai\Tools\Concerns\ResolvesOttoDateRange;
use App\Ai\Tools\Concerns\ResolvesOttoKitFilter;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\OttoToolAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Page-specific tool: when the user is on the Hits list, Otto proposes applying
 * Filament table filters and running the page search (propose-then-confirm).
 */
class ApplyHitSearchFiltersTool implements RegisteredTool
{
    use BuildsPageSearchFilters;
    use ProposesPageActions;
    use ResolvesOttoDateRange;
    use ResolvesOttoKitFilter;
    use ResolvesToolAvailability;

    public const PAGE_TYPE = 'hit-search';

    public const ACTION = 'apply-hit-filters';

    /**
     * @var array<string, array{field: string, label: string, empty: mixed}>
     */
    private const CLEARABLE = [
        'plate' => ['field' => 'plate', 'label' => 'Plate', 'empty' => ''],
        'vin' => ['field' => 'vin', 'label' => 'VIN', 'empty' => ''],
        'order_id' => ['field' => 'order_id', 'label' => 'Order ID', 'empty' => ''],
        'kits' => ['field' => 'kits', 'label' => 'Kit users', 'empty' => []],
        'clients' => ['field' => 'clients', 'label' => 'Client(s)', 'empty' => []],
        'hit_type' => ['field' => 'hit_type', 'label' => 'Hit type', 'empty' => null],
    ];

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    public function availableOnPage(?PageContext $page): bool
    {
        return $page?->pageType === self::PAGE_TYPE;
    }

    public function authorizedFor(User $user): bool
    {
        return OttoToolAuth::userCan($user, PermissionEnum::ViewAny_Hit->value);
    }

    /**
     * Hits scanned-date toggles have no "yesterday".
     *
     * @return list<string>
     */
    protected function pageDateTogglePresets(): array
    {
        return ['today', 'last_7d', 'last_30d'];
    }

    protected function honorsLatestHitsWindow(): bool
    {
        return true;
    }

    public function description(): Stringable|string
    {
        return 'THE PRIMARY way to run a hit search while the user is on the Hits page. Hits are matches between scanned plates and hotlist assignments. Set up the page\'s filters and run the search for them. Use this for ANY hit-search request on this page — refining what they already see OR starting a brand-new search ("find hits for ABC123 in the last 30 days", "open hits on Maria\'s kit today", "show me today\'s hits", "most recent hits"). A time range alone is enough — do NOT ask for a plate, driver, or kit when they asked for recent/today\'s hits. When they say "latest" and name no other time range, the window is the last 12 hours. Prefer this over running your own chat search. Call this tool IMMEDIATELY when the request maps onto page filters — do NOT ask page-vs-chat, and do NOT ask about filters they did not name. In ONE call you can: set plate, VIN, order ID, assignment status, hit type (live/historical/staging/probable), kit users (driver_name/kit_name/kit_id), client, FlexSearch, and time range (applied to both hit date and scanned date so the windows agree). CLEAR plate, vin, order_id, kits, clients, or hit_type. Only include filters you are CHANGING. This page searches HITS only — if they ask for scans or hotlist assignments, do not use this tool.';
    }

    public function handle(Request $request): Stringable|string
    {
        $changes = array_filter([
            'plate' => $this->plateChange($request),
            'vin' => $this->vinChange($request),
            'order_id' => $this->orderIdChange($request),
            'status' => $this->statusChange($request),
            'hit_type' => $this->hitTypeChange($request),
            'kits' => $this->kitChange($request),
            'clients' => $this->clientChange($request),
            'flex_search' => $this->flexSearchChange($request),
            'time_range' => $this->timeRangeChange($request),
        ]);

        $changes = array_merge($changes, $this->clearChanges($request, self::CLEARABLE));

        return $this->proposeFilterChanges(
            $changes,
            self::PAGE_TYPE,
            self::ACTION,
            'hits',
            'Provide at least one change to make (set or clear plate, VIN, order ID, kits, or clients; set status or hit type; toggle FlexSearch; or set a time range).',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'plate' => $schema->string()->description('Set the plate filter. Supports * wildcards. Optional.'),
            'vin' => $schema->string()->description('Set the VIN filter. Optional.'),
            'order_id' => $schema->string()->description('Set the remote order ID filter. Optional.'),
            'status' => $schema->string()->description('Assignment status: Open, Hold, Repossessed, ClosedPositive, Closed, NewFromClient, Invalid, Other. Optional.'),
            'hit_type' => $schema->string()->description('Hit type: live, historical, staging live, staging historical, probable match. Optional.'),
            'driver_name' => $schema->string()->description('Assigned driver display name; resolves to kit users. Optional.'),
            'kit_name' => $schema->string()->description('Kit name / kit login. Optional.'),
            'kit_id' => $schema->integer()->description('Exact kit ID when known. Optional.'),
            'client' => $schema->string()->description('Client name or client org id. Optional.'),
            'flex_search' => $schema->boolean()->description('Toggle FlexSearch. Optional.'),
            'time_range' => $schema->string()->description($this->ottoTimeRangeSchemaDescription()),
            'start_date' => $schema->string()->description($this->ottoStartDateSchemaDescription()),
            'end_date' => $schema->string()->description($this->ottoEndDateSchemaDescription()),
            'clear' => $schema->array()
                ->items($schema->string()->enum(['plate', 'vin', 'order_id', 'kits', 'clients', 'hit_type']))
                ->description('Filters to CLEAR. Optional.'),
        ];
    }
}
