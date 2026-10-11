<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Models\Kit;
use App\Models\User;
use App\Services\Scout\ResolveOrgDriverPositions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Ai\Tools\Request;

trait ResolvesOttoKitFilter
{
    /**
     * Resolve org-scoped kit IDs from optional driver/kit hints on the request.
     *
     * @return array{kit_ids: list<int>, label: ?string, error: ?string}
     */
    protected function resolveOttoKitFilter(User $user, Request $request): array
    {
        $driverName = strtolower(trim((string) ($request['driver_name'] ?? '')));
        $kitName = strtolower(trim((string) ($request['kit_name'] ?? '')));
        $kitId = isset($request['kit_id']) ? (int) $request['kit_id'] : null;
        $hasKitId = $kitId !== null && $kitId > 0;
        $hasIdentityHint = $driverName !== '' || $kitName !== '' || $hasKitId;

        if (! $hasIdentityHint) {
            return ['kit_ids' => [], 'label' => null, 'error' => null];
        }

        $orgId = (int) $user->org_id;
        $resolver = app(ResolveOrgDriverPositions::class);
        $kits = $hasKitId
            ? $resolver->forOrg($orgId, [$kitId])
            : $resolver->forOrg($orgId);

        if ($kits->isEmpty()) {
            return [
                'kit_ids' => [],
                'label' => null,
                'error' => $hasKitId
                    ? 'No kit in this organization matched that ID.'
                    : 'No kits in this organization matched that driver or kit name.',
            ];
        }

        $matched = ($driverName !== '' || $kitName !== '')
            ? $this->matchOttoKits($kits, $driverName, $kitName)
            : $kits;

        if ($matched->isEmpty()) {
            return [
                'kit_ids' => [],
                'label' => null,
                'error' => 'No kits in this organization matched that driver or kit name.',
            ];
        }

        return [
            'kit_ids' => $matched->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'label' => $this->ottoKitFilterLabel($matched->first()),
            'error' => null,
        ];
    }

    /**
     * Match org kits by driver and/or kit name hints.
     *
     * The model is told to pass both fields when unsure which the token is, so
     * the same needle in both must match either side (OR), not both (AND).
     * Distinct needles still mean "this person on that kit" (AND), with an OR
     * fallback when that intersection is empty. A lone driver_name that misses
     * as a person is retried as a kit login. Among hits, exact / full-token
     * matches outrank bare substrings so "Harold Oran" wins over a looser
     * "Harold…" hit when the query is the full name.
     *
     * @param  EloquentCollection<int, Kit>  $kits
     * @return EloquentCollection<int, Kit>
     */
    protected function matchOttoKits(EloquentCollection $kits, string $driverName, string $kitName): EloquentCollection
    {
        if ($driverName === '' && $kitName === '') {
            return $kits->values();
        }

        if ($driverName !== '' && $kitName !== '') {
            $matched = $driverName === $kitName
                ? $this->filterOttoKitsEither($kits, $driverName)
                : $this->filterOttoKits($kits, $driverName, $kitName);

            if ($matched->isEmpty() && $driverName !== $kitName) {
                $matched = $this->filterOttoKitsEither($kits, $driverName, $kitName);
            }

            return $this->preferBestOttoNameMatches($matched, $driverName, $kitName);
        }

        if ($driverName !== '') {
            $matched = $this->filterOttoKits($kits, $driverName, '');

            if ($matched->isEmpty()) {
                $matched = $this->filterOttoKits($kits, '', $driverName);
            }

            return $this->preferBestOttoNameMatches($matched, $driverName, '');
        }

        $matched = $this->filterOttoKits($kits, '', $kitName);

        return $this->preferBestOttoNameMatches($matched, '', $kitName);
    }

