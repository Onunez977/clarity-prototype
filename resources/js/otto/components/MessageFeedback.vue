<template>
    <span class="otto-feedback inline-flex flex-wrap items-center gap-1" data-otto-feedback-root>
        <button
            type="button"
            class="rounded p-0.5 transition"
            :class="rating === true
                ? 'text-green-500 dark:text-green-400'
                : 'text-gray-400 hover:text-green-500 dark:hover:text-green-400'"
            aria-label="Helpful"
            :aria-pressed="rating === true ? 'true' : 'false'"
            :disabled="disabled || busy"
            @click="choose(true)"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true"><path d="M2 10.5a1.5 1.5 0 113 0v6a1.5 1.5 0 01-3 0v-6zM6 10.333v5.818a1.5 1.5 0 001.037 1.427l5.462 1.82A2.5 2.5 0 0015.5 17.2V8.667a1.5 1.5 0 00-.65-1.248L11.5 5.5V3.5A1.5 1.5 0 0010 2H8.5A1.5 1.5 0 007 3.5v2.167L4.65 7.42A1.5 1.5 0 004 8.667v.166A1.5 1.5 0 006 10.333z" /></svg>
        </button>
        <button
            type="button"
            class="rounded p-0.5 transition"
            :class="rating === false
                ? 'text-red-500 dark:text-red-400'
                : 'text-gray-400 hover:text-red-500 dark:hover:text-red-400'"
            aria-label="Not helpful"
            :aria-pressed="rating === false ? 'true' : 'false'"
            :disabled="disabled || busy"
            @click="choose(false)"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true"><path d="M18 9.5a1.5 1.5 0 11-3 0v-6a1.5 1.5 0 013 0v6zM14 9.667v-5.818a1.5 1.5 0 00-1.037-1.427L7.501.602A2.5 2.5 0 004.5 2.8v8.533a1.5 1.5 0 00.65 1.248L8.5 14.5v2A1.5 1.5 0 0010 18h1.5a1.5 1.5 0 001.5-1.5v-2.167l2.35-1.753A1.5 1.5 0 0016 11.333v-.166A1.5 1.5 0 0014 9.667z" /></svg>
        </button>
        <div
            v-if="showReason"
            class="otto-feedback-reason mt-1 w-full min-w-[14rem] basis-full"
        >
            <textarea
                v-model="comment"
                rows="2"
                maxlength="2000"
                placeholder="What went wrong? (optional)"
                class="w-full resize-none rounded-md border border-gray-200 bg-white px-2 py-1.5 text-[11px] text-gray-700 placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                :disabled="disabled || busy"
            ></textarea>
            <p v-if="error" class="mt-1 text-[11px] text-red-600 dark:text-red-400" role="alert">{{ error }}</p>
            <div class="mt-1 flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md bg-gray-200 px-2 py-0.5 text-[11px] font-medium text-gray-700 transition hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    :disabled="disabled || busy"
                    @click="submitReason"
                >Submit</button>
                <button
                    type="button"
                    class="text-[11px] text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                    :disabled="busy"
                    @click="showReason = false; error = ''"
                >Dismiss</button>
            </div>
        </div>
    </span>
</template>

<script setup>
import { ref, watch } from 'vue';
import { submitMessageFeedback } from '../lib/api.js';

const props = defineProps({
    conversationId: { type: String, required: true },
    messageId: { type: String, required: true },
    feedback: { type: Object, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update']);

const rating = ref(props.feedback?.rating ?? null);
const comment = ref(props.feedback?.comment ?? '');
const showReason = ref(false);
const error = ref('');
const busy = ref(false);

watch(() => props.feedback, (value) => {
    rating.value = value?.rating ?? null;
    comment.value = value?.comment ?? '';
});

async function choose(next) {
    if (props.disabled || busy.value) {
        return;
    }
    error.value = '';
    if (rating.value === next) {
        await save(null, null);

        return;
    }
    const saved = await save(next, next === false ? comment.value : null);
    if (saved && next === false) {
        showReason.value = true;
    }
}

async function submitReason() {
    const saved = await save(false, comment.value);
    if (saved) {
        showReason.value = false;
    }
}

async function save(nextRating, nextComment) {
    busy.value = true;
    error.value = '';
    try {
        const result = await submitMessageFeedback(props.conversationId, props.messageId, {
            rating: nextRating,
            comment: nextComment,
            surface: 'chat',
        });
        rating.value = result?.feedback?.rating ?? null;
        comment.value = result?.feedback?.comment ?? '';
        if (rating.value !== false) {
            showReason.value = false;
        }
        emit('update', result?.feedback ?? null);

        return true;
    } catch (e) {
        console.error('Otto message feedback failed', e);
        error.value = "Couldn't save feedback. Try again.";
        showReason.value = true;

        return false;
    } finally {
        busy.value = false;
    }
}
</script>
