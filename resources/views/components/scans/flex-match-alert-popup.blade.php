<div
    class="inline-flex items-center"
    x-data="{
        scanId: null,
        ocr: null,
        vehicleImage: null,
        plateImage: null,
        hotlists: [],
        selectedHotlistIds: [],
        loading: false,
        sending: false,
        error: null,

        setModalData: function (data) {
            this.scanId = data.scanId;
            this.ocr = data.ocr;
            this.vehicleImage = data.vehicleImage;
            this.plateImage = data.plateImage;
            this.hotlists = [];
            this.selectedHotlistIds = [];
            this.loading = true;
            this.sending = false;
            this.error = null;

            $dispatch('open-modal', { id: 'flex-match-alert-modal' });
            trackAnalytics('flex-match-indicator-clicked', 'scans', { scan_id: this.scanId });

            getFlexMatchHotlists(this.scanId)
                .then((res) => {
                    this.hotlists = res.data?.hotlists ?? [];
                    this.selectedHotlistIds = this.hotlists.map(h => h.id);
                    this.loading = false;

                    if (this.hotlists.length === 0) {
                        this.error = 'No matching open hotlists found. The hotlist may have been closed.';
                    }
                })
                .catch(() => {
                    this.loading = false;
                    this.error = 'Failed to load matching hotlists.';
                });
        },

        toggleHotlist: function (id) {
            const index = this.selectedHotlistIds.indexOf(id);
            if (index > -1) {
                this.selectedHotlistIds.splice(index, 1);
            } else {
                this.selectedHotlistIds.push(id);
            }
        },

        sendAlert: function () {
            if (this.selectedHotlistIds.length === 0 || this.sending) return;

            this.sending = true;
            this.error = null;

            sendFlexMatchAlert(this.scanId, this.selectedHotlistIds)
                .then(() => {
                    this.sending = false;
                    this.closeModal();
                })
                .catch(() => {
                    this.sending = false;
                });
        },

        closeModal: function () {
            $dispatch('close-modal', { id: 'flex-match-alert-modal' });
        },
    }"
    x-on:show-flex-match-modal.window="setModalData($event.detail);"
>
    <x-filament::modal
        id="flex-match-alert-modal"
        width="lg"
        alignment="center"
        :autofocus="false"
    >
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="h-5 w-5 text-warning-500"
                />
                <span>Possible Hotlist Match</span>
            </div>
        </x-slot>

        <x-slot name="description">
            <p class="text-left">
                This scan (<strong x-text="ocr"></strong>) matches a hotlist plate via FlexSearch but did not trigger an alert &mdash; would you like to send to ClearPlan now?
            </p>
        </x-slot>

        <div>
            {{-- Scan images --}}
            <div
                class="relative flex flex-col items-center gap-4 mb-4"
                x-show="vehicleImage || plateImage"
            >
                <img
                    :src="vehicleImage"
                    x-show="vehicleImage && vehicleImage !== ''"
                    class="fi-avatar rounded-md bg-gray-200 w-full h-full"
                />

                <img
                    :src="plateImage"
                    x-show="plateImage && plateImage !== ''"
                    class="fi-avatar rounded-md bg-gray-200 md:min-w-[150px] md:w-[150px] min-h-[70px] max-h-[70px] mx-auto"
                />
            </div>

            {{-- Loading state --}}
            <div x-show="loading" class="flex items-center justify-center py-6">
                <x-filament::loading-indicator class="h-6 w-6" />
                <span class="ml-2 text-sm text-gray-500">Loading matching hotlists...</span>
            </div>

            {{-- Error state --}}
            <div x-show="error && !loading" x-cloak class="py-4">
                <p class="text-sm text-center text-danger-600 dark:text-danger-400" x-text="error"></p>
            </div>

            {{-- Hotlist matches --}}
            <div x-show="!loading && hotlists.length > 0" x-cloak>
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Matching Hotlist Plates:
                </h4>

                <div class="space-y-2 max-h-60 overflow-y-auto">
                    <template x-for="hotlist in hotlists" :key="hotlist.id">
                        <label
                            class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition"
                            :class="{ 'ring-2 ring-warning-500 border-warning-500': selectedHotlistIds.includes(hotlist.id) }"
                        >
                            <input
                                type="checkbox"
                                :checked="selectedHotlistIds.includes(hotlist.id)"
                                x-on:change="toggleHotlist(hotlist.id)"
                                class="mt-1 fi-checkbox-input rounded border-gray-300 text-warning-600 shadow-sm focus:ring-warning-500 dark:border-gray-600 dark:bg-gray-700"
                            />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-semibold text-sm" x-text="hotlist.plate"></span>
                                    <span class="text-xs text-gray-500" x-text="hotlist.us_state"></span>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <span x-show="hotlist.vehicle_year || hotlist.vehicle_make || hotlist.vehicle_model"
                                          x-text="[hotlist.vehicle_year, hotlist.vehicle_make, hotlist.vehicle_model].filter(Boolean).join(' ')">
                                    </span>
                                    <span x-show="hotlist.vin" class="ml-2">
                                        VIN: <span x-text="hotlist.vin"></span>
                                    </span>
                                </div>
                            </div>
                        </label>
                    </template>
                </div>
            </div>
        </div>

        <x-slot name="footerActions">
            <div class="flex items-center justify-between gap-4 w-full">
                <x-filament::button
                    size="sm"
                    outlined
                    icon="heroicon-m-x-mark"
                    x-on:click="closeModal()"
                >
                    Close
                </x-filament::button>

                @php
                    $isPro = \Laravel\Pennant\Feature::active(\App\Enums\FeatureEnum::CLEARPLAN_RESEND->slug()) || session()->has('admin_impersonator');
                    $isAdmin = auth()->user()?->hasRole(\InsightLPR\BlackAdam\Enum\RoleEnum::ADMIN->value);
                    $canSend = $isPro && $isAdmin;
                    $disabledTooltip = ! $isPro ? 'Upgrade to Pro to send ClearPlan alerts' : 'Admin role required';
                @endphp

                @if ($canSend)
                    <x-filament::button
                        size="sm"
                        color="warning"
                        icon="heroicon-m-paper-airplane"
                        x-on:click="sendAlert()"
                        x-bind:disabled="selectedHotlistIds.length === 0 || sending || loading || error"
                    >
                        <span x-show="!sending">Send to ClearPlan</span>
                        <span x-show="sending" class="flex items-center gap-2">
                            <x-filament::loading-indicator class="h-4 w-4" />
                            Sending...
                        </span>
                    </x-filament::button>
                @else
                    <x-filament::button
                        size="sm"
                        color="warning"
                        icon="heroicon-m-paper-airplane"
                        disabled
                        x-tooltip="{
                            content: '{{ $disabledTooltip }}',
                            theme: $store.theme,
                        }"
                    >
                        Send to ClearPlan
                    </x-filament::button>
                @endif
            </div>
        </x-slot>
    </x-filament::modal>
</div>
