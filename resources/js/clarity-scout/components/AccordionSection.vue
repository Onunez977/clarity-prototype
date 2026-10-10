<template>
    <div
        class="rounded-2xl overflow-hidden"
        :class="nested
            ? 'bg-gray-50/80'
            : 'bg-white shadow-sm shadow-gray-200/60'"
    >
        <button
            @click="isOpen = !isOpen"
            class="w-full flex items-center gap-3 px-5 py-4 min-h-[44px] text-left"
        >
            <div
                v-if="icon"
                class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                :class="iconBgClass"
            >
                <svg class="w-5 h-5" :class="iconColorClass" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[icon]" />
                </svg>
            </div>
            <span
                class="flex-1 font-semibold text-gray-900"
                :class="nested ? 'text-sm' : 'text-base'"
            >{{ title }}</span>
            <svg
                class="w-6 h-6 text-gray-400 transition-transform duration-200 flex-shrink-0"
                :class="{ 'rotate-180': isOpen }"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div v-show="isOpen" class="px-5 pb-5" :class="{ 'pt-0': true }">
            <div v-if="!nested" class="border-t border-gray-100 pt-4">
                <slot />
            </div>
            <slot v-else />
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const iconPaths = {
    book: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
    list: 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
    download: 'M9 8.25H7.5a2.25 2.25 0 00-2.25 2.25v9a2.25 2.25 0 002.25 2.25h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25H15M9 12l3 3m0 0l3-3m-3 3V2.25',
    grid: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
    route: 'M9 6.75V15m6-6v8.25m.503-12.713l.002-.002a15.9 15.9 0 012.556-2.117A3.374 3.374 0 0121 3.748V15.93a3.375 3.375 0 01-2.94 3.34l-.16.022a15.9 15.9 0 01-2.556-.12L9 17.25 3.94 19.17a15.9 15.9 0 01-2.556.12l-.16-.022A3.375 3.375 0 010 15.93V3.748a3.374 3.374 0 012.939-.18 15.9 15.9 0 012.556 2.117l.002.002L9 9.375l6-2.625z',
    map: 'M15 10.5a3 3 0 11-6 0 3 3 0 016 0z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z',
    phone: 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3',
    desktop: 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z',
};

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    defaultOpen: {
        type: Boolean,
        default: false,
    },
    icon: {
        type: String,
        default: null,
    },
    iconColor: {
        type: String,
        default: 'blue',
    },
    nested: {
        type: Boolean,
        default: false,
    },
});

const colorMap = {
    blue:   { bg: 'bg-blue-100',   text: 'text-blue-600' },
    purple: { bg: 'bg-purple-100', text: 'text-purple-600' },
    green:  { bg: 'bg-green-100',  text: 'text-green-600' },
    amber:  { bg: 'bg-amber-100',  text: 'text-amber-600' },
};

const iconBgClass = colorMap[props.iconColor]?.bg ?? 'bg-blue-100';
const iconColorClass = colorMap[props.iconColor]?.text ?? 'text-blue-600';

const isOpen = ref(props.defaultOpen);

defineExpose({ isOpen });
</script>
