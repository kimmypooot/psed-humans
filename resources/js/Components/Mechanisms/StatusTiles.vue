<script setup>
import { toneClasses } from '@/Components/UI/tones';
import { breakable } from '@/Utils/format';

/**
 * One tile per status with its count: the same colours as the badges. Four to a row on a wide screen
 * (two on a phone); the odd last tile stretches across so no row is left with a gap.
 */
defineProps({
    counts: { type: Array, required: true },
});
</script>

<template>
    <ul class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 [&>li:last-child]:col-span-2" aria-label="HR mechanisms by status">
        <li v-for="c in counts" :key="c.status" class="min-w-0 rounded-lg border border-border bg-surface p-4">
            <p class="text-2xl font-semibold text-text tabular-nums">{{ c.total.toLocaleString() }}</p>
            <span class="badge mt-2 max-w-full text-left leading-snug" :class="toneClasses[c.tone]">{{ breakable(c.status) }}</span>
        </li>
    </ul>
</template>
