<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import { request } from '@/Utils/http';
import { toneClasses } from '@/Components/UI/tones';
import { metaFor, timeAgo } from '@/Utils/notifications';

/**
 * The bell in the header: how many notices are waiting, and the latest ones. A notice opens what it is
 * about (a mechanism waiting for the PSED Chief, say) and is marked read. It looks for new ones when the
 * page loads, every minute while the tab is showing, and when the tab comes back into view.
 */
const open = ref(false);
const unread = ref(0);
const items = ref([]);
const loaded = ref(false);
const rootRef = ref(null);
const buttonRef = ref(null);
let timer = null;

const load = async () => {
    const { ok, data } = await request('GET', '/notifications');

    if (ok && data) {
        unread.value = data.unread;
        items.value = data.items;
        loaded.value = true;
    }
};

const close = () => {
    open.value = false;
};

const toggle = () => {
    open.value = !open.value;
    if (open.value) load();
};

const go = async (item) => {
    close();
    if (!item.read) {
        item.read = true;
        unread.value = Math.max(0, unread.value - 1);
        request('POST', `/notifications/${item.id}/read`);
    }
    if (item.url) router.visit(item.url);
};

const readAll = async () => {
    const { ok } = await request('POST', '/notifications/read-all');

    if (ok) {
        unread.value = 0;
        items.value.forEach((item) => (item.read = true));
    }
};

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) close();
};

const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    const hadFocus = rootRef.value?.contains(document.activeElement);
    close();
    if (hadFocus) buttonRef.value?.focus();
};

const onVisible = () => {
    if (document.visibilityState === 'visible') load();
};

onMounted(() => {
    load();
    timer = window.setInterval(() => document.visibilityState === 'visible' && load(), 60_000);
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('visibilitychange', onVisible);
});

onBeforeUnmount(() => {
    window.clearInterval(timer);
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('visibilitychange', onVisible);
});
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            ref="buttonRef"
            type="button"
            class="relative rounded-md p-2 text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :aria-label="unread ? `Notifications, ${unread} unread` : 'Notifications'"
            :aria-expanded="open"
            aria-controls="notification-panel"
            @click="toggle"
        >
            <Icon name="bell" size="md" />
            <span v-if="unread" class="absolute top-0.5 right-0.5 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] leading-4 font-semibold text-white" aria-hidden="true">{{ unread > 9 ? '9+' : unread }}</span>
        </button>

        <div
            v-if="open"
            id="notification-panel"
            class="absolute right-0 z-50 mt-2 w-96 max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-xl border border-border bg-surface shadow-xl"
        >
            <div class="flex items-center justify-between border-b border-border px-3 py-2">
                <h2 class="text-sm font-semibold text-text">Notifications</h2>
                <button v-if="unread" type="button" class="text-xs text-primary underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="readAll">Mark all as read</button>
            </div>

            <p v-if="!loaded" class="px-3 py-4 text-sm text-text-muted">Loading…</p>
            <div v-else-if="!items.length" class="px-4 py-8 text-center">
                <span class="mx-auto grid size-10 place-items-center rounded-full bg-info-bg text-primary" aria-hidden="true"><Icon name="bell" size="md" /></span>
                <p class="mt-3 text-sm font-medium text-text">You are all caught up</p>
                <p class="mt-1 text-xs text-text-muted">New submissions, routed work and deadlines show up here.</p>
            </div>

            <ul v-else class="max-h-96 divide-y divide-border overflow-y-auto">
                <li v-for="item in items" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full gap-3 px-3 py-2.5 text-left transition-colors hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring"
                        :class="item.read ? '' : 'bg-info-bg/40'"
                        @click="go(item)"
                    >
                        <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full" :class="toneClasses[item.tone] ?? toneClasses.info" aria-hidden="true"><Icon :name="metaFor(item.kind).icon" size="sm" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-start justify-between gap-2">
                                <span class="block text-sm text-text" :class="item.read ? '' : 'font-semibold'">{{ item.title }}<span v-if="!item.read" class="sr-only"> (unread)</span></span>
                                <span v-if="!item.read" class="mt-1.5 size-2 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                            </span>
                            <span class="mt-0.5 line-clamp-2 block text-xs break-words text-text-muted">{{ item.body }}</span>
                            <span class="mt-1 block text-[11px] text-text-muted">{{ timeAgo(item.at) }}</span>
                        </span>
                    </button>
                </li>
            </ul>

            <Link href="/notifications/all" class="block border-t border-border px-3 py-2.5 text-center text-sm font-medium text-primary hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring" @click="close">See all notifications</Link>
        </div>
    </div>
</template>
