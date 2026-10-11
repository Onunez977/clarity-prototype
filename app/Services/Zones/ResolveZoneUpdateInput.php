<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Models\User;
use App\Models\Zone;

/**
 * Resolve a partial Otto zone-update against an existing org-scoped zone.
 *
 * Omitted fields keep the zone's current values. Empty zip_codes keep the
 * existing ZIP list (and polygons). Unknown ZIPs and unresolvable assignees
 * abort rather than silently dropping.
 */
class ResolveZoneUpdateInput
{
    public function __construct(private ResolveZoneCreateInput $createInput) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     ok: true,
     *     zone: Zone,
     *     name: string,
     *     description: ?string,
     *     color: string,
     *     is_no_scan_zone: bool,
     *     zip_codes: list<string>,
     *     polygons: list<mixed>,
     *     user_ids: list<int>,
     *     assigned_driver_names: list<string>
     * }|array{ok: false, error: string}
     */
    public function resolve(User $user, array $input): array
    {
        $zone = $this->findZone($user, $input);

        if ($zone === null) {
            return [
                'ok' => false,
                'error' => 'I could not find that zone in this organization. Search for it first, or tell me the exact zone name.',
            ];
        }

        $definition = is_array($zone->definition) ? $zone->definition : [];
        $existingZips = array_values(array_map(
            static fn (mixed $zip): string => (string) $zip,
            $definition['zip_codes'] ?? [],
        ));
        $existingPolygons = array_values($definition['polygons'] ?? []);
        $existingUserIds = $zone->users()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();

        $zipCodes = $existingZips;
        if (array_key_exists('zip_codes', $input) && is_array($input['zip_codes']) && $input['zip_codes'] !== []) {
            $zipCodes = $input['zip_codes'];
        }

        $merged = [
            'name' => $zone->name,
            'description' => array_key_exists('description', $input)
                ? $input['description']
                : $zone->description,
            'color' => array_key_exists('color', $input) ? $input['color'] : $zone->color,
            'is_no_scan_zone' => array_key_exists('is_no_scan_zone', $input)
                ? (bool) $input['is_no_scan_zone']
                : (bool) $zone->is_no_scan_zone,
            'zip_codes' => $zipCodes,
            'open_map_editor' => false,
        ];

        if (array_key_exists('assigned_drivers', $input) || array_key_exists('assigned_user_ids', $input) || array_key_exists('users', $input)) {
            $merged['assigned_drivers'] = $input['assigned_drivers'] ?? [];
            $merged['assigned_user_ids'] = $input['assigned_user_ids'] ?? $input['users'] ?? [];
        } else {
            $merged['assigned_user_ids'] = $existingUserIds;
        }

        $resolved = $this->createInput->resolve($user, $merged);

        if ($resolved['ok'] === false) {
            return $resolved;
        }

        return [
            'ok' => true,
            'zone' => $zone,
            'name' => $resolved['name'],
            'description' => $resolved['description'],
            'color' => $resolved['color'],
            'is_no_scan_zone' => $resolved['is_no_scan_zone'],
            'zip_codes' => $resolved['zip_codes'],
            'polygons' => $existingPolygons,
            'user_ids' => $resolved['user_ids'],
            'assigned_driver_names' => $resolved['assigned_driver_names'],
        ];
    }

    /**
     * Exact case-insensitive name match within the org. Most recent wins when
     * duplicates already exist (the bug this path prevents creating more of).
     */
    public function findByName(User $user, string $name): ?Zone
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return null;
        }

        return Zone::query()
            ->forOrg((int) $user->org_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($trimmed)])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function findZone(User $user, array $input): ?Zone
    {
        $zoneId = (int) ($input['zone_id'] ?? 0);

        if ($zoneId > 0) {
            return Zone::query()
                ->forOrg((int) $user->org_id)
                ->whereKey($zoneId)
                ->first();
        }

        return $this->findByName($user, (string) ($input['name'] ?? ''));
    }
}
