{{-- Clarity Otto Vue island (registered on App panel LAYOUT_END only). --}}
@auth
    @if (\App\Support\Otto\OttoAccess::userCanUseOtto(auth()->user()))
        <x-otto.island />
        @vite('resources/js/otto/filament.js')
    @endif
@endauth
