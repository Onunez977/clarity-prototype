<div
    class="inline-flex items-center"
    x-data="{
        modelId: null,
        title: null,
        ymm: null,
        scannedAt: null,
        address: '',
        lat: '',
        lng: '',
        vehicleImage: null,
        plateImage: null,
        missedOpportunity: null,
        rowLoop: {},
        disposition: null,
        showReasons: false,

        setModalData: function (data) {
            this.activeTab = 'tab1';
            this.shrink = true;

            this.modelId = data.modelId;
            this.title = data.title;
            this.ymm = data.ymm ?? null;
            this.scannedAt = data.scannedAt;
            this.address = data.address ?? '';
            this.lat = data.lat;
            this.lng = data.lng;
            this.vehicleImage = data.vehicleImage;
            this.plateImage = data.plateImage;
            this.missedOpportunity = data.missedOpportunity === '1';
            this.rowLoop = JSON.parse(data.rowLoop);
            this.disposition = data.disposition ?? data.defaultDisposition;

            this.showReasons = this.disposition === '{{ \App\Enums\DispositionEnum::INVALID_SCAN->value }}';

            if (this.address === '') {
                this.address = this.lat+', '+this.lng;
                setTimeout(() => {
                    geocodeReverse(this.lat, this.lng, this.modelId)
                        .then((res) => {
                            var a = res.data?.results[0]?.formatted_address ?? '';
                            if (a !== '') {
                                this.address = a;
                            }
                    });
                }, 2000);
            }

            $dispatch('open-modal', { id: 'scan-disposition-modal' });
            trackAnalytics('disposition-popup-opened', 'scans', { scan_id: this.modelId });

            if (!this.ymm) {
                getScanVehicle(this.modelId)
                    .then((res) => {
                        this.ymm = res.data?.vehicle ?? null;
                    })
                    .catch(() => {
                        this.ymm = null;
                    });
            }
        },

        next: function () {
            if (this.rowLoop && this.rowLoop.index < this.rowLoop.count - 1) {
                this.closeAndOpenDispositionModal(this.rowLoop.index + 1);
            }
        },

        prev: function () {
            if (this.rowLoop && this.rowLoop.index > 0) {
                this.closeAndOpenDispositionModal(this.rowLoop.index - 1);
            }
        },

        closeAndOpenDispositionModal: function (id) {
            const nextElement = document.getElementById('scan-' + id);
            if (nextElement?.querySelector('.disposition-trigger')) {
                nextElement.querySelector('.disposition-trigger').click();
            }
        },

        changeDisposition: function (value) {
            if (value === '{{ \App\Enums\DispositionEnum::INVALID_SCAN->value }}' && ! this.showReasons) {
                this.showReasons = true;

                return;
            }

            window.updateScanDisposition(
                this.modelId,
                value,
                'other',
                this.missedOpportunity
                    ? 'scan-' + (this.rowLoop?.index ?? this.modelId)
                    : null
            );
            this.showReasons = false;

            if (value === '{{ \App\Enums\DispositionEnum::INVALID_SCAN->value }}') {
                if (this.rowLoop && this.rowLoop.index < this.rowLoop.count - 1) {
                    this.closeAndOpenDispositionModal(this.rowLoop.index + 1);
                } else {
                    this.closeModal();
                }
            }
        },

        closeModal: function () {
            $dispatch('close-modal', { id: 'scan-disposition-modal' });
        },
    }"
    x-on:show-disposition-modal.window="setModalData($event.detail);"
