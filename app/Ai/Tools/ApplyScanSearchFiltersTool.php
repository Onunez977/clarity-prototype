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
 * Page-specific tool: when the user is on the Scans list, Otto proposes applying
 * Filament table filters and running the page search (propose-then-confirm).
 */
class ApplyScanSearchFiltersTool implements RegisteredTool
{
    use BuildsPageSearchFilters;
    use ProposesPageActions;
    use ResolvesOttoDateRange;
    use ResolvesOttoKitFilter;
    use ResolvesToolAvailability;

    public const PAGE_TYPE = 'scan-search';

    public const ACTION = 'apply-scan-filters';

    /**
     * @var array<string, array{field: string, label: string, empty: mixed}>
     */
    private const CLEARABLE = [
        'plate' => ['field' => 'plate', 'label' => 'Plate', 'empty' => ''],
        'kits' => ['field' => 'kits', 'label' => 'Kit users', 'empty' => []],
        'invalid' => ['field' => 'invalid', 'label' => 'Invalid scans', 'empty' => false],
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
        return OttoToolAuth::userCan($user, PermissionEnum::ViewAny_SCAN->value);
    }

    public function description(): Stringable|string
    {
        return 'THE PRIMARY way to run a scan search while the user is on the Scans page. Set up the page\'s filters and run the search for them. Use this for ANY scan-search request on this page — refining what they already see OR starting a brand-new search they have not typed into the page yet ("search for ABC123 over the last 90 days", "find Priya\'s scans today", "show me today\'s scans", "most recent scans"). A time range alone is enough — do NOT ask for a plate, driver, or kit when they asked for recent/today\'s scans. Prefer this over running your own chat search: keep the user working in the page they already have open. Call this tool IMMEDIATELY in the same reply when the request maps onto page filters — do NOT ask whether they want page filters vs chat results, and do NOT ask clarifying questions about filters they did not name (leave those at the page\'s current values). In ONE call you can change several things at once: set a plate (supports * wildcards); filter to kit users via driver_name, kit_name, or kit_id; toggle FlexSearch; show only invalid scans; set a time range (preset today/yesterday/last_7_days/last_30_days OR a custom start_date+end_date); and CLEAR plate, kits, or invalid. Only include the filters you are actually CHANGING. Each change is presented as its own item they can approve; nothing changes and no search runs until they accept. This page searches SCANS only — if they ask for hits/alerts or hotlist assignments, do not use this tool.';
    }

    public function handle(Request $request): Stringable|string
    {
        $changes = array_filter([
            'plate' => $this->plateChange($request),
            'kits' => $this->kitChange($request),
            'flex_search' => $this->flexSearchChange($request),
            'invalid' => $this->booleanChange($request, 'invalid', 'invalid', 'Invalid scans'),
            'time_range' => $this->timeRangeChange($request),
        ]);

        $changes = array_merge($changes, $this->clearChanges($request, self::CLEARABLE));

        return $this->proposeFilterChanges(
            $changes,
            self::PAGE_TYPE,
            self::ACTION,
            'scans',
            'Provide at least one change to make (set or clear plate or kit users; toggle FlexSearch or invalid scans; or set a time range).',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'plate' => $schema->string()->description('Set the plate filter. Supports * wildcards. Optional.'),
            'driver_name' => $schema->string()->description('Assigned driver display name; resolves to kit users on this page. Optional.'),
            'kit_name' => $schema->string()->description('Kit name / kit login; resolves to kit users on this page. Optional.'),
            'kit_id' => $schema->integer()->description('Exact kit ID when known. Optional.'),
            'flex_search' => $schema->boolean()->description('Toggle FlexSearch (fuzzy OCR plate matching). Optional.'),
            'invalid' => $schema->boolean()->description('true = show only invalid scans. Optional.'),
            'time_range' => $schema->string()->description($this->ottoTimeRangeSchemaDescription()),
            'start_date' => $schema->string()->description($this->ottoStartDateSchemaDescription()),
            'end_date' => $schema->string()->description($this->ottoEndDateSchemaDescription()),
            'clear' => $schema->array()
                ->items($schema->string()->enum(['plate', 'kits', 'invalid']))
                ->description('Filters to CLEAR. Optional.'),
        ];
    }
}
