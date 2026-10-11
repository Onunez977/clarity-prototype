<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Events\OttoResultDetailShown;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\OttoToolAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use Stringable;

class ShowResultDetailsTool implements RegisteredTool
{
    use ResolvesToolAvailability;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    /** Permission required to see detail cards of each result type. */
    private const PERMISSION_BY_TYPE = [
        'scan' => PermissionEnum::ViewAny_SCAN,
        'hit' => PermissionEnum::ViewAny_Hit,
        'hotlist' => PermissionEnum::ViewAny_Hotlist,
    ];

    /**
     * Registered when the user may see at least one of the three record types;
     * handle() then re-checks the specific type actually asked for, since this
     * one tool spans all three.
     */
    public function authorizedFor(User $user): bool
    {
        foreach (self::PERMISSION_BY_TYPE as $permission) {
            if (OttoToolAuth::userCan($user, $permission->value)) {
                return true;
            }
        }

        return false;
    }

    public function description(): Stringable|string
    {
        return 'Show detail cards for specific scan, hit, or hotlist assignment result IDs that already appear in this conversation\'s search results. Never invent IDs.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'result_ids' => $schema->array()->items($schema->integer())->description('Result IDs from prior search widgets.')->required(),
            'type' => $schema->string()->description('scan, hit, or hotlist')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $type = strtolower((string) ($request['type'] ?? 'scan'));
        $permission = self::PERMISSION_BY_TYPE[$type] ?? null;

        if ($permission === null) {
            return 'Type must be scan, hit, or hotlist.';
        }

        if (! OttoToolAuth::userCan($this->user, $permission->value)) {
            return 'This user is not permitted to view '.$type.' records. Tell them their account does not have access to that, and do not describe the records.';
        }

        $requested = collect($request['result_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $searchesById = collect($this->investigation->data?->searches ?? [])
            ->keyBy(fn ($search) => $search->searchId);
        $known = collect($this->investigation->data?->searchResults ?? [])
            ->filter(function ($result) use ($searchesById, $type): bool {
                $search = $searchesById->get($result->searchId);

                return $search !== null && $search->searchType->value === $type;
            })
            ->pluck('resultId')
            ->map(fn ($id) => (int) $id);
        $allowed = $requested->intersect($known)->values();

        if ($allowed->isEmpty()) {
            return 'None of those result IDs are in this conversation\'s search results. Run a search first, then show details for IDs from that search.';
        }

        $conversationId = $this->investigation->activeConversationId();
        $messageId = (string) Str::uuid();

        if ($conversationId) {
            foreach ($allowed as $resultId) {
                broadcast(new OttoResultDetailShown($conversationId, $messageId, $type, (int) $resultId));
            }
        }

        return 'Showing details for '.$allowed->count().' '.$type.' result(s). Do not repeat the record fields in prose.';
    }
}
