<template>
    <div
        class="flex relative h-full gap-0"
        :class="{ 'flex-row': fullscreen }"
    >
        <div
            v-if="fullscreen && !historyCollapsed"
            id="otto-history-sidebar"
            data-otto-history-sidebar
            class="hidden md:flex md:w-72 flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700"
        >
            <div class="flex items-center justify-between gap-2 p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">History</h3>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        aria-label="Collapse history"
                        aria-expanded="true"
                        aria-controls="otto-history-sidebar"
                        title="Collapse history"
                        data-otto-history-collapse
                        @click="collapseHistory"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg bg-primary-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-600 transition"
                        data-otto-history-new="sidebar"
                        @click="startNewConversation"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        New
                    </button>
                </div>
            </div>
            <div class="px-2 pt-2">
                <input
                    v-model="conversationSearch"
                    type="text"
                    placeholder="Search history..."
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 placeholder-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                >
            </div>
            <div class="flex-1 overflow-y-auto p-2 space-y-1">
                <button
                    v-for="conversation in conversations"
                    :key="conversation.id"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition"
                    :class="conversationId === conversation.id
                        ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 font-medium'
                        : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
                    @click="openConversation(conversation.id)"
                >
                    <span class="min-w-0 flex-1 truncate text-left">{{ limitTitle(conversation.title) }}</span>
                </button>
                <p v-if="conversations.length === 0" class="px-3 py-6 text-center text-xs text-gray-400 dark:text-gray-500">No conversations yet</p>
            </div>
        </div>

        <div class="flex flex-1 flex-col bg-white dark:bg-gray-900 relative min-w-0">
            <div
                v-if="fullscreen && historyCollapsed"
                class="hidden md:flex shrink-0 items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-800 px-3 py-2"
                data-otto-history-collapsed-bar
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500"
                    aria-label="Expand history"
                    aria-expanded="false"
                    aria-controls="otto-history-sidebar"
                    title="Expand history"
                    data-otto-history-expand
                    @click="expandHistory"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    History
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg bg-primary-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-600 transition"
                    aria-label="New chat"
                    data-otto-history-new="chat"
                    @click="startNewConversation"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    New
                </button>
            </div>

            <div v-if="!fullscreen" class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-800 px-3 py-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-600 transition"
                    @click="startNewConversation(); historyOpen = false"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    New chat
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition hover:bg-gray-100 dark:hover:bg-gray-800"
                    :class="historyOpen ? 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200' : 'text-gray-500 dark:text-gray-400'"
                    aria-label="Conversation history"
                    @click="historyOpen = !historyOpen"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    History
                </button>
            </div>

            <div
                v-if="historyOpen && !fullscreen"
                class="absolute inset-0 z-30 flex flex-col bg-white dark:bg-gray-900"
            >
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-800 px-3 py-2">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">History</h3>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="Close history"
                        @click="historyOpen = false"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="px-3 pt-2">
                    <input
                        v-model="conversationSearch"
                        type="text"
                        placeholder="Search history..."
                        class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 placeholder-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                    >
                </div>
                <div class="flex-1 overflow-y-auto p-2 space-y-1">
                    <button
                        v-for="conversation in conversations"
                        :key="'compact-' + conversation.id"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition"
                        :class="conversationId === conversation.id
                            ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 font-medium'
                            : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="openConversation(conversation.id); historyOpen = false"
                    >
                        <span class="min-w-0 flex-1 truncate text-left">{{ limitTitle(conversation.title) }}</span>
                    </button>
                    <p v-if="conversations.length === 0" class="px-3 py-6 text-center text-xs text-gray-400 dark:text-gray-500">No conversations yet</p>
                </div>
            </div>

            <div
                ref="messagesEl"
                id="otto-messages"
                class="group/messages relative flex-1 overflow-y-auto px-5 py-4"
            >
                <div class="flex flex-col gap-4">
                    <div v-if="!hasMessages" class="flex flex-col items-center justify-center py-10 text-center">
                        <img :src="config.ottoAvatar" alt="Otto" class="h-16 w-16 rounded-full object-cover mb-4">
                        <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-1">Hello, I'm Otto!</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md">
                            I can help you search scan records, find hits, look up assignments, and answer product questions.
                            What would you like to look into?
                        </p>
                        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-2 w-full max-w-lg">
                            <button
                                v-for="starter in STARTER_PROMPTS"
                                :key="starter.prompt"
                                type="button"
                                class="rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
                                @click="submitPrompt(starter.prompt)"
                            >
                                <span class="block font-medium text-gray-700 dark:text-gray-300">{{ starter.label }}</span>
                                <span class="block text-xs">{{ starter.hint }}</span>
                            </button>
                        </div>
                    </div>

                    <template v-for="message in messages" :key="message.localId">
                        <SearchInsteadMarker v-if="message.role === 'event' && message.event === 'search_instead'" />
                        <div
                            v-else-if="assistantRowVisible(message)"
                            class="otto-msg group flex gap-2.5"
                            :class="message.role === 'user' ? 'flex-row-reverse' : ''"
                            :data-role="message.role"
                            :data-otto-inv="message.invocationId || undefined"
                            :data-otto-message-id="message.id || undefined"
                        >
                            <div class="mt-0.5 shrink-0">
                                <div
                                    v-if="message.role === 'user'"
                                    class="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-semibold text-white"
                                    :style="{ backgroundColor: message.author?.color || config.userColor }"
                                    :title="message.author?.name || ''"
                                >{{ message.author?.initials || config.userInitials }}</div>
                                <img v-else :src="config.ottoAvatar" alt="Otto" class="h-7 w-7 rounded-full object-cover">
                            </div>
                            <div
                                class="flex min-w-0 max-w-[85%] flex-col gap-1"
                                :class="message.role === 'user' ? 'items-end' : 'items-start'"
                                data-otto-body
                            >
                                <div
                                    v-if="message.role === 'user' || message.html"
                                    class="px-3.5 py-2 text-sm"
                                    :class="message.role === 'user'
                                        ? 'rounded-2xl rounded-tr-sm bg-primary-500 text-white'
                                        : 'rounded-2xl rounded-tl-sm bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100'"
                                >
                                    <div
                                        v-if="message.role === 'user'"
                                        class="otto-text whitespace-pre-wrap break-words"
                                    >{{ message.content }}</div>
                                    <div
                                        v-else
                                        class="otto-text prose prose-sm dark:prose-invert max-w-none break-words"
                                        v-html="message.html"
                                    ></div>
                                </div>

                                <template v-if="showStreamResultWidgets(message)">
                                    <template v-for="(widget, wIndex) in message.widgets" :key="widget.searchId + '-' + wIndex">
                                        <DriverMapWidget
                                            v-if="widget.type === 'scout_driver'"
                                            :search-id="widget.searchId"
                                            :title="widget.title || ''"
                                        />
                                        <ConvoyWidget
                                            v-else-if="widget.type === 'convoy'"
                                            :search-id="widget.searchId"
                                            :title="widget.title || ''"
                                        />
                                        <SearchWidget
                                            v-else
                                            :type="widget.type"
                                            :search-id="widget.searchId"
                                            :title="widget.title || ''"
                                        />
                                    </template>
                                </template>

                                <DetailWidget
                                    v-for="detail in message.details"
                                    :key="detail.resultType + '-' + detail.resultId"
                                    :type="detail.resultType"
                                    :record-id="detail.resultId"
                                />

                                <PageActionCard
                                    v-for="(action, aIndex) in message.pageActions"
                                    :key="action.actionId || aIndex"
                                    :action="action"
                                    :payload="pageActionView(action).payload"
                                    :rows="pageActionView(action).rows"
                                    :restored="action.restored === true"
                                    :conversation-id="conversationId"
                                    :thinking="thinking"
                                    @search-instead="onSearchInstead"
                                />

                                <div
                                    v-if="message.error"
                                    class="otto-error mt-1 w-full rounded-md bg-red-50 dark:bg-red-900/30 px-3 py-2 text-sm text-red-700 dark:text-red-300"
                                >{{ message.error }}</div>

                                <div
                                    v-if="message.role === 'assistant' && (message.html || message.id)"
                                    class="otto-meta flex flex-wrap items-center gap-2 px-1 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100"
                                >
                                    <button
                                        v-if="message.html"
                                        type="button"
                                        class="text-[11px] text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                                        @click="copyMessage(message, $event)"
                                    >{{ copiedId === message.localId ? 'Copied' : 'Copy' }}</button>
                                    <MessageFeedback
                                        v-if="message.id && conversationId"
                                        :conversation-id="conversationId"
                                        :message-id="message.id"
                                        :feedback="message.feedback"
                                        :disabled="thinking || streaming"
                                        @update="message.feedback = $event"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>

                    <div v-if="thinking && !streaming" class="mt-4 flex gap-2.5">
                        <img
                            v-if="!liveTurnAvatarVisible()"
                            :src="config.ottoAvatar"
                            alt="Otto"
                            class="mt-0.5 h-7 w-7 shrink-0 rounded-full object-cover"
                        >
                        <div v-else class="w-7 shrink-0" aria-hidden="true"></div>
                        <div class="flex items-center gap-2 rounded-2xl rounded-tl-sm bg-gray-100 dark:bg-gray-800 px-3.5 py-3 text-sm text-gray-500 dark:text-gray-400">
                            <span class="flex gap-1">
                                <span class="h-2 w-2 rounded-full bg-gray-400 dark:bg-gray-500 animate-bounce" style="animation-delay: 0ms"></span>
                                <span class="h-2 w-2 rounded-full bg-gray-400 dark:bg-gray-500 animate-bounce" style="animation-delay: 150ms"></span>
                                <span class="h-2 w-2 rounded-full bg-gray-400 dark:bg-gray-500 animate-bounce" style="animation-delay: 300ms"></span>
                            </span>
                            <span class="text-xs">{{ status }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 px-5 py-3">
                <form
                    class="flex items-end gap-2 rounded-xl border border-gray-300 bg-gray-50 px-3 py-2 transition focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                    @submit.prevent="submitPrompt()"
                >
                    <div class="relative min-w-0 flex-1">
                        <textarea
                            ref="inputEl"
                            v-model="prompt"
                            rows="1"
                            placeholder="Ask Otto something..."
                            autocomplete="off"
                            class="block max-h-40 min-h-6 w-full resize-none border-0 bg-transparent py-1 text-sm leading-5 text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-gray-200 dark:placeholder-gray-500"
                            @input="autoGrow(); onMentionInput()"
                            @keydown="onComposerKeydown"
                        ></textarea>
                        <div
                            v-if="mentionOpen"
                            class="absolute bottom-full left-0 right-0 mb-1 max-h-56 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg z-50"
                        >
                            <button
                                v-for="(item, index) in mentionSuggestions"
                                :key="item.type + ':' + item.value"
                                type="button"
                                class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50 transition"
                                :class="index === mentionIndex ? 'bg-primary-50 dark:bg-primary-900/20' : ''"
                                @click="pickMention(item)"
                                @mouseenter="mentionIndex = index"
                            >
                                <span
                                    class="inline-flex items-center justify-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase shrink-0"
                                    :class="item.type === 'plate' ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'"
                                >{{ item.type === 'plate' ? 'Plate' : 'Cam' }}</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200 truncate" :class="item.type === 'plate' ? 'font-mono' : ''">{{ item.label }}</span>
                                <span v-if="item.sub" class="ml-auto text-xs text-gray-400 dark:text-gray-500 shrink-0">{{ item.sub }}</span>
                            </button>
                        </div>
                    </div>
                    <button
                        v-if="!thinking"
                        type="submit"
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-500 text-white hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition"
                        aria-label="Send"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                    </button>
                    <button
                        v-else
                        type="button"
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-gray-600 transition hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        aria-label="Stop"
                        @click="stopGenerating"
                    >
                        <svg class="size-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 6h12v12H6z" /></svg>
                    </button>
                </form>
            </div>

            <div
                v-if="showDisclaimer"
                class="absolute inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
            >
                <div class="w-full max-w-md mx-4 rounded-xl bg-white dark:bg-gray-800 shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 pt-6 pb-4">
                        <div class="flex items-center gap-3 mb-4">
                            <img :src="config.ottoAvatar" alt="Otto" class="h-10 w-10 rounded-full object-cover">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Welcome to Otto</h3>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                            <span class="font-bold">Otto is currently in closed beta.</span>
                            Otto can search scans, hits, and assignments for your organization and answer product how-to questions.
                            Do not share credentials or secrets. Otto can make mistakes.
                        </p>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700">
                        <button
                            type="button"
                            class="w-full rounded-lg bg-primary-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition"
                            @click="onAcceptDisclaimer"
                        >
                            I understand
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="conversationId && fullscreen"
            data-otto-results-pane
            class="hidden xl:flex xl:w-1/2 min-h-0 flex-col border-l border-gray-200 dark:border-gray-700"
        >
            <OttoResultsPane :conversation-id="conversationId" />
        </div>

    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import OttoResultsPane from './OttoResultsPane.vue';
import SearchInsteadMarker from './components/SearchInsteadMarker.vue';
import SearchWidget from './components/widgets/SearchWidget.vue';
import ConvoyWidget from './components/widgets/ConvoyWidget.vue';
import DetailWidget from './components/widgets/DetailWidget.vue';
import DriverMapWidget from './components/widgets/DriverMapWidget.vue';
import PageActionCard from './components/widgets/PageActionCard.vue';
import MessageFeedback from './components/MessageFeedback.vue';
import {
    acceptDisclaimer,
    createConversation,
    currentPageContext,
    getConversation,
    listConversations,
    pollTurn,
    resetActiveConversation,
    sendMessage,
    stopTurn,
} from './lib/api.js';
import { getSessionEcho } from './lib/echo.js';
import { buildMentionItems, loadInvestigation } from './lib/investigation.js';
import { isToolInvocationNarration, prepareAssistantContent, stripToolCalls } from './lib/markdown.js';
import {
    computeSearchUpTo,
    isSearchInsteadPrompt,
    normalizePageAction,
    normalizePageActions,
    STARTER_PROMPTS,
    TOOL_STATUS,
} from './lib/page-actions.js';
import { limitTitle } from './lib/format.js';
import { mergePolledThread } from './lib/thread-order.js';

const props = defineProps({
    config: { type: Object, required: true },
    fullscreen: { type: Boolean, default: false },
});

const conversationId = ref(props.config.conversationId || null);
const messages = ref([]);
const conversations = ref([]);
const conversationSearch = ref('');
const historyOpen = ref(false);
const HISTORY_COLLAPSED_KEY = 'otto-history-collapsed';
const historyCollapsed = ref(readHistoryCollapsed());
const prompt = ref('');
const thinking = ref(false);
const streaming = ref(false);
const status = ref('Thinking…');
const hasMessages = ref(!!props.config.hasMessages);
const showDisclaimer = ref(!!props.config.showDisclaimer);
const disclaimerAccepted = ref(false);
const disclaimerChecked = ref(false);
const messagesEl = ref(null);
const inputEl = ref(null);
const copiedId = ref(null);

const mentionOpen = ref(false);
const mentionQuery = ref('');
const mentionStart = ref(0);
const mentionIndex = ref(0);
const mentionItems = ref([]);

const mentionSuggestions = computed(() => {
    if (!mentionItems.value.length) {
        return [];
    }
    if (mentionQuery.value.length === 0) {
        return mentionItems.value.slice(0, 8);
    }
    const q = mentionQuery.value.toLowerCase();

    return mentionItems.value.filter((i) => i.label.toLowerCase().includes(q)).slice(0, 8);
});

let lastSentPrompt = null;
let stopped = false;
let currentInvocationId = null;
let pendingWidgets = [];
let pendingSearchInstead = null;
let searchInsteadAwaitingStart = false;
let pollTimer = null;
let pollStartedAt = 0;
let searchTimer = null;
let threadConversationId = null;
let userChannelConv = null;
const seen = {};
const turns = {};

function newLocalId() {
    return 'local-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

function normalizeServerMessages(rows) {
    return (rows || []).map((row) => {
        if (row.role === 'event' || (row.role === 'user' && isSearchInsteadPrompt(row.content || ''))) {
            return {
                localId: row.id || newLocalId(),
                id: row.id || null,
                role: 'event',
                event: 'search_instead',
            };
        }

        const pageActions = normalizePageActions(row.page_actions ?? row.pageActions ?? []).map((a) => ({
            ...a,
            restored: true,
        }));
        const prepared = row.role === 'assistant'
            ? prepareAssistantContent(row.content || '')
            : { html: '', text: row.content || '', widgets: [] };

        return {
            localId: row.id || newLocalId(),
            id: row.id || null,
            role: row.role,
            content: row.content || '',
            html: row.role === 'assistant' ? prepared.html : '',
            widgets: prepared.widgets,
            details: [],
            pageActions,
            feedback: row.feedback ?? null,
            error: null,
            invocationId: null,
            author: row.role === 'user'
                ? { initials: props.config.userInitials, color: props.config.userColor }
                : null,
        };
    }).filter((message) => {
        if (message.role !== 'assistant') {
            return true;
        }

        return message.html !== '' || message.widgets.length > 0 || message.pageActions.length > 0 || message.details.length > 0;
    });
}

function pageActionView(action) {
    const cards = [];
    messages.value.forEach((message) => {
        (message.pageActions || []).forEach((item) => {
            cards.push(item);
        });
    });
    const index = cards.findIndex((item) => item === action || (item.actionId && item.actionId === action.actionId));

    return computeSearchUpTo(cards, index < 0 ? cards.length - 1 : index);
}

function scrollToBottom() {
    nextTick(() => {
        const el = messagesEl.value;
        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    });
}

function autoGrow() {
    const el = inputEl.value;
    if (!el) {
        return;
    }
    el.style.height = '0px';
    el.style.height = `${el.scrollHeight}px`;
}

async function refreshHistory() {
    try {
        const result = await listConversations(conversationSearch.value);
        conversations.value = result?.conversations || [];
        if (!disclaimerAccepted.value && typeof result?.showDisclaimer === 'boolean') {
            showDisclaimer.value = result.showDisclaimer;
        }
        disclaimerChecked.value = true;

        return result;
    } catch {
        conversations.value = [];
        disclaimerChecked.value = true;

        return null;
    }
}

let switchingConversation = false;

function dismissOttoPopups() {
    window.dispatchEvent(new CustomEvent('otto-dismiss-popups'));
}

function resumeOttoPopups() {
    if (historyOpen.value || switchingConversation) {
        return;
    }
    window.dispatchEvent(new CustomEvent('otto-popups-resume'));
}

async function openConversation(id) {
    switchingConversation = true;
    dismissOttoPopups();
    stopTurnPoll();
    clearPendingSearchInstead();
    thinking.value = false;
    streaming.value = false;
    stopped = false;
    currentInvocationId = null;
    pendingWidgets = [];
    Object.keys(turns).forEach((key) => delete turns[key]);
    Object.keys(seen).forEach((key) => delete seen[key]);
    lastSentPrompt = null;

    try {
        const result = await getConversation(id);
        conversationId.value = result.conversationId || result.id || id;
        messages.value = normalizeServerMessages(result.messages || []);
        hasMessages.value = result.hasMessages ?? messages.value.length > 0;
        subscribeThread(conversationId.value);
        await loadInvestigation(conversationId.value);
        refreshMentionItems();
        scrollToBottom();
    } catch (e) {
        console.error('Failed to load Otto conversation', e);
    } finally {
        switchingConversation = false;
        await nextTick();
        resumeOttoPopups();
    }
}

function readHistoryCollapsed() {
    try {
        return localStorage.getItem(HISTORY_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function writeHistoryCollapsed(collapsed) {
    try {
        localStorage.setItem(HISTORY_COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch {
        /* storage may be unavailable */
    }
}

function collapseHistory() {
    historyCollapsed.value = true;
}

function expandHistory() {
    historyCollapsed.value = false;
}

function startNewConversation() {
    dismissOttoPopups();
    const leaving = conversationId.value;
    stopTurnPoll();
    leaveChannels();
    conversationId.value = null;
    resetActiveConversation(leaving).catch((e) => {
        console.error('Failed to clear the active Otto conversation', e);
    });
    messages.value = [];
    prompt.value = '';
    hasMessages.value = false;
    thinking.value = false;
    streaming.value = false;
    stopped = false;
    currentInvocationId = null;
    pendingWidgets = [];
    lastSentPrompt = null;
    clearPendingSearchInstead();
    nextTick(() => resumeOttoPopups());
}

function disclaimerBlocksSend() {
    if (disclaimerAccepted.value) {
        return false;
    }
    if (!disclaimerChecked.value) {
        return true;
    }

    return showDisclaimer.value;
}

function submitPrompt(preset) {
    if (disclaimerBlocksSend()) {
        return;
    }
    const text = (preset ?? prompt.value ?? '').toString().trim();
    if (text === '' || thinking.value || streaming.value) {
        return;
    }

    thinking.value = true;
    mentionOpen.value = false;
    appendUserBubble(text);
    dispatchPrompt(text);
}

function submitSearchInsteadPrompt(text) {
    if (disclaimerBlocksSend()) {
        return false;
    }
    const value = (text ?? '').toString().trim();
    if (value === '' || thinking.value) {
        return false;
    }

    mentionOpen.value = false;
    messages.value.push({
        localId: newLocalId(),
        id: null,
        role: 'event',
        event: 'search_instead',
    });
    dispatchPrompt(value);

    return true;
}

function onSearchInstead({ prompt: text, queued, finish }) {
    if (queued) {
        pendingSearchInstead = { prompt: text, finish };

        return;
    }
    if (!submitSearchInsteadPrompt(text)) {
        finish?.(false);

        return;
    }
    finish?.(true);
}

function flushPendingSearchInstead() {
    const pending = pendingSearchInstead;
    if (!pending) {
        return;
    }
    pendingSearchInstead = null;
    if (!submitSearchInsteadPrompt(pending.prompt)) {
        pendingSearchInstead = pending;

        return;
    }
    searchInsteadAwaitingStart = true;
    pending.finish?.(true);
}

function clearPendingSearchInstead() {
    pendingSearchInstead = null;
    searchInsteadAwaitingStart = false;
}

function appendUserBubble(text, author = null) {
    messages.value.push({
        localId: newLocalId(),
        id: null,
        role: 'user',
        content: text,
        html: '',
        widgets: [],
        details: [],
        pageActions: [],
        author: author || { initials: props.config.userInitials, color: props.config.userColor },
    });
    hasMessages.value = true;
}

function dispatchPrompt(text) {
    hasMessages.value = true;
    thinking.value = true;
    streaming.value = false;
    status.value = 'Thinking…';
    lastSentPrompt = text;
    stopped = false;
    currentInvocationId = null;
    pendingWidgets = [];
    prompt.value = '';
    nextTick(() => {
        autoGrow();
        inputEl.value?.focus();
        scrollToBottom();
    });

    const pageContext = currentPageContext();
    const request = conversationId.value
        ? sendMessage(conversationId.value, { message: text, pageContext })
        : createConversation({ message: text, pageContext });

    Promise.resolve(request).then((result) => {
        const id = result?.conversationId || result?.id;
        if (id) {
            conversationId.value = id;
            subscribeThread(id);
            loadInvestigation(id);
            startTurnPoll();
            refreshHistory();
        }
    }).catch((e) => {
        console.error('Otto send failed', e);
        thinking.value = false;
        streaming.value = false;
    });
}

function startTurnPoll() {
    stopTurnPoll();
    pollStartedAt = Date.now();
    pollTimer = setInterval(() => {
        pollTurnFromServer();
    }, 1500);
}

function stopTurnPoll() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function pollTurnFromServer() {
    if (!thinking.value && !streaming.value) {
        stopTurnPoll();

        return;
    }
    if (!conversationId.value) {
        return;
    }
    if (Date.now() - pollStartedAt > 180000) {
        stopTurnPoll();
        finalizeStream();

        return;
    }
    try {
        const result = await pollTurn(conversationId.value);
        if (!result || result.status === 'pending' || result.status === 'idle') {
            return;
        }
        if (result.status === 'complete') {
            applyPolledTurn(result);

            return;
        }
    } catch {
        // Keep polling — a single blip should not leave Thinking… stuck.
    }
}

function applyPolledTurn(result) {
    stopTurnPoll();
    const polled = normalizeServerMessages(result.messages || []);
    if (polled.length) {
        messages.value = mergePolledThread(messages.value, polled);
        hasMessages.value = result.hasMessages ?? messages.value.length > 0;
    }
    if (conversationId.value) {
        loadInvestigation(conversationId.value);
    }
    thinking.value = false;
    streaming.value = false;
    scrollToBottom();
}

function stopGenerating() {
    stopTurnPoll();
    stopped = true;
    flushBufferedReply();
    finalizeStream();
    if (conversationId.value) {
        Promise.resolve(stopTurn(conversationId.value)).catch(() => {});
    }
}

function firstApply(e) {
    const id = e?.id;
    if (!id) {
        return true;
    }
    if (seen[id]) {
        return false;
    }
    seen[id] = true;

    return true;
}

function softTurn(invocationId) {
    const key = invocationId || 'pending';
    if (!turns[key]) {
        turns[key] = { raw: '', preToolRaw: '', hadToolCall: false, messageId: null, localId: null };
    }

    return turns[key];
}

function ensureTurn(invocationId) {
    const key = invocationId || currentInvocationId || 'pending';
    const turn = softTurn(key);
    if (turn.localId) {
        const existing = messages.value.find((m) => m.localId === turn.localId);
        if (existing) {
            return existing;
        }
    }
    const existing = messages.value.find((m) => m.invocationId === key);
    if (existing) {
        turn.localId = existing.localId;

        return existing;
    }

    const message = {
        localId: newLocalId(),
        id: null,
        role: 'assistant',
        content: '',
        html: '',
        widgets: [],
        details: [],
        pageActions: [],
        error: null,
        invocationId: key,
    };
    messages.value.push(message);
    turn.localId = message.localId;
    hasMessages.value = true;

    return message;
}

function onStart(e) {
    if (stopped || !firstApply(e)) {
        return;
    }
    searchInsteadAwaitingStart = false;
    thinking.value = true;
    currentInvocationId = e?.invocation_id ?? currentInvocationId;
    flushPendingWidgets();
    scrollToBottom();
}

function onDelta(e) {
    if (stopped || !firstApply(e)) {
        return;
    }
    const inv = e?.invocation_id ?? currentInvocationId;
    currentInvocationId = inv;
    flushPendingWidgets();
    const turn = softTurn(inv);
    const messageId = e?.message_id ?? null;
    if (messageId !== null && turn.messageId !== null && messageId !== turn.messageId) {
        turn.raw = '';
        turn.hadToolCall = false;
    }
    turn.messageId = messageId;
    turn.raw += e?.delta ?? '';
    thinking.value = true;
    streaming.value = false;
}

function onStatus(e) {
    if (stopped || !firstApply(e)) {
        return;
    }
    const inv = e?.invocation_id ?? currentInvocationId;
    currentInvocationId = inv;
    flushPendingWidgets();
    const turn = softTurn(inv);
    if ((turn.raw ?? '').trim() !== '') {
        turn.preToolRaw = turn.raw;
    }
    turn.hadToolCall = true;
    turn.raw = '';
    status.value = TOOL_STATUS[e?.tool_name] ?? 'Working…';
    thinking.value = true;
    streaming.value = false;
    scrollToBottom();
}

function onWidget(e) {
    if (stopped) {
        return;
    }
    if (!currentInvocationId) {
        if (thinking.value) {
            pendingWidgets.push(e);
        }

        return;
    }
    attachWidget(e);
}

function flushPendingWidgets() {
    if (!currentInvocationId || pendingWidgets.length === 0) {
        return;
    }
    const queued = pendingWidgets;
    pendingWidgets = [];
    queued.forEach((event) => attachWidget(event));
}

function showStreamResultWidgets(message) {
    if (!thinking.value || message?.role !== 'assistant') {
        return true;
    }

    const liveId = currentInvocationId || 'pending';

    return String(message.invocationId || '') !== String(liveId);
}

function assistantRowVisible(message) {
    if (message?.role !== 'assistant') {
        return true;
    }
    if (message.html || message.error) {
        return true;
    }
    if ((message.details || []).length > 0 || (message.pageActions || []).length > 0) {
        return true;
    }

    return (message.widgets || []).length > 0 && showStreamResultWidgets(message);
}

function liveTurnAvatarVisible() {
    if (!thinking.value) {
        return false;
    }

    const liveId = currentInvocationId || 'pending';

    return messages.value.some((message) => {
        return message.role === 'assistant'
            && String(message.invocationId || '') === String(liveId)
            && assistantRowVisible(message);
    });
}

function attachWidget(e) {
    const message = ensureTurn(currentInvocationId);
    const searchId = e?.search_id ? String(e.search_id) : '';
    if (searchId && message.widgets.some((w) => w.searchId === searchId)) {
        return;
    }
    message.widgets.push({
        type: e?.widget_type || 'scan',
        searchId,
        title: e?.title || '',
    });
    scrollToBottom();
}

function onDetail(e) {
    if (stopped) {
        return;
    }
    const message = ensureTurn(currentInvocationId);
    const resultId = e?.result_id ?? e?.record_id;
    const resultType = e?.result_type ?? e?.detail_type ?? '';
    if (resultId && message.details.some((d) => String(d.resultId) === String(resultId) && d.resultType === resultType)) {
        return;
    }
    message.details.push({ resultType, resultId });
    scrollToBottom();
}

function onPageAction(e) {
    if (stopped || !e) {
        return;
    }
    const message = ensureTurn(currentInvocationId);
    const action = normalizePageAction(e);
    if (!action) {
        return;
    }
    if (action.actionId && message.pageActions.some((a) => a.actionId === action.actionId)) {
        return;
    }
    message.pageActions.push(action);
    scrollToBottom();
}

function onEnd(e) {
    if (stopped || !firstApply(e)) {
        return;
    }
    const inv = e?.invocation_id ?? currentInvocationId;
    currentInvocationId = inv;
    flushPendingWidgets();
    const turn = softTurn(inv);
    const reason = (e?.reason ?? '').toString().toLowerCase().replaceAll('_', '-');
    const toolsStillRunning = turn.hadToolCall && (reason === 'tool-calls' || reason === 'tool-use');

    if (toolsStillRunning) {
        turn.raw = '';
        turn.hadToolCall = false;
        thinking.value = true;
        streaming.value = false;
        scrollToBottom();

        return;
    }

    paintTurnEnd(turn, inv);
}

function paintTurnEnd(turn, invocationId) {
    const pending = stripToolCalls(turn.raw ?? '');
    if (pending === '' && (turn.preToolRaw ?? '').trim()) {
        turn.raw = turn.preToolRaw;
    }
    turn.hadToolCall = false;
    turn.preToolRaw = '';

    let clean = stripToolCalls(turn.raw ?? '');
    if (isToolInvocationNarration(clean)) {
        clean = '';
    }
    const prepared = prepareAssistantContent(clean);
    if (clean !== '' || turn.localId) {
        const message = ensureTurn(invocationId);
        message.html = prepared.html;
        message.content = clean;
        prepared.widgets.forEach((widget) => {
            if (!message.widgets.some((w) => w.searchId === widget.searchId)) {
                message.widgets.push(widget);
            }
        });
    }
    thinking.value = false;
    streaming.value = false;
    stopTurnPoll();
    nextTick(() => flushPendingSearchInstead());
    scrollToBottom();
}

function finalizeHungTurnAfterRun() {
    if (!thinking.value && !streaming.value) {
        return;
    }
    if (searchInsteadAwaitingStart) {
        return;
    }
    const inv = currentInvocationId;
    if (!inv) {
        finalizeStream();

        return;
    }
    paintTurnEnd(softTurn(inv), inv);
}

function onError(e) {
    const message = ensureTurn(currentInvocationId);
    message.error = e?.message ?? 'Otto ran into a problem. Please try again.';
    scrollToBottom();
    finalizeStream();
}

function finalizeStream() {
    stopTurnPoll();
    thinking.value = false;
    streaming.value = false;
    currentInvocationId = null;
    pendingWidgets = [];
    nextTick(() => flushPendingSearchInstead());
}

function flushBufferedReply() {
    const inv = currentInvocationId;
    if (!inv) {
        return;
    }
    const turn = turns[inv];
    if (!turn || turn.hadToolCall) {
        return;
    }
    const clean = stripToolCalls(turn.raw ?? '');
    if (clean === '' || isToolInvocationNarration(clean)) {
        return;
    }
    const message = ensureTurn(inv);
    const prepared = prepareAssistantContent(clean);
    message.html = prepared.html;
    message.content = clean;
    scrollToBottom();
}

function isOwnUserPrompt(content) {
    return lastSentPrompt && content === lastSentPrompt;
}

function onUserMessage(e) {
    const content = e.content ?? '';
    if (isOwnUserPrompt(content)) {
        hasMessages.value = true;
        stopped = false;
        thinking.value = true;
        streaming.value = false;
        status.value = 'Thinking…';
        startTurnPoll();
        scrollToBottom();

        return;
    }
    if (isSearchInsteadPrompt(content)) {
        messages.value.push({
            localId: newLocalId(),
            id: null,
            role: 'event',
            event: 'search_instead',
        });
    } else {
        appendUserBubble(content, {
            name: e.author_name,
            initials: e.author_initials,
            color: e.author_color,
        });
    }
    hasMessages.value = true;
    stopped = false;
    thinking.value = true;
    streaming.value = false;
    status.value = 'Thinking…';
    startTurnPoll();
    scrollToBottom();
}

function stampAssistantMessageId(invocationId, messageId) {
    if (!invocationId || !messageId) {
        return;
    }
    const message = messages.value.find((m) => m.invocationId === invocationId);
    if (message) {
        message.id = messageId;
    }
}

function discardTurn(invocationId) {
    if (!invocationId) {
        return;
    }
    const id = String(invocationId);
    messages.value = messages.value.filter((m) => m.invocationId !== id);
    delete turns[id];
    if (currentInvocationId === id) {
        currentInvocationId = null;
    }
    thinking.value = true;
    streaming.value = false;
    startTurnPoll();
    scrollToBottom();
}

function replaceTurn(invocationId, content) {
    if (!invocationId || typeof content !== 'string') {
        return;
    }
    const message = ensureTurn(String(invocationId));
    const prepared = prepareAssistantContent(content);
    message.content = content;
    message.html = prepared.html;
    thinking.value = false;
    streaming.value = false;
    scrollToBottom();
}

function leaveChannels() {
    const echo = getSessionEcho();
    if (!echo) {
        return;
    }
    if (threadConversationId) {
        echo.leave(`otto-thread.${threadConversationId}`);
        threadConversationId = null;
    }
    if (userChannelConv && props.config.userId) {
        echo.leave(`otto.${props.config.userId}.${userChannelConv}`);
        userChannelConv = null;
    }
}

function subscribeThread(id) {
    const echo = getSessionEcho();
    if (!echo || !id) {
        return;
    }
    if (id === threadConversationId) {
        return;
    }

    if (threadConversationId) {
        echo.leave(`otto-thread.${threadConversationId}`);
    }
    echo.leave(`otto-thread.${id}`);
    threadConversationId = id;
    conversationId.value = id;

    if (props.config.userId) {
        if (userChannelConv) {
            echo.leave(`otto.${props.config.userId}.${userChannelConv}`);
        }
        echo.leave(`otto.${props.config.userId}.${id}`);
        userChannelConv = id;
        echo.private(`otto.${props.config.userId}.${id}`)
            .listen('.otto.status', (e) => {
                status.value = e.status ?? status.value;
                thinking.value = true;
            })
            .listen('.otto.investigation.updated', () => {
                loadInvestigation(id);
                window.Livewire?.dispatch('otto-investigation-changed');
                window.dispatchEvent(new CustomEvent('otto-investigation-changed'));
                finalizeHungTurnAfterRun();
                graftWidgetsFromServer();
                refreshHistory();
            })
            .listen('.otto.assistant_message_persisted', (e) => {
                stampAssistantMessageId(e?.invocation_id, e?.message_id);
            })
            .listen('.otto.reply_discarded', (e) => {
                discardTurn(e?.invocation_id);
            });
    }

    echo.private(`otto-thread.${id}`)
        .listen('.otto.user_message', (e) => onUserMessage(e))
        .listen('.stream_start', (e) => onStart(e))
        .listen('.text_delta', (e) => onDelta(e))
        .listen('.tool_call', (e) => onStatus(e))
        .listen('.otto.widget', (e) => onWidget(e))
        .listen('.otto.detail', (e) => onDetail(e))
        .listen('.otto.page_action', (e) => onPageAction(e))
        .listen('.otto.reply_replaced', (e) => replaceTurn(e?.invocation_id, e?.content))
        .listen('.otto.assistant_message_persisted', (e) => {
            stampAssistantMessageId(e?.invocation_id, e?.message_id);
        })
        .listen('.stream_end', (e) => onEnd(e))
        .listen('.otto.stopped', () => {
            stopped = true;
            flushBufferedReply();
            finalizeStream();
        })
        .listen('.otto.error', (e) => onError(e));
}

async function graftWidgetsFromServer() {
    if (!conversationId.value) {
        return;
    }
    try {
        const result = await pollTurn(conversationId.value);
        if (result?.messages?.length) {
            messages.value = mergePolledThread(messages.value, normalizeServerMessages(result.messages));
            scrollToBottom();
        }
    } catch {
        // Hang-net only.
    }
}

function refreshMentionItems() {
    mentionItems.value = buildMentionItems();
}

function onMentionInput() {
    const input = inputEl.value;
    if (!input) {
        return;
    }
    const before = prompt.value.substring(0, input.selectionStart);
    const atIdx = before.lastIndexOf('@');
    if (atIdx !== -1 && !before.substring(atIdx + 1).includes(' ')) {
        mentionStart.value = atIdx;
        mentionQuery.value = before.substring(atIdx + 1);
        mentionOpen.value = mentionSuggestions.value.length > 0;
        mentionIndex.value = 0;
    } else {
        mentionOpen.value = false;
        mentionQuery.value = '';
    }
}

function onMentionKeydown(e) {
    if (!mentionOpen.value) {
        return false;
    }
    const list = mentionSuggestions.value;
    if (!list.length) {
        return false;
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        mentionIndex.value = Math.min(mentionIndex.value + 1, list.length - 1);

        return true;
    }
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        mentionIndex.value = Math.max(mentionIndex.value - 1, 0);

        return true;
    }
    if (e.key === 'Tab' || (e.key === 'Enter' && !e.shiftKey)) {
        e.preventDefault();
        pickMention(list[mentionIndex.value]);

        return true;
    }
    if (e.key === 'Escape') {
        mentionOpen.value = false;

        return true;
    }

    return false;
}

function pickMention(item) {
    const input = inputEl.value;
    if (!input || !item) {
        return;
    }
    const after = prompt.value.substring(input.selectionStart);
    prompt.value = `${prompt.value.substring(0, mentionStart.value)}${item.value} ${after}`;
    mentionOpen.value = false;
    mentionQuery.value = '';
    nextTick(() => {
        autoGrow();
        const caret = mentionStart.value + item.value.length + 1;
        input.setSelectionRange(caret, caret);
        input.focus();
    });
}

function onComposerKeydown(e) {
    if (onMentionKeydown(e)) {
        return;
    }
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitPrompt();
    }
}

function copyMessage(message, event) {
    const text = message.content || event.target.closest('.otto-msg')?.querySelector('.otto-text')?.innerText || '';
    navigator.clipboard?.writeText(text);
    copiedId.value = message.localId;
    setTimeout(() => {
        if (copiedId.value === message.localId) {
            copiedId.value = null;
        }
    }, 1500);
}

async function onAcceptDisclaimer() {
    try {
        await acceptDisclaimer(null);
    } catch (e) {
        console.error('Failed to accept Otto disclaimer', e);

        return;
    }
    disclaimerAccepted.value = true;
    showDisclaimer.value = false;
}

watch(historyCollapsed, (collapsed) => {
    writeHistoryCollapsed(collapsed);
});

watch(historyOpen, (open) => {
    window.dispatchEvent(new CustomEvent('otto-history-open', { detail: open }));
    if (open) {
        mentionOpen.value = false;
        dismissOttoPopups();

        return;
    }
    if (switchingConversation) {
        return;
    }
    dismissOttoPopups();
    nextTick(() => resumeOttoPopups());
});

watch(conversationSearch, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => refreshHistory(), 300);
});

watch(thinking, (value) => {
    if (value) {
        scrollToBottom();
    }
});

onMounted(async () => {
    refreshMentionItems();
    window.addEventListener('otto-investigation-loaded', refreshMentionItems);
    const listed = await refreshHistory();
    const bootId = listed
        ? (listed.activeConversationId || null)
        : (conversationId.value || null);
    if (bootId) {
        await openConversation(bootId);
    }
});

onBeforeUnmount(() => {
    stopTurnPoll();
    leaveChannels();
    window.removeEventListener('otto-investigation-loaded', refreshMentionItems);
    clearTimeout(searchTimer);
});

defineExpose({ startNewConversation });
</script>
