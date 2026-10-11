@php
    $isProFeature = $isProInactive();
@endphp

<x-pro-upgrade-popover :active="!$isProFeature" :description="$getProDescription()" :feature="$getProFeature()">
    @include('filament-forms::components.toggle')
</x-pro-upgrade-popover>
