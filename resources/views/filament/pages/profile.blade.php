<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <br/>

        <x-filament::actions :actions="$this->getFormActions()" />
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
