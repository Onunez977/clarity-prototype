<x-filament-panels::page x-init="$store.sidebar.close()">
    <div class="space-y-6">
        <p class="font-bold">
            Current Version: {{ \Composer\InstalledVersions::getPrettyVersion('insight-lpr/platform') ?? '1.0.0' }}
        </p>

        <div class="space-y-4">
            @foreach(config('changelog.releases', []) as $version => $release)
                <x-filament::section
                    :collapsible="true"
                    :collapsed="!$loop->first"
                >
                    <x-slot name="heading">
                        <p class="font-semibold text-lg">
                            {{ $version }}
                        </p>
                    </x-slot>

                    <x-slot name="description">
                        <time class="text-sm text-gray-500 dark:text-gray-400">
                            Released on <span class="font-semibold">{{ $release['date'] }}</span>
                        </time>
                    </x-slot>

                    @if ($loop->first)
                        <x-slot name="headerEnd">
                            Latest
                        </x-slot>
                    @endif

                    <ul class="space-y-2 text-sm">
                        @foreach($release['changes'] as $change)
                            <li class="flex items-start gap-2">
                                <x-heroicon-o-chevron-right class="mt-0.5 h-3 w-3 shrink-0 text-gray-500" />
                                <span>{!! $change !!}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
