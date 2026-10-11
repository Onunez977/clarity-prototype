<div
    class="flex items-center gap-2"
    x-data="{
        modelId: null,
        title: null,
        ocr: null,
        ymm: null,
        vehicleImage: null,
        plateImage: null,
        vin: null,
        showTabs: false,
        dispositionable: null,
        missedOpportunity: null,
        rowLoop: {},
        activeTab: 'tab1',
        shrink: true,

        setModalData: function (data) {
            this.activeTab = 'tab1';
            this.shrink = true;

            this.modelId = data.modelId;
            this.title = data.title;
            this.ocr = data.ocr;
            this.vin = data.vin;
            this.ymm = data.ymm ?? null;
            this.vehicleImage = data.vehicleImage;
            this.plateImage = data.plateImage;
            this.showTabs = data.showTabs === '1';
            this.dispositionable = data.dispositionable === '1';
            this.missedOpportunity = data.missedOpportunity === '1';
            this.rowLoop = JSON.parse(data.rowLoop);

            $dispatch('open-modal', { id: 'scan-plate-modal' });
            trackAnalytics('plate-images-popup-opened', 'scans', { scan_id: this.modelId });

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
                this.activeTab = 'tab1';
                this.closeAndOpenImageModal(this.rowLoop.index + 1);
            }
        },

        prev: function () {
            if (this.rowLoop && this.rowLoop.index > 0) {
                this.activeTab = 'tab1';
                this.closeAndOpenImageModal(this.rowLoop.index - 1);
            }
        },

        closeAndOpenImageModal: function (id) {
            const nextElement = document.getElementById('scan-' + id);
            if (nextElement?.querySelector('.popup-trigger')) {
                nextElement.querySelector('.popup-trigger').click();
            }
        },

        showDispositionModal: function () {
            this.closeModal();

            const nextElement = document.getElementById('scan-' + this.rowLoop.index);
            if (nextElement?.querySelector('.disposition-trigger')) {
                nextElement.querySelector('.disposition-trigger').click();
            }
        },

        closeModal: function () {
            $dispatch('close-modal', { id: 'scan-plate-modal' });
        },
    }"
    x-on:show-plates-modal.window="setModalData($event.detail);"
>
    <x-filament::modal
        id="scan-plate-modal"
        class="scan-plate-modal"
        width="full"
        :slideOver="true"
    >
        <x-slot name="heading">
            <span x-text="title"></span>
        </x-slot>

        <x-slot name="description">
            <div class="flex flex-col items-start">
                <span data-vin x-show="vin !== null && vin !== ''">
                    <strong>VIN</strong>: <span x-text="vin"></span>
                </span>
                <span data-ymm x-show="ymm !== null && ymm !== ''">
                    <strong>Vehicle</strong>: <span x-text="ymm"></span>
                </span>
            </div>

            <div class="flex flex-col items-start" x-show="rowLoop && rowLoop.count > 1">
                <div class="flex flex-row items-center justify-between gap-2 mt-2">
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

            <div class="flex items-center space-x-4 mt-4">
                <div class="flex items-center space-x-4" x-show="showTabs">
                    <div>
                        <x-filament::button
                            size="sm"
                            class="w-full"
                            color="gray"
                            x-show="activeTab !== 'tab1'"
                            x-on:click="activeTab = 'tab1'"
                        >
                            Car
                        </x-filament::button>
                        <x-filament::button
                            size="sm"
                            class="w-full"
                            outlined
                            x-show="activeTab === 'tab1'"
                        >
                            Car
                        </x-filament::button>
                    </div>

                    <div>
                        <x-filament::button
                            size="sm"
                            class="w-full"
                            color="gray"
                            x-show="activeTab !== 'tab2'"
                            x-on:click="activeTab = 'tab2'"
                        >
                            Plate
                        </x-filament::button>

                        <x-filament::button
                            size="sm"
                            class="w-full"
                            outlined
                            x-show="activeTab === 'tab2'"
                            x-on:click="activeTab = 'tab2'"
                        >
                            Plate
                        </x-filament::button>
                    </div>
                </div>

                <x-filament::button
                    size="sm"
                    class="w-full"
                    outlined
                    x-show="dispositionable"
                    x-on:click="showDispositionModal()"
                    x-text="missedOpportunity ? 'Change Validation Status' : 'Disposition Scan'"
                ></x-filament::button>
            </div>
        </x-slot>

        <div class="relative flex items-center justify-center w-full h-full">
            <img
                :src="vehicleImage"
                x-show="activeTab === 'tab1'"
                class="!h-full rounded-md bg-gray-200 w-full"
            />

            <div
                class="flex items-center justify-center !w-full !h-full"
                x-show="showTabs && activeTab === 'tab2' && (plateImage !== null && plateImage !== '')"
            >
                <div class="flex items-center justify-center absolute top-2 right-2 w-6 md:w-8 lg:w-12 h-6 md:h-8 lg:h-12">
                    <x-filament::icon-button
                        size="lg"
                        icon="heroicon-m-arrows-pointing-out"
                        label="Expand Image"
                        tooltip="Expand Image"
                        class="w-full h-full"
                        x-show="shrink === true"
                        x-on:click="shrink = false"
                    />

                    <x-filament::icon-button
                        size="lg"
                        icon="heroicon-m-arrows-pointing-in"
                        label="Shrink Image"
                        tooltip="Shrink Image"
                        class="w-full h-full"
                        x-show="shrink === false"
                        x-on:click="shrink = true"
                    />
                </div>

                <img
                    :src="plateImage"
                    class="fi-avatar w-full rounded-md bg-gray-200"
                    :class="{
                        'md:min-w-[500px] md:w-[500px] min-h-[200px] max-h-[200px] m-auto' : shrink,
                        '!h-full' : ! shrink
                    }"
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
