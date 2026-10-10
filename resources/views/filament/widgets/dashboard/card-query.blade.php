<x-filament::section>
    <x-slot name="heading">
        <div class="flex items-center gap-2">
            <span>Query</span>
            <x-filament::badge color="danger" size="sm">Admin only</x-filament::badge>
        </div>
    </x-slot>

    <x-slot name="description">
        The statement that resolves this card's headline value on a cold cache. The number above is
        very likely served from cache, so this may not have run on this request. Valid against this
        environment only.
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span>connection <code class="font-mono">{{ $connection }}</code></span>
            <span>&middot;</span>
            <span>database <code class="font-mono">{{ $database }}</code></span>
            <span>&middot;</span>
            <span>driver <code class="font-mono">{{ $driver }}</code></span>
            <span>&middot;</span>
            <span>env <code class="font-mono">{{ $environment }}</code></span>
            <span>&middot;</span>
            <span>viewer timezone <code class="font-mono">{{ $timezone }}</code></span>
        </div>

        @if ($error !== null)
            <x-filament::section.description>
                <span class="text-danger-600 dark:text-danger-400">
                    Could not build the query detail: {{ $error }}
                </span>
            </x-filament::section.description>
        @elseif (! $supported)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Query detail isn't available for this card yet.
            </p>
        @elseif ($statements === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This card resolved its value without running a query.
            </p>
        @else
            @foreach ($statements as $statement)
                <div
                    wire:key="card-query-{{ $loop->index }}"
                    x-data="{
                        sql: @js($statement->sql),
                        copy: function () {
                            try {
                                navigator.clipboard.writeText(this.sql);

                                new FilamentNotification()
                                    .title('Query copied to clipboard')
                                    .color('info')
                                    .send()
                            } catch (e) {
                                try {
                                    new FilamentNotification()
                                        .title('There was an error copying to clipboard.')
                                        .body('Please select the query and copy it manually.')
                                        .color('warning')
                                        .send()
                                } catch (e) {
                                    //
                                }
                            }
                        },
                    }"
                    class="flex flex-col gap-2"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">
                                {{ $statement->label }}
                            </span>

                            @if ($statement->queryClass !== null)
                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">
                                    {{ $statement->queryClass }}
                                </span>
                            @endif
                        </div>

                        <x-filament::button
                            size="sm"
                            color="gray"
                            outlined="true"
                            icon="heroicon-m-clipboard-document-list"
                            tooltip="Copy to Clipboard"
                            x-on:click="copy()"
                        >
                            Copy
                        </x-filament::button>
                    </div>

                    @if ($statement->note !== null)
                        <p class="rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30">
                            {{ $statement->note }}
                        </p>
                    @endif

                    <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"><code class="font-mono whitespace-pre text-gray-950 dark:text-gray-200">{{ $statement->sql }}</code></pre>
                </div>
            @endforeach
        @endif
    </div>
</x-filament::section>
