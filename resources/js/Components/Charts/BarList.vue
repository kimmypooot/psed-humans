<script setup>
import { computed, onMounted, ref } from 'vue';

/**
 * Horizontal bars, one per row, largest first. When `approved` is present the bar is split: the
 * approved part in green, the rest in the brand blue. `rows`: { label, total, approved?, hint? }.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    single: { type: Boolean, default: false }, // plain bars, no approved split
});

const max = computed(() => Math.max(1, ...props.rows.map((r) => r.total)));
const drawn = ref(false);
onMounted(() => requestAnimationFrame(() => (drawn.value = true)));
const pct = (n) => (drawn.value ? `${(n / max.value) * 100}%` : '0%');
</script>

<template>
    <ul class="space-y-3">
        <li v-for="r in rows" :key="r.label" class="group">
            <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate font-medium text-text" :title="r.hint ?? r.label">{{ r.label }}</span>
                <span class="shrink-0 text-text-muted tabular-nums">
                    <b class="font-semibold text-text">{{ r.total.toLocaleString() }}</b>
                    <span v-if="!single && r.total" class="ml-1.5 text-xs">{{ Math.round(((r.approved ?? 0) / r.total) * 100) }}% approved</span>
                </span>
            </div>
            <div class="flex h-2.5 overflow-hidden rounded-full bg-surface-muted" role="img" :aria-label="`${r.label}: ${r.total}${single ? '' : `, ${r.approved ?? 0} approved`}`">
                <div v-if="!single" class="h-full bg-success transition-[width] duration-700 ease-out motion-reduce:transition-none" :style="{ width: pct(r.approved ?? 0) }" />
                <div class="h-full bg-chart-1 transition-[width] duration-700 ease-out motion-reduce:transition-none" :class="single ? 'rounded-full' : ''" :style="{ width: pct(single ? r.total : r.total - (r.approved ?? 0)) }" />
            </div>
        </li>
        <li v-if="!rows.length" class="py-6 text-center text-sm text-text-muted">Nothing to show for these filters.</li>
    </ul>
</template>