    /**
     * @param  EloquentCollection<int, Kit>  $kits
     * @return EloquentCollection<int, Kit>
     */
    protected function filterOttoKits(EloquentCollection $kits, string $driverName, string $kitName): EloquentCollection
    {
        return $kits
            ->filter(function (Kit $kit) use ($driverName, $kitName): bool {
                if ($kitName !== '' && $this->ottoNameMatchScore($this->ottoKitName($kit), $kitName) === 0) {
                    return false;
                }

                if ($driverName !== '' && $this->ottoNameMatchScore($this->ottoDriverName($kit), $driverName) === 0) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * Match kits where the driver name hits any needle or the kit name hits any needle.
     *
     * @param  EloquentCollection<int, Kit>  $kits
     * @return EloquentCollection<int, Kit>
     */
    protected function filterOttoKitsEither(EloquentCollection $kits, string $needle, ?string $secondNeedle = null): EloquentCollection
    {
        $needles = array_values(array_unique(array_filter(
            [$needle, $secondNeedle ?? ''],
            static fn (string $value): bool => $value !== '',
        )));

        return $kits
            ->filter(function (Kit $kit) use ($needles): bool {
                $driver = $this->ottoDriverName($kit);
                $kitLabel = $this->ottoKitName($kit);

                foreach ($needles as $candidate) {
                    if ($this->ottoNameMatchScore($driver, $candidate) > 0
                        || $this->ottoNameMatchScore($kitLabel, $candidate) > 0) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * Keep only the best name-match tier so an exact / full-name hit wins over
     * looser substring hits when both are in the candidate set.
     *
     * @param  EloquentCollection<int, Kit>  $kits
     * @return EloquentCollection<int, Kit>
     */
    protected function preferBestOttoNameMatches(EloquentCollection $kits, string $driverName, string $kitName): EloquentCollection
    {
        if ($kits->count() <= 1) {
            return $kits->values();
        }

        $scored = $kits->map(function (Kit $kit) use ($driverName, $kitName): array {
            $driverScore = $driverName !== ''
                ? $this->ottoNameMatchScore($this->ottoDriverName($kit), $driverName)
                : 0;
            $kitScore = $kitName !== ''
                ? $this->ottoNameMatchScore($this->ottoKitName($kit), $kitName)
                : 0;

            return [
                'kit' => $kit,
                'score' => max($driverScore, $kitScore),
            ];
        });

        $best = (int) $scored->max('score');

        if ($best <= 0) {
            return $kits->values();
        }

        // map()/filter() on scored rows yields a Support Collection; re-wrap so
        // callers keep the declared EloquentCollection<int, Kit> return type.
        return new EloquentCollection(
            $scored
                ->filter(static fn (array $row): bool => $row['score'] === $best)
                ->map(static fn (array $row): Kit => $row['kit'])
                ->values()
                ->all()
        );
    }

    /**
     * 3 = exact full string, 2 = whole token (first/last name), 1 = substring, 0 = miss.
     */
    protected function ottoNameMatchScore(string $haystack, string $needle): int
    {
        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if ($haystack === $needle) {
            return 3;
        }

        foreach (preg_split('/\s+/u', $haystack) ?: [] as $token) {
            if ($token === $needle) {
                return 2;
            }
        }

        return str_contains($haystack, $needle) ? 1 : 0;
    }

    protected function ottoDriverName(Kit $kit): string
    {
        return strtolower(trim((string) ($kit->settings?->user?->name ?? '')));
    }

    protected function ottoKitName(Kit $kit): string
    {
        return strtolower(trim((string) ($kit->name ?? '')));
    }

    protected function ottoKitFilterLabel(Kit $kit): string
    {
        $assigned = trim((string) ($kit->settings?->user?->name ?? ''));
        $name = trim((string) $kit->name);

        if ($assigned !== '' && $name !== '') {
            return "{$assigned} ({$name})";
        }

        if ($assigned !== '') {
            return $assigned;
        }

        return $name !== '' ? $name : 'Kit '.$kit->id;
    }
}
