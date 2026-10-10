<template>
    <div
        data-widget="convoy"
        :data-search-id="searchId"
        class="otto-widget not-prose my-3 w-full overflow-hidden rounded-lg border border-amber-200 bg-amber-50 shadow-sm dark:border-amber-800 dark:bg-amber-900/10"
    >
        <div class="flex items-center gap-2 border-b border-amber-200 px-3 py-2 dark:border-amber-800">
            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ headerTitle }}</span>
            <span v-if="loaded" class="ml-auto text-[10px] text-gray-400 dark:text-gray-500">
                {{ items.length }} {{ items.length === 1 ? 'companion' : 'companions' }}
            </span>
        </div>
        <div v-if="loaded && items.length > 0">
            <table class="w-full text-xs">
                <thead class="bg-amber-100/40 dark:bg-amber-900/20">
                    <tr>
                        <th class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Companion plate</th>
                        <th v-if="hasTypes" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Vehicle</th>
                        <th class="px-3 py-1.5 text-right font-medium text-gray-500 dark:text-gray-400">Times seen together</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.plate"
                        class="border-t border-amber-100 dark:border-amber-900/40"
                    >
                        <td class="px-3 py-1.5 font-mono font-semibold text-gray-800 dark:text-gray-200">{{ item.plate }}</td>
                        <td v-if="hasTypes" class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.vehicle_type || '—' }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ item.occurrences }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!loaded" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
            Loading companions…
        </div>
        <div v-else-if="items.length === 0" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
            No companions found.
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { investigation } from '../../lib/investigation.js';

const props = defineProps({
    searchId: { type: String, required: true },
    title: { type: String, default: '' },
});

const loaded = ref(false);
const items = ref([]);
const resolvedTitle = ref(props.title);

const headerTitle = computed(() => resolvedTitle.value || 'Convoy Companions');
const hasTypes = computed(() => items.value.some((i) => i.vehicle_type));

function loadItems() {
    items.value = investigation.convoys.filter((i) => i.searchId === props.searchId);
    const search = investigation.searches.find((s) => s.searchId === props.searchId);
    if (search?.title) {
        resolvedTitle.value = search.title;
    }
    if (search || items.value.length > 0) {
        loaded.value = true;
    }
}

watch(() => [investigation.loaded, investigation.convoys, investigation.searches], loadItems, { immediate: true, deep: true });
</script>