>
    <x-filament::modal
        id="scan-disposition-modal"
        width="xl"
        alignment="center"
        :autofocus="false"
    >
        <x-slot name="heading">
            <span x-text="title"></span>
        </x-slot>

        <x-slot name="description" class="w-full">
            <div class="flex flex-col items-start">
                <span data-scanned-at x-show="scannedAt !== null && scannedAt !== ''" class="text-left">
                    <strong>Scanned At</strong>: <span x-text="scannedAt"></span>
                </span>
                <span data-address x-show="address !== null && address !== ''" class="text-left">
                    <strong>Address</strong>: <span x-text="address"></span>
                </span>
                <span data-ymm x-show="ymm !== null && ymm !== ''" class="text-left">
                    <strong>Vehicle</strong>: <span x-text="ymm"></span>
                </span>

                <div class="flex flex-row items-center justify-between w-full gap-2 mt-2">
                    <x-filament::button
                        size="sm"
                        color="gray"
                        title="Previous"
                        outlined
                        icon="heroicon-m-arrow-left"
                        x-show="rowLoop"
                        x-on:click="prev()"
                    >
                        <span class="hidden lg:inline">Previous</span>
                    </x-filament::button>

                    <x-filament::input.wrapper class="flex-1">
                        <x-filament::input.select
                            x-model="disposition"
                            x-on:change="changeDisposition($event.target.value)"
                        >
                            <option value="" x-text="missedOpportunity ? 'Awaiting Validation' : 'Select a Disposition'"></option>
                            @foreach(\App\Enums\DispositionEnum::formOptions(missedOpportunity: false) as $value => $label)
                                <option
                                    value="{{ $value }}"
                                    x-show="missedOpportunity === false"
                                    :selected="disposition === '{{ $value }}'"
                                >
                                    {{ $label }}
                                </option>
                            @endforeach
                            @foreach(\App\Enums\DispositionEnum::formOptions(missedOpportunity: true) as $value => $label)
                                <option
                                    value="{{ $value }}"
                                    x-show="missedOpportunity === true"
                                    :selected="disposition === '{{ $value }}'"
                                >
                                    {{ $label }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <x-filament::button
                        size="sm"
                        color="gray"
                        title="Next"
                        outlined
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                        x-show="rowLoop"
                        x-on:click="next()"
                    >
                        <span class="hidden lg:inline">Next</span>
                    </x-filament::button>
                </div>
            </div>
        </x-slot>

        <div>
            <div
                class="border-b mb-6 pb-6"
                x-show="showReasons"
                x-cloak
            >
                <h3 class="fi-modal-heading flex flex-row items-center gap-2 text-base lg:text-lg text-center justify-center font-semibold leading-6 text-gray-950 dark:text-white me-6">
                    <x-filament::icon-button
                        icon="heroicon-o-exclamation-triangle"
                        color="warning"
                    />
                    WARNING
                    <x-filament::icon-button
                        icon="heroicon-o-exclamation-triangle"
                        color="warning"
                    />
                </h3>
                <p class="text-base text-center bold">
                    Marking this as "invalid" will no longer generate historical hits.<br/>
                    <strong>What was the reason for marking this invalid?</strong><br/>
                    Please select an option below to continue.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-2 mt-4">
                    @foreach($reasons as $reason)
                        <x-filament::button
                            size="sm"
                            outlined="true"
                            class="{{ $loop->last ? 'md:col-span-2' : '' }}"
                            x-on:click="window.updateScanDisposition(modelId, '{{ \App\Enums\DispositionEnum::INVALID_SCAN->value }}', '{{ \strtolower($reason) }}', 'scan-'+rowLoop.index)"
                        >
                            {{ $reason }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>

            <div
                class="relative flex flex-col items-center gap-4 mb-4"
                x-show="vehicleImage !== null || plateImage !== null"
            >
                <img
                    :src="vehicleImage"
                    x-show="vehicleImage !== null && vehicleImage !== ''"
                    class="fi-avatar rounded-md bg-gray-200 w-full h-full"
                />

                <img
                    :src="plateImage"
                    x-show="plateImage !== null && plateImage !== ''"
                    class="fi-avatar rounded-md bg-gray-200 md:min-w-[150px] md:w-[150px] min-h-[70px] max-h-[70px] mx-auto"
                />
            </div>
        </div>

        <x-slot name="footerActions">
            <div class="flex items-center justify-between gap-4 w-full">
                <x-filament::icon-button
                    icon="heroicon-m-arrow-left"
                    size="lg"
                    color="gray"
                    label="Previous"
                    x-show="rowLoop"
                    x-on:click="prev()"
                />

                <x-filament::button
                    size="sm"
                    outlined="true"
                    class="w-full flex-grow"
                    icon="heroicon-m-x-mark"
                    x-on:click="closeModal()"
                >
                    Close
                </x-filament::button>

                <x-filament::icon-button
                    icon="heroicon-m-arrow-right"
                    size="lg"
                    color="gray"
                    label="Next"
                    x-show="rowLoop"
                    x-on:click="next()"
                />
            </div>
        </x-slot>
    </x-filament::modal>
</div>
