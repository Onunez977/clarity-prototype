<template>
    <div
        :data-detail-type="type"
        :data-record-id="recordId"
        class="not-prose my-3 w-full overflow-hidden rounded-lg border shadow-sm"
        :class="shellClass"
    >
        <div class="flex items-center gap-2 border-b px-3 py-2" :class="headerBorderClass">
            <span class="h-2 w-2 rounded-full" :class="dotClass"></span>
            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ headerLabel }}</span>
        </div>

        <div v-if="!loaded" class="px-3 py-4 text-center text-xs text-gray-400 dark:text-gray-500">Loading…</div>
        <div v-else-if="!record" class="px-3 py-4 text-center text-xs text-gray-400 dark:text-gray-500">This record isn’t in the investigation results yet.</div>

        <div v-else-if="isHotlist" class="grid grid-cols-1 gap-3 p-3">
            <div class="rounded-md border-2 border-gray-400 bg-gray-100 px-2 py-1 text-center text-lg font-bold tracking-wide text-gray-800 dark:border-gray-500 dark:bg-gray-700 dark:text-gray-100">
                <span>{{ record.plate }}</span> <span class="text-sm text-gray-500">{{ record.state }}</span>
            </div>
            <div class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                <div>
                    <div class="text-gray-500 dark:text-gray-400">VIN</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.vin || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Vehicle</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.vehicle || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Status</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.status_label || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Source</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.source || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Client</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.client || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Lienholder</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.lienholder || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Order ID</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.order_id || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Order date</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.order_date || '—' }}</div>
                </div>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <img v-if="record.car_image" :src="record.car_image" alt="Vehicle" class="w-full rounded-md object-cover" loading="lazy" />
                <div class="flex items-center gap-3">
                    <div class="rounded-md border-2 border-gray-400 bg-gray-100 px-2 py-1 text-center text-lg font-bold tracking-wide text-gray-800 dark:border-gray-500 dark:bg-gray-700 dark:text-gray-100">
                        <span>{{ record.plate }}</span> <span class="text-sm text-gray-500">{{ record.state }}</span>
                    </div>
                    <img v-if="record.plate_image" :src="record.plate_image" alt="Plate" class="h-12 rounded object-contain" loading="lazy" />
                </div>
                <a v-if="mapUrl" :href="mapUrl" target="_blank" rel="noopener">
                    <img :src="mapUrl" alt="Map" class="w-full rounded-md object-cover" loading="lazy" />
                </a>
            </div>

            <div class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Time</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.scanned_at || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Camera</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.camera || '—' }}</div>
                </div>
                <div class="col-span-2">
                    <div class="text-gray-500 dark:text-gray-400">Approximate location</div>
                    <a :href="mapsLink" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                        {{ address || (record ? (record.lat + ', ' + record.lng) : '—') }}
                    </a>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">AI make</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.vehicle_make || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">AI color</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.vehicle_color || '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">AI type</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.vehicle_type || '—' }}</div>
                </div>
                <template v-if="isHit">
                    <div class="col-span-2 mt-1 space-y-2 border-t border-red-200 pt-2 dark:border-red-800">
                        <div v-if="record.hotlist_name">
                            <div class="text-gray-500 dark:text-gray-400">Hotlist</div>
                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.hotlist_name }}</div>
                        </div>
                        <div v-if="record.case_number">
                            <div class="text-gray-500 dark:text-gray-400">Case #</div>
                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.case_number }}</div>
                        </div>
                        <div v-if="record.hotlist_description">
                            <div class="text-gray-500 dark:text-gray-400">Description</div>
                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ record.hotlist_description }}</div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { investigation } from '../../lib/investigation.js';

const props = defineProps({
    type: { type: String, default: 'scan' },
    recordId: { type: [String, Number], required: true },
});

const loaded = ref(false);
const record = ref(null);
const address = ref('');

const isHit = computed(() => props.type === 'hit');
const isHotlist = computed(() => props.type === 'hotlist');

const headerLabel = computed(() => {
    if (isHotlist.value) {
        return 'Assignment Detail';
    }

    return isHit.value ? 'Hit Detail' : 'Scan Detail';
});

const shellClass = computed(() => {
    if (isHotlist.value) {
        return 'border-amber-200 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-900/10';
    }
    if (isHit.value) {
        return 'border-red-200 bg-red-50/60 dark:border-red-800 dark:bg-red-900/10';
    }

    return 'border-blue-200 bg-blue-50/60 dark:border-blue-800 dark:bg-blue-900/10';
});

const headerBorderClass = computed(() => {
    if (isHotlist.value) {
        return 'border-amber-200 dark:border-amber-800';
    }
    if (isHit.value) {
        return 'border-red-200 dark:border-red-800';
    }

    return 'border-blue-200 dark:border-blue-800';
});

const dotClass = computed(() => {
    if (isHotlist.value) {
        return 'bg-amber-500';
    }
    if (isHit.value) {
        return 'bg-red-500';
    }

    return 'bg-blue-500';
});

const mapUrl = computed(() => (record.value?.guid ? ('/scans/static-map-by-guid/' + record.value.guid + '.png') : null));
const mapsLink = computed(() => {
    if (!record.value) {
        return '#';
    }

    return 'https://www.google.com/maps/search/?api=1&query=' + record.value.lat + '%2C' + record.value.lng + '&zoom=17';
});

function pool() {
    if (isHotlist.value) {
        return investigation.hotlists;
    }
    if (isHit.value) {
        return investigation.hits;
    }

    return investigation.scans;
}

function load() {
    record.value = pool().find((r) => String(r.id) === String(props.recordId)) || null;
    loaded.value = investigation.loaded;
    if (record.value && !isHotlist.value && !address.value) {
        geocode();
    }
}

function geocode() {
    if (!record.value || record.value.lat == null || record.value.lng == null) {
        return;
    }
    const body = { data: record.value.lat + ',' + record.value.lng };
    const request = window.axios
        ? window.axios.post('/api/geocoder/reverse', body).then((r) => r.data)
        : Promise.reject();
    request
        .then((d) => {
            address.value = d?.results?.[0]?.formatted_address || '';
        })
        .catch(() => {});
}

watch(() => [investigation.loaded, investigation.scans, investigation.hits, investigation.hotlists, props.recordId, props.type], load, { immediate: true, deep: true });
</script>
