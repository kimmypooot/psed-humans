<script setup>
import { computed } from 'vue';
import { formatDateTime } from '@/Utils/format';

/**
 * Everything that happened to a mechanism, newest first, as a vertical timeline. A step that
 * went back to the agency is red; an approval is green; the rest are blue or orange.
 */
const props = defineProps({
    history: { type: Array, required: true },
});

const dot = {
    submitted: 'bg-info',
    resubmitted: 'bg-info',
    routed: 'bg-primary',
    acknowledged: 'bg-primary',
    forwarded: 'bg-primary',
    returned: 'bg-danger',
    email_failed: 'bg-danger',
    reviewed: 'bg-warning',
    approved: 'bg-success',
};

const items = computed(() => [...props.history].reverse());
</script>

<template>
    <ol v-if="items.length" class="relative space-y-5 before:absolute before:top-1.5 before:bottom-1.5 before:left-[0.4375rem] before:w-0.5 before:bg-border">
        <li v-for="e in items" :key="e.id" class="relative pl-7">
            <span class="absolute top-1 left-0 size-4 rounded-full ring-4 ring-surface" :class="dot[e.action] ?? 'bg-border-strong'" aria-hidden="true" />
            <p class="text-sm font-semibold text-text">{{ e.title }}</p>
            <p class="text-xs text-text-muted">
                <template v-if="e.by">{{ e.by }} · </template><time :datetime="e.at">{{ formatDateTime(e.at) }}</time>
            </p>
            <p v-if="e.remarks" class="mt-1.5 rounded-md bg-surface-muted px-3 py-2 text-sm whitespace-pre-line text-text">{{ e.remarks }}</p>
        </li>
    </ol>
    <p v-else class="text-sm text-text-muted">No history was recorded for this one. It was brought over from the old monitoring workbook.</p>
</template>
