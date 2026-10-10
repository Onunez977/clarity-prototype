@php
    $enabled = (bool) ($values[$option['key']] ?? false);
@endphp

<button
    type="button"
    role="switch"
    aria-checked="{{ $enabled ? 'true' : 'false' }}"
    aria-label="{{ $option['label'] }}"
    wire:click="toggle('{{ $option['key'] }}')"
    wire:loading.attr="disabled"
    wire:target="toggle"
    wire:key="configuration-switch-{{ $option['key'] }}"
    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-60"
    style="background-color: {{ $enabled ? '#FF6666' : 'rgb(209, 213, 219)' }};"
>
    <span
        aria-hidden="true"
        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
        style="transform: translateX({{ $enabled ? '1.25rem' : '0' }});"
    ></span>
</button>
