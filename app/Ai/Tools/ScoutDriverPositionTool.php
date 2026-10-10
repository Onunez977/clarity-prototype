<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ResolvesOttoKitFilter;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Datatype\SearchType;
use App\Events\OttoWidgetCreated;
use App\Http\Middleware\ClarityScoutAdminMiddleware;
use App\Models\Investigation;
use App\Models\Kit;
use App\Models\User;
use App\Services\Scout\ResolveOrgDriverPositions;
use App\Support\Geocoding\ReverseGeocoder;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\OttoScoutAccess;
use App\Support\Otto\SearchActivityLog;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Org-scoped Scout driver position lookup for Otto (DEV-2321).
 *
 * Soft-gates Scout + admin the same way Bird's Eye does
 * ({@see ClarityScoutAdminMiddleware}); returns a
 * customer-friendly message when Scout/Pro is unavailable rather than hard-failing.
 *
 * Driver/kit identity uses {@see ResolvesOttoKitFilter::matchOttoKits()} — partial
 * first/last names, OR when the model duplicates one uncertain token into both
 * fields, and prefer-exact ranking when several candidates hit.
 */
class ScoutDriverPositionTool implements RegisteredTool
{
    use ResolvesOttoKitFilter;
    use ResolvesToolAvailability;

    private const FLEET_SUMMARY_LIMIT = LimitedToolResultMessage::FLEET_DRIVER_SUMMARY_LIMIT;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
    ) {}

    /**
     * Registered for every Otto user on purpose: the Scout + ADMIN gate lives in
     * handle() via userCanLookupDriverPositions(), which returns a customer-
     * friendly upsell rather than hiding the capability. Withholding the tool
     * here instead would make Otto claim it cannot look up drivers at all.
     */
    public function authorizedFor(User $user): bool
    {
        return true;
    }

    public function description(): Stringable|string
    {
        return 'Look up where an organization Scout driver or kit was last seen. Use when the user asks where a driver is, a kit\'s location, or who has a recent Scout position. driver_name is the assigned person (e.g. Admin, Harold), not the kit login — first name, last name, or a distinctive partial of the full name is enough. kit_name / kit_id are the kit (e.g. gsmitham, kit 2). If the user names something that might be either, pass both driver_name and kit_name with the same token; the tool matches either side. Without a name, summarize who has a recent position (do not dump the full fleet).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'driver_name' => $schema->string()->description('Assigned driver display name (the person). Case-insensitive; first name, last name, or distinctive substring OK (e.g. harold → Harold Oran). Do not put kit logins here.'),
            'kit_name' => $schema->string()->description('Kit name / kit login (not the driver person), case-insensitive; partial OK.'),
            'kit_id' => $schema->integer()->description('Exact kit ID when known.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->investigation->toolCallLimitReached()) {
            return 'Tool call limit reached for this message. Summarize what you have.';
        }

        if (! $this->userCanLookupDriverPositions()) {
            return 'Driver location lookups need Clarity Scout and an organization admin account. If your organization does not have Scout yet, ask your Clarity account manager about enabling it.';
        }

        $this->investigation->incrementToolCalls();

        $orgId = (int) $this->user->org_id;
        $resolver = app(ResolveOrgDriverPositions::class);

        $driverName = strtolower(trim((string) ($request['driver_name'] ?? '')));
        $kitName = strtolower(trim((string) ($request['kit_name'] ?? '')));
        $kitId = isset($request['kit_id']) ? (int) $request['kit_id'] : null;
        $hasKitId = $kitId !== null && $kitId > 0;

        SearchActivityLog::record(SearchActivityLog::SCOUT_DRIVER_POSITIONS, $this->user, [
            'driverName' => $driverName !== '' ? $driverName : null,
            'kitName' => $kitName !== '' ? $kitName : null,
            'kitId' => $hasKitId ? $kitId : null,
        ], $this->investigation->activeConversationId());

        $kits = $hasKitId
            ? $resolver->forOrg($orgId, [$kitId])
            : $resolver->forOrg($orgId);

        if ($kits->isEmpty() && ! $hasKitId) {
            return 'No Scout kits for this organization yet.';
        }

        $hasIdentityHint = $driverName !== '' || $kitName !== '' || $hasKitId;

        $matched = ($driverName !== '' || $kitName !== '')
            ? $this->matchOttoKits($kits, $driverName, $kitName)
            : $kits;

        if ($hasIdentityHint && $matched->isEmpty()) {
            return 'No drivers or kits in this organization matched that name. Ask for a different driver or kit name.';
        }

        $positions = $this->withUsableFix($resolver->positions($orgId, $matched));
        $totalRecent = count($positions);

        // The recency cap applies on the identity path too: snapshotPositions
        // makes one blocking reverse-geocode round-trip per position, and a
        // partial driver_name ("a") matches every kit whose driver contains it —
        // uncapped that can outlast this job's 180s timeout on tries = 1.
        if ($positions !== []) {
            usort($positions, function (array $a, array $b): int {
                return $this->positionRecencyKey($b) <=> $this->positionRecencyKey($a);
            });
            $positions = array_slice($positions, 0, self::FLEET_SUMMARY_LIMIT);
        }

        $snapshots = $this->snapshotPositions($positions);

        if ($snapshots !== []) {
            $this->recordSearch($snapshots, $hasIdentityHint);
        }

        if (! $hasIdentityHint) {
            return $this->summarizeFleet($snapshots, $totalRecent);
        }

        $byKitId = collect($snapshots)->keyBy(static fn (array $row): int => (int) $row['kit_id']);

        // Kits with a position first, so a broad name match spends the line
        // budget on answers rather than on "no recent location" for every kit.
        $shown = $matched
            ->sortByDesc(fn (Kit $kit): int => $byKitId->has((int) $kit->id) ? 1 : 0)
            ->take(self::FLEET_SUMMARY_LIMIT);

        $lines = [];

        foreach ($shown as $kit) {
            $position = $byKitId->get((int) $kit->id);
            $lines[] = $position === null
                ? $this->formatMissing($kit)
                : $this->formatSnapshot($kit, $position);
        }

        $unshown = $matched->count() - $shown->count();
        if ($unshown > 0) {
            $lines[] = "…and {$unshown} more ".Str::plural('kit', $unshown).' matched that name. Ask for a more specific driver or kit name.';
        } elseif ($matched->count() > 1) {
            $distinctDrivers = $matched
                ->map(fn (Kit $kit): string => trim((string) ($kit->settings?->user?->name ?? '')))
                ->filter(static fn (string $name): bool => $name !== '')
                ->unique(static fn (string $name): string => strtolower($name))
                ->values();

            if ($distinctDrivers->count() > 1) {
                array_unshift(
                    $lines,
                    'Several drivers matched that name — ask which one: '.$distinctDrivers->implode(', ').'.',
                );
            }
        }

        return implode("\n", $lines);
    }

    private function userCanLookupDriverPositions(): bool
    {
        return OttoScoutAccess::adminCanUseScout($this->user);
    }

    /**
     * Persist absolute coords + `last_seen_at_utc` (+ place). Do not store
     * `minutes_ago` — that relative integer is computed in-memory for this
     * turn's LLM reply and again at hydration from `last_seen_at_utc`.
     *
     * Bird's Eye JSON still uses `scanned_at`; Otto snapshots map that ISO string
     * onto `last_seen_at_utc` and do not persist `scanned_at`.
     *
     * @param  list<array{kit_id: int, name: string|null, driver_name: string|null, lat: float, lng: float, minutes_ago?: int, scanned_at?: string|null, last_seen_at_utc?: string|null, position_source?: string}>  $positions
     * @return list<array{kit_id: int, name: string|null, driver_name: string|null, lat: float, lng: float, last_seen_at_utc: ?string, place: ?string}>
     */
    private function snapshotPositions(array $positions): array
    {
        $geocoder = app(ReverseGeocoder::class);
        $snapshots = [];

        foreach ($positions as $position) {
            $lat = (float) $position['lat'];
            $lng = (float) $position['lng'];

            $snapshots[] = [
                'kit_id' => (int) $position['kit_id'],
                'name' => $position['name'] ?? null,
                'driver_name' => $position['driver_name'] ?? null,
                'lat' => $lat,
                'lng' => $lng,
                'last_seen_at_utc' => $this->lastSeenAtUtc($position),
                'place' => $geocoder->label($lat, $lng),
            ];
        }

        return $snapshots;
    }

    /**
     * Drop positions with no usable fix (a kit reporting 0.0,0.0). Filtering here
     * rather than inside snapshotPositions keeps the fleet overflow note honest:
     * counting discarded rows made Otto claim N more drivers had a recent
     * position and invite follow-ups that could only answer "no recent location".
     *
     * @param  list<array<string, mixed>>  $positions
     * @return list<array<string, mixed>>
     */
    private function withUsableFix(array $positions): array
    {
        return array_values(array_filter(
            $positions,
            static fn (array $position): bool => (float) ($position['lat'] ?? 0) !== 0.0
                || (float) ($position['lng'] ?? 0) !== 0.0,
        ));
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function lastSeenAtUtc(array $position): ?string
    {
        foreach (['last_seen_at_utc', 'scanned_at'] as $key) {
            if (! isset($position[$key]) || $position[$key] === null || $position[$key] === '') {
                continue;
            }

            return (string) $position[$key];
        }

        return null;
    }

    /**
     * @param  list<array{kit_id: int, name: string|null, driver_name: string|null, lat: float, lng: float, last_seen_at_utc: ?string, place: ?string}>  $snapshots
     */
    private function recordSearch(array $snapshots, bool $hasIdentityHint): void
    {
        $searchId = Str::lower(Str::random(12));
        $title = $hasIdentityHint ? 'Scout driver location' : 'Scout driver locations';
        $props = $this->investigation->data ?? new AgentInvestigationProperties;
        $searches = $props->searches;
        $searches[] = new Search(
            searchId: $searchId,
            messageId: '',
            parameters: ['positions' => $snapshots],
            searchType: SearchType::ScoutDriver,
            title: $title,
            createdBy: $this->user->id,
            conversationId: (string) $this->investigation->activeConversationId(),
        );

        $this->investigation->data = new AgentInvestigationProperties(
            searches: $searches,
            searchResults: $props->searchResults,
        );

        $conversationId = $this->investigation->activeConversationId();
        if ($conversationId) {
            broadcast(new OttoWidgetCreated(
                (string) $conversationId,
                (string) Str::uuid(),
                SearchType::ScoutDriver->value,
                $searchId,
                $title,
            ));
        }
    }

    /**
     * @param  list<array{kit_id: int, name: string|null, driver_name: string|null, lat: float, lng: float, last_seen_at_utc: ?string, place: ?string}>  $snapshots
     */
    private function summarizeFleet(array $snapshots, int $totalRecent): string
    {
        if ($snapshots === []) {
            return 'No Scout drivers have a recent location (within the last 24 hours). Ask for a specific driver or kit name to check further.';
        }

        $lines = [LimitedToolResultMessage::fleetDriverSummaryIntro(count($snapshots), self::FLEET_SUMMARY_LIMIT)];

        foreach ($snapshots as $position) {
            $label = trim((string) ($position['driver_name'] ?: $position['name'] ?: 'Kit '.$position['kit_id']));
            $when = $this->humanizeMinutesAgo($this->minutesAgoForReply($position['last_seen_at_utc'] ?? null));
            $place = $position['place'];

            if (is_string($place) && $place !== '') {
                $lines[] = "- {$label}: near {$place} — updated {$when}.";
            } else {
                $lines[] = "- {$label}: last location update {$when}; a street/city name was not available.";
            }
        }

        $extra = $totalRecent - count($snapshots);
        $overflow = LimitedToolResultMessage::fleetDriverSummaryOverflow($extra, self::FLEET_SUMMARY_LIMIT);
        if ($overflow !== '') {
            $lines[] = $overflow;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function positionRecencyKey(array $position): string
    {
        foreach (['scanned_at', 'last_seen_at_utc'] as $key) {
            if (! isset($position[$key]) || $position[$key] === null || $position[$key] === '') {
                continue;
            }

            return (string) $position[$key];
        }

        return '';
    }

    /**
     * @param  array{kit_id: int, name: string|null, driver_name: string|null, lat: float, lng: float, last_seen_at_utc: ?string, place: ?string}  $position
     */
    private function formatSnapshot(Kit $kit, array $position): string
    {
        $who = $this->labelFor($kit, $position['driver_name'] ?? null);
        $when = $this->humanizeMinutesAgo($this->minutesAgoForReply($position['last_seen_at_utc'] ?? null));
        $place = $position['place'] ?? null;

        if (is_string($place) && $place !== '') {
            return "{$who} was last seen near {$place} — updated {$when}.";
        }

        return "{$who} has a recent location update from {$when}, but a street/city name could not be determined. Do not invent an address.";
    }

    /**
     * Relative age for this turn's assistant text only — never persisted.
     */
    private function minutesAgoForReply(?string $lastSeenAtUtc): int
    {
        if ($lastSeenAtUtc === null || $lastSeenAtUtc === '') {
            return 0;
        }

        try {
            return (int) abs(now()->diffInMinutes(Carbon::parse($lastSeenAtUtc)));
        } catch (InvalidFormatException) {
            return 0;
        }
    }

    private function formatMissing(Kit $kit): string
    {
        return $this->labelFor($kit).' has no recent Scout location (nothing in the last 24 hours).';
    }

    private function labelFor(Kit $kit, ?string $driverName = null): string
    {
        $driver = $driverName ?? $kit->settings?->user?->name;
        $kitLabel = $kit->name ?: ('kit #'.$kit->id);

        if (is_string($driver) && trim($driver) !== '') {
            return trim($driver).' ('.$kitLabel.')';
        }

        return $kitLabel;
    }

    private function humanizeMinutesAgo(int $minutesAgo): string
    {
        if ($minutesAgo <= 1) {
            return 'about a minute ago';
        }

        if ($minutesAgo < 60) {
            return "{$minutesAgo} minutes ago";
        }

        $hours = (int) floor($minutesAgo / 60);

        if ($hours === 1) {
            return 'about 1 hour ago';
        }

        return "about {$hours} hours ago";
    }
}
