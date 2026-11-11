<script setup>
import { computed, onMounted, ref } from 'vue';

/**
 * A donut with the total in the middle and a legend beside it. Hovering a legend row or a slice
 * highlights it. `slices`: { label, total, color }.
 */
const props = defineProps({
    slices: { type: Array, required: true },
    centerLabel: { type: String, default: 'Total' },
});

const R = 70;
const C = 2 * Math.PI * R;
const total = computed(() => props.slices.reduce((s, x) => s + x.total, 0));
const arcs = computed(() => {
    let offset = 0;
    return props.slices
        .filter((s) => s.total > 0)
        .map((s) => {
            const len = (s.total / total.value) * C;
            const arc = { ...s, len, offset, pct: (s.total / total.value) * 100 };
            offset += len;
            return arc;
        });
});
const hover = ref(null);
const shown = computed(() => arcs.value.find((a) => a.label === hover.value));

const drawn = ref(false);
onMounted(() => requestAnimationFrame(() => (drawn.value = true)));
</script>

<template>
    <div class="flex flex-col items-center gap-6 sm:flex-row">
        <div class="relative size-44 shrink-0">
            <svg viewBox="0 0 180 180" class="size-full -rotate-90" role="img" :aria-label="`${centerLabel}: ${total}`">
                <circle cx="90" cy="90" :r="R" fill="none" stroke="var(--color-surface-muted)" stroke-width="22" />
                <circle
                    v-for="a in arcs"
                    :key="a.label"
                    cx="90"
                    cy="90"
                    :r="R"
                    fill="none"
                    :stroke="a.color"
                    :stroke-width="hover === a.label ? 26 : 22"
                    :stroke-dasharray="drawn ? `${Math.max(a.len - 2, 0)} ${C}` : `0 ${C}`"
                    :stroke-dashoffset="-a.offset"
                    class="cursor-pointer transition-all duration-700 ease-out motion-reduce:transition-none"
                    :class="hover && hover !== a.label ? 'opacity-40' : ''"
                    @mouseenter="hover = a.label"
                    @mouseleave="hover = null"
                />
            </svg>
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                <p class="text-3xl leading-none font-semibold text-text">{{ (shown ? shown.total : total).toLocaleString() }}</p>
                <p class="mt-1 max-w-24 text-xs leading-tight text-text-muted">{{ shown ? `${shown.label} · ${shown.pct.toFixed(0)}%` : centerLabel }}</p>
            </div>
        </div>

        <ul class="w-full min-w-0 flex-1 space-y-1">
            <li
                v-for="s in slices"
                :key="s.label"
                class="flex items-center gap-2.5 rounded-md px-2 py-1 text-sm transition-colors"
                :class="hover === s.label ? 'bg-surface-muted' : ''"
                @mouseenter="hover = s.label"
                @mouseleave="hover = null"
            >
                <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: s.color }" />
                <span class="min-w-0 flex-1 truncate text-text-muted">{{ s.label }}</span>
                <span class="font-semibold text-text tabular-nums">{{ s.total.toLocaleString() }}</span>
                <span class="w-10 text-right text-xs text-text-muted tabular-nums">{{ total ? Math.round((s.total / total) * 100) : 0 }}%</span>
            </li>
        </ul>
    </div>
</template>
