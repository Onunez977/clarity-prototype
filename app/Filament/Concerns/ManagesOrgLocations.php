<?php

namespace App\Filament\Concerns;

use App\Enums\OrgLocationType;
use App\Models\OrgCoverageZone;
use App\Models\OrgLocation;
use App\Support\Prototype\LocationCoverageMap;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rule;
use InsightLPR\BlackAdam\Enum\RoleEnum;

trait ManagesOrgLocations
{
    public bool $showLocationForm = false;

    public ?int $editingLocationId = null;

    /**
     * @var array<string, mixed>
     */
    public array $locationForm = [];

    public string $coverageScope = 'company';

    public string $coverageMode = 'radius';

    public ?int $coverageCenterLocationId = null;

    public string $coverageRadiusMiles = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $coveragePolygon = null;

    public function loadLocationEditor(): void
    {
        $this->resetLocationForm();
        $this->fillCoverageForm();
    }

    /**
     * @return array<string, mixed>
     */
    public function locationMap(): array
    {
        return LocationCoverageMap::present($this->locationOrgId());
    }

    public function startCreate(): void
    {
        $this->authorizeLocationAdmin();
        $this->resetLocationForm();
        $this->editingLocationId = null;
        $this->showLocationForm = true;
    }

    public function startEdit(int $locationId): void
    {
        $this->authorizeLocationAdmin();
        $location = $this->findLocation($locationId);
        $this->editingLocationId = $location->id;
        $this->locationForm = [
            'name' => $location->name,
            'type' => $location->type,
            'address' => $location->address,
            'phone' => $location->phone ?? '',
            'notes' => $location->notes ?? '',
            'latitude' => $location->latitude === null ? '' : (string) $location->latitude,
            'longitude' => $location->longitude === null ? '' : (string) $location->longitude,
            'coverage_mode' => $location->coverage_mode ?? '',
            'coverage_radius_miles' => $location->coverage_radius_miles === null ? '' : (string) $location->coverage_radius_miles,
            'coverage_polygon' => $location->coverage_polygon,
        ];
        $this->showLocationForm = true;
        $this->resetErrorBag();
    }

    public function cancelLocation(): void
    {
        $this->showLocationForm = false;
        $this->editingLocationId = null;
        $this->resetLocationForm();
        $this->resetErrorBag();
    }

    public function saveLocation(): void
    {
        $this->authorizeLocationAdmin();

        foreach (['phone', 'notes', 'latitude', 'longitude', 'coverage_radius_miles'] as $field) {
            $this->locationForm[$field] = $this->blankToNull($this->locationForm[$field] ?? null);
        }

        $this->validate([
            'locationForm.name' => ['required', 'string', 'max:120'],
            'locationForm.type' => ['required', Rule::enum(OrgLocationType::class)],
            'locationForm.address' => ['required', 'string', 'max:500'],
            'locationForm.phone' => ['nullable', 'string', 'max:40'],
            'locationForm.notes' => ['nullable', 'string', 'max:2000'],
            'locationForm.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'locationForm.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'locationForm.coverage_mode' => ['nullable', Rule::in(['', 'radius', 'polygon'])],
            'locationForm.coverage_radius_miles' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
        ]);

        $lat = $this->blankToNull($this->locationForm['latitude'] ?? null);
        $lng = $this->blankToNull($this->locationForm['longitude'] ?? null);

        if (($lat === null) xor ($lng === null)) {
            $this->addError('locationForm.latitude', 'Enter both latitude and longitude, or leave both blank.');

            return;
        }

        $type = OrgLocationType::from($this->locationForm['type']);
        if (! $type->allowsMultiple() && $this->mainOfficeTaken($this->editingLocationId)) {
            $this->addError('locationForm.type', 'Only one Main Office is allowed. Edit the existing Main Office or choose another type.');

            return;
        }

        $coverageMode = $this->locationForm['coverage_mode'] ?: null;
        $radius = $this->blankToNull($this->locationForm['coverage_radius_miles'] ?? null);
        $polygon = $this->locationForm['coverage_polygon'] ?? null;

        if ($coverageMode === 'radius' && $radius === null) {
            $this->addError('locationForm.coverage_radius_miles', 'Enter a radius in miles.');

            return;
        }

        if ($coverageMode === 'polygon' && LocationCoverageMap::ring(is_array($polygon) ? $polygon : null) === []) {
            $this->addError('locationForm.coverage_mode', 'Draw a polygon on the map before saving.');

            return;
        }

        $attributes = [
            'org_id' => $this->locationOrgId(),
            'name' => trim($this->locationForm['name']),
            'type' => $type->value,
            'address' => trim($this->locationForm['address']),
            'phone' => $this->blankToNull($this->locationForm['phone'] ?? null),
            'notes' => $this->blankToNull($this->locationForm['notes'] ?? null),
            'latitude' => $lat,
            'longitude' => $lng,
            'coverage_mode' => $coverageMode,
            'coverage_radius_miles' => $coverageMode === 'radius' ? $radius : null,
            'coverage_polygon' => $coverageMode === 'polygon' ? $polygon : null,
        ];

        if ($this->editingLocationId) {
            $this->findLocation($this->editingLocationId)->update($attributes);
            $title = 'Location updated';
        } else {
            OrgLocation::query()->create($attributes);
            $title = 'Location added';
        }

        $this->cancelLocation();
        $this->fillCoverageForm();

        Notification::make()->title($title)->success()->send();
        $this->dispatch('locations-refresh', state: $this->locationMap());
    }

