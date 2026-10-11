<?php

namespace App\Ai\Tools;

use App\Ai\PageContext;
use App\Ai\Tools\Concerns\BuildsPageSearchFilters;
use App\Ai\Tools\Concerns\ProposesPageActions;
use App\Ai\Tools\Concerns\ResolvesOttoDateRange;
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
 * Page-specific tool: when the user is on the Hotlist assignments list, Otto
 * proposes applying Filament table filters (propose-then-confirm). This page
 * has no date-range toggles.
 */
class ApplyHotlistSearchFiltersTool implements RegisteredTool
{
    use BuildsPageSearchFilters;
    use ProposesPageActions;
    use ResolvesOttoDateRange;
    use ResolvesToolAvailability;

    public const PAGE_TYPE = 'hotlist-search';

    public const ACTION = 'apply-hotlist-filters';

    /**
     * @var array<string, array{field: string, label: string, empty: mixed}>
     */
    private const CLEARABLE = [
        'plate' => ['field' => 'plate', 'label' => 'Plate', 'empty' => ''],
        'vin' => ['field' => 'vin', 'label' => 'VIN', 'empty' => ''],
        'order_id' => ['field' => 'order_id', 'label' => 'Order ID', 'empty' => ''],
        'clients' => ['field' => 'clients', 'label' => 'Client(s)', 'empty' => []],
        'missing_plate' => ['field' => 'missing_plate', 'label' => 'Missing plate', 'empty' => false],
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
        return OttoToolAuth::userCan($user, PermissionEnum::ViewAny_Hotlist->value);
    }

    public function description(): Stringable|string
    {
        return 'THE PRIMARY way to search hotlist assignments while the user is on the Hotlist page. Assignments are wanted vehicles (the book of work), not hit/match events. Set up the page\'s filters for them. Use this for ANY assignment-search request on this page — refining what they already see OR starting a brand-new lookup ("open assignments for ABC123", "VIN 1HGCM", "client Acme"). Prefer this over running your own chat search. Call this tool IMMEDIATELY when the request maps onto page filters. In ONE call you can: set plate, VIN, order ID, status, client, FlexSearch, and missing-plate. CLEAR plate, vin, order_id, clients, or missing_plate. This page has no date-range filter — do not invent one. This page searches ASSIGNMENTS only — if they ask for hits/alerts or scans, do not use this tool.';
    }

    public function handle(Request $request): Stringable|string
    {
        $changes = array_filter([
            'plate' => $this->plateChange($request),
            'vin' => $this->vinChange($request),
            'order_id' => $this->orderIdChange($request),
            'status' => $this->statusChange($request),
            'clients' => $this->clientChange($request),
            'flex_search' => $this->flexSearchChange($request),
            'missing_plate' => $this->booleanChange($request, 'missing_plate', 'missing_plate', 'Missing plate'),
        ]);

        $changes = array_merge($changes, $this->clearChanges($request, self::CLEARABLE));

        return $this->proposeFilterChanges(
            $changes,
            self::PAGE_TYPE,
            self::ACTION,
            'hotlist',
            'Provide at least one change to make (set or clear plate, VIN, order ID, or clients; set status; toggle FlexSearch or missing plate).',
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
            'client' => $schema->string()->description('Client name or client org id. Optional.'),
            'flex_search' => $schema->boolean()->description('Toggle FlexSearch. Optional.'),
            'missing_plate' => $schema->boolean()->description('true = only assignments missing a plate. Optional.'),
            'clear' => $schema->array()
                ->items($schema->string()->enum(['plate', 'vin', 'order_id', 'clients', 'missing_plate']))
                ->description('Filters to CLEAR. Optional.'),
        ];
    }
}
