<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * Shared validation for Otto zone-create proposals and Chat Accept.
 *
 * Unknown ZIPs and unresolvable assignee names abort rather than silently dropping.
 */
class ResolveZoneCreateInput
{
    public function __construct(private ZipCodeLookup $zips) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     ok: true,
     *     name: string,
     *     description: ?string,
     *     color: string,
     *     is_no_scan_zone: bool,
     *     zip_codes: list<string>,
     *     user_ids: list<int>,
     *     assigned_driver_names: list<string>,
     *     open_map_editor: bool
     * }|array{ok: false, error: string}
     */
    public function resolve(User $user, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return [
                'ok' => false,
                'error' => 'A zone name is required before I can propose creating it. What should this zone be called?',
            ];
        }

        $openEditor = (bool) ($input['open_map_editor'] ?? false);
        $requestedZips = $this->zips->normalize($input['zip_codes'] ?? []);
        $existingZips = $requestedZips === [] ? [] : $this->zips->existing($requestedZips);

        if ($requestedZips !== [] && count($existingZips) !== count($requestedZips) && ! $openEditor) {
            $unknown = array_values(array_diff($requestedZips, $existingZips));

            return [
                'ok' => false,
                'error' => 'I do not recognize ZIP '.implode(', ', $unknown)
                    .'. I will not create a zone with missing geography. Check those ZIPs or I can open the Zones map so you can draw a shape instead.',
            ];
        }

        $driverNames = $this->stringList($input['assigned_drivers'] ?? []);
        $userIds = $this->intList($input['assigned_user_ids'] ?? $input['users'] ?? []);
        $assignedNames = [];

        if ($driverNames !== []) {
            $resolved = $this->resolveDriverNames($user, $driverNames);

            if ($resolved['error'] !== null) {
                return ['ok' => false, 'error' => $resolved['error']];
            }

            $userIds = $resolved['ids'];
            $assignedNames = $resolved['names'];
        } elseif ($userIds !== []) {
            $validated = $this->validateUserIds($user, $userIds);

            if ($validated['error'] !== null) {
                return ['ok' => false, 'error' => $validated['error']];
            }

            $userIds = $validated['ids'];
            $assignedNames = $validated['names'];
        }

        $isNoScan = (bool) ($input['is_no_scan_zone'] ?? false);

        return [
            'ok' => true,
            'name' => Str::limit($name, 255, ''),
            'description' => $this->nullableString($input['description'] ?? null, 1000),
            'color' => $isNoScan
                ? Zone::NO_SCAN_COLOR
                : Zone::colorFromHint(isset($input['color']) ? (string) $input['color'] : null),
            'is_no_scan_zone' => $isNoScan,
            'zip_codes' => $existingZips,
            'user_ids' => $userIds,
            'assigned_driver_names' => $assignedNames,
            'open_map_editor' => $openEditor,
        ];
    }

    /**
     * @param  list<string>  $names
     * @return array{ids: list<int>, names: list<string>, error: ?string}
     */
    private function resolveDriverNames(User $user, array $names): array
    {
        $kitUsers = User::query()
            ->where('org_id', $user->org_id)
            ->whereHas('kitSettings')
            ->orderBy('name')
            ->get(['id', 'name']);

        $ids = [];
        $resolvedNames = [];

        foreach ($names as $name) {
            $needle = strtolower($name);
            $exact = $kitUsers->first(
                fn (User $candidate): bool => strtolower((string) $candidate->name) === $needle,
            );

            if ($exact !== null) {
                $ids[] = (int) $exact->id;
                $resolvedNames[] = (string) $exact->name;

                continue;
            }

            $partial = $kitUsers->filter(
                fn (User $candidate): bool => str_contains(strtolower((string) $candidate->name), $needle),
            );

            if ($partial->count() !== 1) {
                return [
                    'ids' => [],
                    'names' => [],
                    'error' => "I could not uniquely match a kit user named {$name}. I will not create the zone without that assignment.",
                ];
            }

            /** @var User $match */
            $match = $partial->first();
            $ids[] = (int) $match->id;
            $resolvedNames[] = (string) $match->name;
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'names' => array_values(array_unique($resolvedNames)),
            'error' => null,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array{ids: list<int>, names: list<string>, error: ?string}
     */
    private function validateUserIds(User $user, array $userIds): array
    {
        $valid = User::query()
            ->where('org_id', $user->org_id)
            ->whereHas('kitSettings')
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($valid->count() !== count(array_unique($userIds))) {
            return [
                'ids' => [],
                'names' => [],
                'error' => 'One or more assigned drivers do not have a kit in this organization. I will not create the zone without that assignment.',
            ];
        }

        return [
            'ids' => $valid->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'names' => $valid->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            'error' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $trimmed = trim((string) $item);

            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            $id = (int) $item;

            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return Str::limit($trimmed, $max, '');
    }
}
