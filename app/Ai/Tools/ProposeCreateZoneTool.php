<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\PageAction;
use App\Ai\PageContext;
use App\Ai\Tools\Concerns\ProposesPageActions;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Models\Investigation;
use App\Models\User;
use App\Services\Zones\ResolveZoneCreateInput;
use App\Services\Zones\ResolveZoneUpdateInput;
use App\Support\Otto\OttoScoutAccess;
use App\Support\Otto\ZonePageActionChanges;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeCreateZoneTool implements RegisteredTool
{
    use ProposesPageActions;
    use ResolvesToolAvailability;

    public const PAGE_TYPE = 'zone-create';

    public const ACTION_CREATE = 'create-zone';

    public const ACTION_OPEN_EDITOR = 'open-zone-editor';

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    /**
     * Registered for every Otto user so the model can call this and relay the
     * Scout / admin soft message instead of claiming zones cannot be created.
     */
    public function authorizedFor(User $user): bool
    {
        return true;
    }

    public function availableOnPage(?PageContext $page): bool
    {
        return true;
    }

    public function description(): Stringable|string
    {
        return 'Propose creating a Scout patrol zone from ZIP codes, or opening the Zones map so the user can draw a polygon. Never invent GeoJSON coordinates. Never claim the zone was saved — the user must Accept the confirmation card first. Require a name. Pass zip_codes when they named ZIPs. Set open_map_editor true when they want to draw a shape. assigned_drivers are kit-user display names. Do not use this to edit an existing zone — call ProposeUpdateZone instead. If a zone with the same name already exists in the org, this tool proposes an update rather than a duplicate create.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Zone name. Required to propose.'),
            'description' => $schema->string()->description('Optional description.'),
            'color' => $schema->string()->description('Preset color hex or name (blue, teal, orange, purple, green). Ignored for no-scan zones.'),
            'is_no_scan_zone' => $schema->boolean()->description('true marks the area off-limits and stores gray.'),
            'zip_codes' => $schema->array()->items($schema->string())->description('US ZIP codes that define the boundary.'),
            'assigned_drivers' => $schema->array()->items($schema->string())->description('Kit-user display names to assign. Optional.'),
            'open_map_editor' => $schema->boolean()->description('true when the user wants to draw a polygon on the Zones map instead of saving from ZIPs.'),
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

        $openMapEditor = (bool) ($request['open_map_editor'] ?? false);
        $name = trim((string) ($request['name'] ?? ''));

        if ($name !== '' && ! $openMapEditor) {
            $existing = app(ResolveZoneUpdateInput::class)->findByName($this->user, $name);

            if ($existing !== null) {
                return $this->proposeUpdateForExisting($request, $existing->id);
            }
        }

        $resolved = app(ResolveZoneCreateInput::class)->resolve($this->user, [
            'name' => $request['name'] ?? null,
            'description' => $request['description'] ?? null,
            'color' => $request['color'] ?? null,
            'is_no_scan_zone' => $request['is_no_scan_zone'] ?? false,
            'zip_codes' => $request['zip_codes'] ?? [],
            'assigned_drivers' => $request['assigned_drivers'] ?? [],
            'open_map_editor' => $openMapEditor,
        ]);

        if ($resolved['ok'] === false) {
            return $resolved['error'];
        }

        $openEditor = $resolved['open_map_editor'] || $resolved['zip_codes'] === [];

        if ($openEditor && $resolved['zip_codes'] === [] && ! $openMapEditor) {
            return 'I need ZIP codes to create this zone, or I can open the Zones map so you can draw a shape. Which would you like?';
        }

        $action = new PageAction(
            pageType: self::PAGE_TYPE,
            name: $openEditor ? self::ACTION_OPEN_EDITOR : self::ACTION_CREATE,
            summary: $openEditor
                ? 'Open the Zones map to draw '.$resolved['name']
                : 'Create zone '.$resolved['name'],
            changes: ZonePageActionChanges::forResolved($resolved),
        );

        return $this->proposePageAction($action);
    }

    private function proposeUpdateForExisting(Request $request, int $zoneId): string
    {
        $raw = [
            'zone_id' => $zoneId,
            'name' => $request['name'] ?? null,
        ];

        foreach (['description', 'color', 'is_no_scan_zone', 'zip_codes', 'assigned_drivers'] as $key) {
            if ($request->offsetExists($key)) {
                $raw[$key] = $request[$key];
            }
        }

        $resolved = app(ResolveZoneUpdateInput::class)->resolve($this->user, $raw);

        if ($resolved['ok'] === false) {
            return $resolved['error'];
        }

        $action = new PageAction(
            pageType: ProposeUpdateZoneTool::PAGE_TYPE,
            name: ProposeUpdateZoneTool::ACTION_UPDATE,
            summary: 'Update zone '.$resolved['name'],
            changes: ZonePageActionChanges::forResolved($resolved, (int) $resolved['zone']->id),
        );

        return $this->proposePageAction($action);
    }
}
