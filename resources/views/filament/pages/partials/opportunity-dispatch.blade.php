@if ($showDispatch)
    <div id="dispatch-modal" class="fixed inset-0 z-[60] flex items-center justify-center p-4" style="background: rgb(3 7 18 / 0.5)">
        <div class="w-full max-w-lg rounded-xl border border-gray-200 bg-white p-5 shadow-xl dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                Dispatch {{ count($dispatchCaseIds) }} {{ count($dispatchCaseIds) === 1 ? 'opportunity' : 'opportunities' }}
            </h2>
            <p class="mt-1 text-sm text-gray-500">Assign a seeded Scout driver. The driver gets a logged stub only — nothing is sent.</p>

            <div class="mt-4 space-y-3">
                <label class="block text-sm font-medium text-gray-800 dark:text-gray-100" for="dispatch-driver">Driver</label>
                <select id="dispatch-driver" wire:model="dispatchDriverId" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                    <option value="">Choose a driver</option>
                    @foreach ($board['drivers'] as $driver)
                        <option value="{{ $driver['id'] }}">{{ $driver['name'] }} · {{ $driver['kit'] }} · {{ $driver['fix_label'] }}</option>
                    @endforeach
                </select>
                @error('dispatchDriverId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="block text-sm font-medium text-gray-800 dark:text-gray-100" for="dispatch-priority">Priority</label>
                <select id="dispatch-priority" wire:model="dispatchPriority" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                    <option value="high">High</option>
                    <option value="normal">Normal</option>
                </select>

                <label class="block text-sm font-medium text-gray-800 dark:text-gray-100" for="dispatch-due">Due by</label>
                <select id="dispatch-due" wire:model="dispatchDue" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                    <option value="tonight">Tonight</option>
                    <option value="overnight">Overnight</option>
                    <option value="tomorrow">Tomorrow</option>
                </select>

                <label class="block text-sm font-medium text-gray-800 dark:text-gray-100" for="dispatch-note">Note</label>
                <textarea id="dispatch-note" wire:model="dispatchNote" rows="3" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-950" placeholder="Check Oak Ave between 1 and 4am"></textarea>
                @error('dispatchNote') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex gap-2">
                <div class="ml-auto flex gap-2">
                <x-filament::button color="gray" wire:click="closeDispatch">Cancel</x-filament::button>
                <x-filament::button id="dispatch-submit" wire:click="saveDispatch" wire:loading.attr="disabled">Dispatch</x-filament::button>
                </div>
            </div>
        </div>
    </div>
@endif
