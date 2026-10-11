<span
    class="hidden"
    wire:ignore
    x-init="$watch('entangledSelectedRecords', (values) => $dispatch('load-records-for-map', { recordIds: values }))"
></span>