    public function deleteLocation(int $locationId): void
    {
        $this->authorizeLocationAdmin();
        $location = $this->findLocation($locationId);
        $name = $location->name;
        $location->delete();

        if ($this->editingLocationId === $locationId) {
            $this->cancelLocation();
        }

        $this->fillCoverageForm();

        Notification::make()->title($name.' deleted')->success()->send();
        $this->dispatch('locations-refresh', state: $this->locationMap());
    }

    public function saveCoverage(): void
    {
        $this->authorizeLocationAdmin();

        $this->coverageRadiusMiles = (string) ($this->blankToNull($this->coverageRadiusMiles) ?? '');

        $this->validate([
            'coverageScope' => ['required', Rule::in(['company', 'location'])],
            'coverageMode' => ['required', Rule::in(['radius', 'polygon'])],
            'coverageRadiusMiles' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'coverageCenterLocationId' => ['nullable', 'integer'],
        ]);

        if ($this->coverageScope === 'company' && $this->coverageMode === 'radius') {
            if ($this->blankToNull($this->coverageRadiusMiles) === null) {
                $this->addError('coverageRadiusMiles', 'Enter a radius in miles.');

                return;
            }

            $centerExists = $this->coverageCenterLocationId
                && OrgLocation::query()
                    ->where('org_id', $this->locationOrgId())
                    ->whereKey($this->coverageCenterLocationId)
                    ->exists();

            if (! $centerExists) {
                $this->addError('coverageCenterLocationId', 'Choose the location at the center of the radius.');

                return;
            }
        }

        if ($this->coverageScope === 'company' && $this->coverageMode === 'polygon' && LocationCoverageMap::ring($this->coveragePolygon) === []) {
            $this->addError('coverageMode', 'Draw the company-wide polygon on the map before saving.');

            return;
        }

        OrgCoverageZone::query()->updateOrCreate(
            ['org_id' => $this->locationOrgId()],
            [
                'scope' => $this->coverageScope,
                'mode' => $this->coverageMode,
                'radius_miles' => $this->blankToNull($this->coverageRadiusMiles),
                'center_location_id' => $this->coverageCenterLocationId,
                'polygon' => $this->coveragePolygon,
            ],
        );

        $this->fillCoverageForm();

        Notification::make()->title('Coverage zone saved')->success()->send();
        $this->dispatch('locations-refresh', state: $this->locationMap());
    }

    /**
     * @param  array<string, mixed>  $polygon
     */
    public function captureCompanyPolygon(array $polygon): void
    {
        $this->authorizeLocationAdmin();

        if (LocationCoverageMap::ring($polygon) === []) {
            return;
        }

        $this->coverageScope = 'company';
        $this->coverageMode = 'polygon';
        $this->coveragePolygon = $polygon;
        $this->saveCoverage();
    }

    /**
     * @param  array<string, mixed>  $polygon
     */
    public function captureLocationPolygon(array $polygon): void
    {
        $this->authorizeLocationAdmin();

        if (LocationCoverageMap::ring($polygon) === []) {
            Notification::make()->title('Draw at least three points')->warning()->send();

            return;
        }

        $this->locationForm['coverage_mode'] = 'polygon';
        $this->locationForm['coverage_polygon'] = $polygon;
        $this->showLocationForm = true;

        Notification::make()
            ->title('Polygon captured')
            ->body('Save the location to keep this coverage area.')
            ->success()
            ->send();
    }

    public function mapUnavailable(): void
    {
        Notification::make()
            ->title('Map drawing needs a Google Maps key')
            ->body('A radius still saves without the map. Tiles stay blank until GOOGLE_MAPS_API_KEY is set.')
            ->warning()
            ->send();
    }

    private function fillCoverageForm(): void
    {
        $coverage = OrgCoverageZone::query()->where('org_id', $this->locationOrgId())->first();
        $this->coverageScope = $coverage?->scope === 'location' ? 'location' : 'company';
        $this->coverageMode = $coverage?->mode === 'polygon' ? 'polygon' : 'radius';
        $this->coverageCenterLocationId = $coverage?->center_location_id;
        $this->coverageRadiusMiles = $coverage?->radius_miles === null ? '' : (string) $coverage->radius_miles;
        $this->coveragePolygon = $coverage?->polygon;
    }

    private function resetLocationForm(): void
    {
        $this->locationForm = [
            'name' => '',
            'type' => OrgLocationType::SatelliteOffice->value,
            'address' => '',
            'phone' => '',
            'notes' => '',
            'latitude' => '',
            'longitude' => '',
            'coverage_mode' => '',
            'coverage_radius_miles' => '',
            'coverage_polygon' => null,
        ];
    }

    private function findLocation(int $locationId): OrgLocation
    {
        return OrgLocation::query()
            ->where('org_id', $this->locationOrgId())
            ->whereKey($locationId)
            ->firstOrFail();
    }

    private function mainOfficeTaken(?int $ignoreId): bool
    {
        return OrgLocation::query()
            ->where('org_id', $this->locationOrgId())
            ->where('type', OrgLocationType::MainOffice->value)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function locationOrgId(): int
    {
        return (int) auth()->user()->org_id;
    }

    private function authorizeLocationAdmin(): void
    {
        if (! auth()->user()->hasRole(RoleEnum::ADMIN->value)) {
            abort(403);
        }
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return is_string($value) ? trim($value) : $value;
    }
}
