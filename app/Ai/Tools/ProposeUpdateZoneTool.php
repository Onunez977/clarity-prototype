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
use App\Services\Zones\ResolveZoneUpdateInput;
use App\Support\Otto\OttoScoutAccess;
use App\Support\Otto\ZonePageActionChanges;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeUpdateZoneTool implements RegisteredTool
{
    use ProposesPageActions;
    use ResolvesToolAvailability;

    public const PAGE_TYPE = 'zone-update';

    public const ACTION_UPDATE = 'update-zone';

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    /**
     * Registered for every Otto user so the model can call this and relay the
     * Scout / admin soft message instead of claiming zones cannot be updated.
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
        return 'Propose updating an existing Scout patrol zone (no-scan flag, ZIPs, color, description, or driver assignments). Use when the user changes a zone that already exists or was just created/proposed — e.g. "make it a no-scan zone", "add ZIP …", or "rename" is not supported here (keep the same name). Pass zone_id when known from a prior search or create; otherwise pass the exact name. Never claim the zone was saved — the user must Accept the confirmation card first. Do not use this to create a new zone.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'zone_id' => $schema->integer()->description('Existing zone id when known from a prior search or create in this conversation.'),
            'name' => $schema->string()->description('Exact zone name when zone_id is unknown. Used to find the zone (case-insensitive).'),
            'description' => $schema->string()->description('Optional new description. Omit to keep the current value.'),
            'color' => $schema->string()->description('Preset color hex or name. Ignored for no-scan zones. Omit to keep the current value.'),
            'is_no_scan_zone' => $schema->boolean()->description('true marks the area off-limits and stores gray. Omit to keep the current value.'),
            'zip_codes' => $schema->array()->items($schema->string())->description('Replacement US ZIP codes. Omit or pass empty to keep the current boundary.'),
            'assigned_drivers' => $schema->array()->items($schema->string())->description('Replacement kit-user display names. Omit to keep current assignments.'),
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

        $raw = [
            'zone_id' => $request['zone_id'] ?? null,
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
            pageType: self::PAGE_TYPE,
            name: self::ACTION_UPDATE,
            summary: 'Update zone '.$resolved['name'],
            changes: ZonePageActionChanges::forResolved($resolved, (int) $resolved['zone']->id),
        );

        return $this->proposePageAction($action);
    }
}
