<x-filament-panels::page>
    <div class="space-y-6">
        @foreach (\App\Filament\Pages\Configuration::sections() as $section)
            @php
                $single = count($section['options']) === 1;
            @endphp

            <x-filament::section
                :heading="$section['heading']"
                :description="$single ? $section['options'][0]['helper'] : ($section['description'] ?? null)"
            >
                @if ($single)
                    <x-slot name="afterHeader">
                        @include('filament.pages.partials.configuration-switch', [
                            'option' => $section['options'][0],
                            'values' => $values,
                        ])
                    </x-slot>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($section['options'] as $option)
                            <div class="flex items-start justify-between gap-4 py-4 first:pt-0 last:pb-0" wire:key="configuration-row-{{ $option['key'] }}">
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $option['label'] }}
                                    </div>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {{ $option['helper'] }}
                                    </p>
                                </div>

                                @include('filament.pages.partials.configuration-switch', [
                                    'option' => $option,
                                    'values' => $values,
                                ])
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
