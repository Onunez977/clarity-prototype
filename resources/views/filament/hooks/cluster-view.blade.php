@php
    $table = $this?->table ?? null;
    $clusterView = \property_exists($this, 'clusterView') && $this->clusterView;
    $modelClass = $this->getModel();
    $hasClustering = \method_exists($modelClass, 'clusterByLocation');
    $visitDateRange = \method_exists($this, 'getVisitDateRange') ? $this->getVisitDateRange() : [null, null];
    $isAddressMode = \method_exists($this, 'getIsAddressClusterMode') && $this->getIsAddressClusterMode();
    $filterCoordinates = \method_exists($this, 'getClusterFilterCoordinates') ? $this->getClusterFilterCoordinates() : [];
    $searchedPlate = \method_exists($this, 'getSearchedPlate') ? $this->getSearchedPlate() : null;
    $flexSearchEnabled = \method_exists($this, 'isFlexSearchEnabled') && $this->isFlexSearchEnabled();
@endphp

@if ($hasClustering && $clusterView && (session()->has('admin_impersonator') || \Laravel\Pennant\Feature::active(\App\Enums\FeatureEnum::SCAN_CLUSTERING->slug())))
    @if ($table?->getRecords()->isNotEmpty())
        @php
            if ($isAddressMode) {
                $clusters = collect([$table->getRecords()->values()]);
            } else {
                $clusters = $modelClass::clusterByLocation(
                    $table?->getRecords(),
                    timestampField: $modelClass::clusterTimestampField(),
                ) ?? collect();
            }
        @endphp

        <section class="cluster-view">
            <div class="flex flex-col gap-4 w-full">
                @foreach($clusters as $group)
                    <x-dynamic-component
                        :component="$modelClass::clusterGroupComponent()"
                        :group="$group"
                        :table="$table"
                        :filterStartDate="$visitDateRange[0]"
                        :filterEndDate="$visitDateRange[1]"
                        :addressMode="$isAddressMode"
                        :filterCoordinates="$filterCoordinates"
                        :searchedPlate="$searchedPlate"
                        :flexSearchEnabled="$flexSearchEnabled"
                    />
                @endforeach
            </div>

            @if ($table)
                <x-filament::pagination
                    :paginator="$table->getRecords()"
                    :page-options="$table->getPaginationPageOptions()"
                    class="fi-ta-pagination px-3 py-3 sm:px-6"
                />
            @endif
        </section>
    @endif
@endif
