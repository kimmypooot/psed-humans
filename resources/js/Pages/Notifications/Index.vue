<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { toneClasses } from '@/Components/UI/tones';
import { request } from '@/Utils/http';
import { dayLabel, metaFor, timeAgo } from '@/Utils/notifications';

/**
 * Everything the bell has told the person, newest first and grouped by day. Filter by unread or by what
 * kind of notice it is (needs you, progress, deadlines). A notice opens what it is about and is marked read.
 */
const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, required: true },
    unread: { type: Number, required: true },
    total: { type: Number, required: true },
});

const busy = ref(false);

const categories = [
    { value: '', label: 'Everything', icon: 'bell' },
    { value: 'action', label: 'Needs you', icon: 'arrowRight' },
    { value: 'progress', label: 'Progress', icon: 'checkCircle' },
    { value: 'deadline', label: 'Deadlines', icon: 'clock' },
];

const go = (query) => router.get('/notifications/all', Object.fromEntries(Object.entries(query).filter(([, v]) => v)), { preserveScroll: true, replace: true });
const unreadOnly = computed(() => props.filters.filter === 'unread');
const setCategory = (category) => go({ filter: props.filters.filter, category });
const toggleUnread = () => go({ filter: unreadOnly.value ? '' : 'unread', category: props.filters.category });

// Notices grouped under Today / Yesterday / a date, in the order they arrive.
const groups = computed(() => {
    const out = [];
    for (const item of props.items.data) {
        const label = dayLabel(item.at);
        const last = out[out.length - 1];
        if (last?.label === label) last.items.push(item);
        else out.push({ label, items: [item] });
    }
    return out;
});

const refresh = () => router.reload({ only: ['items', 'unread', 'total'], preserveScroll: true });

const open = async (item) => {
    if (!item.read) await request('POST', `/notifications/${item.id}/read`);
    if (item.url) router.visit(item.url);
    else refresh();
};

const markRead = async (item) => {
    await request('POST', `/notifications/${item.id}/read`);
    refresh();
};

const run = async (url) => {
    busy.value = true;
    await request('POST', url);
    busy.value = false;
    refresh();
};
</script>

<template>
    <Head title="Notifications" />

    <StaffLayout>
        <PageHeader title="Notifications" description="What needs you, what moved along and what is near its deadline." :breadcrumbs="[{ label: 'Notifications' }]">
            <template #actions>
                <Button variant="outline" :disabled="busy || !unread" @click="run('/notifications/read-all')"><Icon name="check" size="sm" />Mark all as read</Button>
                <Button variant="ghost" :disabled="busy || total === unread" @click="run('/notifications/clear-read')"><Icon name="trash" size="sm" />Clear read</Button>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Kind of notification">
                <button
                    v-for="c in categories"
                    :key="c.value"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:outline-none"
                    :class="(filters.category ?? '') === c.value ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-muted hover:border-primary/40 hover:text-text'"
                    :aria-pressed="(filters.category ?? '') === c.value"
                    @click="setCategory(c.value)"
                >
                    <Icon :name="c.icon" size="xs" />{{ c.label }}
                </button>
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-text">
                <input type="checkbox" :checked="unreadOnly" class="size-4 rounded border-border-strong text-primary focus:ring-focus-ring" @change="toggleUnread" />
                Unread only
                <span v-if="unread" class="rounded-full bg-danger px-2 py-0.5 text-xs font-semibold text-white">{{ unread }}</span>
            </label>
        </div>

        <div v-if="!items.data.length" class="rounded-xl border border-border bg-surface">
            <EmptyState
                :title="unreadOnly || filters.category ? 'Nothing matches' : 'You are all caught up'"
                :description="unreadOnly || filters.category ? 'Try another filter.' : 'New submissions, work routed to you, deadlines and decisions will show up here.'"
                icon="bell"
            />
        </div>

        <div v-else class="space-y-6">
            <section v-for="g in groups" :key="g.label" :aria-label="g.label">
                <h2 class="mb-2 text-xs font-semibold tracking-widest text-text-muted uppercase">{{ g.label }}</h2>
                <ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-surface">
                    <li v-for="item in g.items" :key="item.id" class="group relative flex items-start gap-3 px-4 py-4 transition-colors" :class="item.read ? 'hover:bg-surface-muted/60' : 'bg-info-bg/40 hover:bg-info-bg/70'">
                        <span v-if="!item.read" class="absolute inset-y-0 left-0 w-1 bg-primary" aria-hidden="true" />
                        <span class="mt-0.5 grid size-10 shrink-0 place-items-center rounded-full" :class="toneClasses[item.tone] ?? toneClasses.info" aria-hidden="true"><Icon :name="metaFor(item.kind).icon" size="sm" /></span>

                        <button type="button" class="min-w-0 flex-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="open(item)">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="text-sm text-text" :class="item.read ? 'font-medium' : 'font-semibold'">{{ item.title }}<span v-if="!item.read" class="sr-only"> (unread)</span></span>
                                <span class="rounded-md px-1.5 py-0.5 text-[11px] font-medium" :class="toneClasses[item.tone] ?? toneClasses.info">{{ metaFor(item.kind).label }}</span>
                            </span>
                            <span class="mt-1 block text-sm break-words text-text-muted">{{ item.body }}</span>
                            <span class="mt-2 flex flex-wrap items-center gap-x-3 text-xs text-text-muted">
                                <span v-if="item.tracking_code" class="font-mono">{{ item.tracking_code }}</span>
                                <time :datetime="item.at">{{ timeAgo(item.at) }}</time>
                            </span>
                        </button>

                        <button v-if="!item.read" type="button" class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-primary opacity-0 transition-opacity group-hover:opacity-100 hover:bg-info-bg focus:opacity-100 focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:outline-none max-sm:opacity-100" @click="markRead(item)">Mark read</button>
                    </li>
                </ul>
            </section>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-text-muted">{{ items.total.toLocaleString() }} {{ items.total === 1 ? 'notification' : 'notifications' }}<template v-if="unread"> · {{ unread }} unread</template></p>
            <Pagination :prev-url="items.prev_page_url" :next-url="items.next_page_url" :current-page="items.current_page" :last-page="items.last_page" />
        </div>
    </StaffLayout>
</template>
