<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
import { toneClasses } from './tones';

/**
 * A headline number: a label, a big value, an icon chip tinted by tone, and an optional line
 * underneath (a change from last month, or a plain hint). `delta` shows an up/down arrow.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    suffix: { type: String, default: null },
    icon: { type: String, default: 'chartBar' },
    tone: { type: String, default: 'info' },
    hint: { type: String, default: null },
    delta: { type: Number, default: null }, // change vs. the period before; null hides it
    goodWhenDown: { type: Boolean, default: false },
});

const deltaTone = computed(() => {
    if (!props.delta) return 'text-text-muted';
    return (props.delta > 0) !== props.goodWhenDown ? 'text-success' : 'text-danger';
});
</script>

<template>
    <div class="group relative overflow-hidden rounded-xl border border-border bg-surface p-5 transition duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md motion-reduce:transition-none motion-reduce:hover:translate-y-0">
        <span class="pointer-events-none absolute -top-10 -right-10 size-28 rounded-full bg-info-bg/60 transition-transform duration-300 group-hover:scale-125 motion-reduce:transition-none" aria-hidden="true" />
        <div class="relative flex items-start justify-between gap-3">
            <p class="text-sm font-medium text-text-muted">{{ label }}</p>
            <span class="grid size-9 shrink-0 place-items-center rounded-full" :class="toneClasses[tone]"><Icon :name="icon" size="sm" /></span>
        </div>
        <p class="relative mt-2 text-3xl leading-none font-semibold tracking-tight text-text tabular-nums">
            {{ typeof value === 'number' ? value.toLocaleString() : value }}<span v-if="suffix" class="ml-1 text-base font-medium text-text-muted">{{ suffix }}</span>
        </p>
        <p v-if="delta !== null" class="relative mt-2 flex items-center gap-1 text-xs font-medium" :class="deltaTone">
            <span aria-hidden="true">{{ delta > 0 ? '▲' : delta < 0 ? '▼' : '–' }}</span>
            {{ delta === 0 ? 'No change' : `${Math.abs(delta).toLocaleString()} ${delta > 0 ? 'more' : 'fewer'}` }} than last month
        </p>
        <p v-else-if="hint" class="relative mt-2 text-xs text-text-muted">{{ hint }}</p>
    </div>
</template>
