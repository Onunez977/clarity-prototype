<template>
    <div
        class="otto-page-action mt-1 w-full rounded-lg border px-3 py-2 text-sm"
        :class="accented
            ? 'border-primary-200 bg-primary-50 dark:border-primary-800 dark:bg-primary-900/20'
            : 'border-gray-200 bg-gray-100/70 dark:border-gray-700 dark:bg-gray-800/40'"
        :data-action-id="action.actionId"
        :data-action-name="action.name"
    >
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ ui.heading }}
        </div>
        <div class="otto-search-list mt-2 flex flex-col gap-1.5" :class="{ 'opacity-60 line-through': mutedList }">
            <div v-if="!rows.length" class="text-gray-500 dark:text-gray-400">{{ ui.emptyLabel }}</div>
            <div
                v-for="(row, index) in rows"
                :key="index"
                class="flex items-start gap-2 text-gray-700 dark:text-gray-200"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="mt-0.5 h-4 w-4 shrink-0 text-primary-600 dark:text-primary-400">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.5 7.5a1 1 0 01-1.42 0l-3.5-3.5a1 1 0 111.42-1.42l2.79 2.79 6.79-6.79a1 1 0 011.42 0z" />
                </svg>
                <span class="min-w-0">{{ row.summary }}</span>
                <span
                    v-if="row.badge"
                    class="ml-1 shrink-0 self-center rounded-full px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                    :class="row.badge === 'New'
                        ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300'
                        : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                >{{ row.badge }}</span>
            </div>
        </div>

        <div v-if="showActions" class="mt-2 flex flex-wrap items-center gap-2">
            <button
                v-if="mode === 'propose' || mode === 'zone'"
                type="button"
                class="rounded-md bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500"
                @click="onAccept"
            >{{ ui.applyLabel }}</button>
            <button
                v-if="mode === 'propose' && ui.showSearchInstead"
                type="button"
                class="rounded-md border border-primary-300 px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100 dark:border-primary-700 dark:text-primary-300 dark:hover:bg-primary-900/40"
                @click="onSearchInstead"
            >Make Otto search instead</button>
            <button
                v-if="mode === 'zone'"
                type="button"
                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                @click="onDismiss"
            >Dismiss</button>
            <button
                v-if="mode === 'reapply'"
                type="button"
                class="relative overflow-hidden rounded-md bg-primary-600 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:cursor-not-allowed"
                :disabled="cooldown"
                @click="onReapply"
            >
                {{ ui.reapplyLabel }}
                <span
                    v-if="cooldown"
                    class="pointer-events-none absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-white/80"
                    :style="{ width: barWidth, transition: 'width 2000ms linear' }"
                ></span>
            </button>
        </div>
        <div v-if="note" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ note }}</div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    dispatchPageAction,
    pageActionPresentation,
    searchInsteadPromptFromRows,
} from '../../lib/page-actions.js';
import { openZoneEditor, zoneCreate, zoneUpdate } from '../../lib/api.js';

const props = defineProps({
    action: { type: Object, required: true },
    payload: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    restored: { type: Boolean, default: false },
    conversationId: { type: String, default: null },
    thinking: { type: Boolean, default: false },
});

const emit = defineEmits(['search-instead']);

const ui = computed(() => pageActionPresentation(props.action.name));
const isZone = computed(() => props.action.name === 'create-zone'
    || props.action.name === 'update-zone'
    || props.action.name === 'open-zone-editor');

const mode = ref(props.restored && !isZone.value ? 'reapply' : (isZone.value ? 'zone' : 'propose'));
const muted = ref(false);
const note = ref('');
const queued = ref(false);
const onPage = ref(true);
const cooldown = ref(false);
const barWidth = ref('100%');
let cooldownTimer = null;

const accented = computed(() => {
    if (muted.value) {
        return false;
    }
    if (mode.value === 'reapply') {
        return onPage.value;
    }

    return mode.value === 'propose' || mode.value === 'zone';
});

const mutedList = computed(() => muted.value || (mode.value === 'reapply' && !onPage.value));
const showActions = computed(() => {
    if (muted.value && mode.value !== 'reapply') {
        return false;
    }
    if (mode.value === 'reapply') {
        return onPage.value;
    }

    return mode.value === 'propose' || mode.value === 'zone';
});

function refreshOnPage() {
    let pc = null;
    try {
        pc = window.Otto?.currentPageContext?.();
    } catch {
        pc = null;
    }
    onPage.value = !!pc && pc.pageType === props.action.pageType;
    if (mode.value === 'reapply') {
        note.value = onPage.value ? '' : ui.value.offPageNote;
    }
}

function onAccept() {
    if (isZone.value) {
        dispatchZone();

        return;
    }
    dispatchPageAction(props.action.pageType, props.action.name, props.payload);
    mode.value = 'reapply';
    refreshOnPage();
}

function onSearchInstead() {
    if (queued.value) {
        return;
    }
    const prompt = searchInsteadPromptFromRows(props.rows);
    if (props.thinking) {
        queued.value = true;
        emit('search-instead', { prompt, queued: true, finish: finishSearchInstead });
        note.value = 'Queued — Otto will search in chat when this reply finishes';

        return;
    }
    emit('search-instead', { prompt, queued: false, finish: finishSearchInstead });
}

function finishSearchInstead(accepted) {
    if (!accepted) {
        queued.value = false;

        return;
    }
    muted.value = true;
    mode.value = 'done';
    note.value = 'No filters changed on the page — Otto is running this search in the chat below.';
}

function onDismiss() {
    muted.value = true;
    mode.value = 'done';
    note.value = 'Dismissed.';
}

function onReapply() {
    if (cooldown.value) {
        return;
    }
    dispatchPageAction(props.action.pageType, props.action.name, props.payload);
    cooldown.value = true;
    barWidth.value = '100%';
    requestAnimationFrame(() => {
        barWidth.value = '0%';
    });
    cooldownTimer = setTimeout(() => {
        cooldown.value = false;
        barWidth.value = '100%';
    }, 2000);
}

async function dispatchZone() {
    if (!props.conversationId) {
        note.value = 'Could not complete that action.';

        return;
    }
    try {
        let result;
        if (props.action.name === 'create-zone') {
            result = await zoneCreate(props.conversationId, props.payload);
        } else if (props.action.name === 'update-zone') {
            result = await zoneUpdate(props.conversationId, props.payload);
        } else {
            result = await openZoneEditor(props.conversationId, props.payload);
        }
        if (!result?.ok) {
            note.value = result?.error || 'Could not complete that action.';

            return;
        }
        muted.value = true;
        mode.value = 'done';
        if (props.action.name === 'open-zone-editor' && result.url) {
            note.value = 'Opening the Zones map…';
            window.location.assign(result.url);

            return;
        }
        note.value = props.action.name === 'update-zone' ? 'Zone updated.' : 'Zone saved.';
        window.dispatchEvent(new CustomEvent('otto-investigation-changed'));
    } catch {
        note.value = 'Could not complete that action.';
    }
}

if (!props.restored && props.action.requiresConfirmation === false && !isZone.value) {
    dispatchPageAction(props.action.pageType, props.action.name, props.payload);
    mode.value = 'reapply';
}

onMounted(() => {
    refreshOnPage();
    window.addEventListener('otto:page-context-changed', refreshOnPage);
});

onBeforeUnmount(() => {
    window.removeEventListener('otto:page-context-changed', refreshOnPage);
    if (cooldownTimer) {
        clearTimeout(cooldownTimer);
    }
});
</script>
