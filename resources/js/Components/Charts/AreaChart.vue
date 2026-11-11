<script setup>
import { computed, onMounted, ref } from 'vue';

/**
 * Two series over time: a soft area for what came in and a line for what was approved.
 * Hover (or focus a column with the keyboard) to read a month. The values are also in a hidden table for screen readers.
 */
const props = defineProps({
    points: { type: Array, required: true }, // { label, month, submitted, approved }
});

const W = 640;
const H = 250;
const pad = { top: 16, right: 12, bottom: 28, left: 34 };
const innerW = W - pad.left - pad.right;
const innerH = H - pad.top - pad.bottom;

const max = computed(() => {
    const m = Math.max(1, ...props.points.flatMap((p) => [p.submitted, p.approved]));
    const step = m <= 5 ? 1 : m <= 20 ? 5 : m <= 100 ? 20 : 50;
    return Math.ceil(m / step) * step;
});
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(max.value * f)));
const x = (i) => pad.left + (props.points.length === 1 ? innerW / 2 : (i / (props.points.length - 1)) * innerW);
const y = (v) => pad.top + innerH - (v / max.value) * innerH;

// A smooth curve through the points (Catmull-Rom turned into cubic Béziers).
const curve = (key) => {
    const pts = props.points.map((p, i) => [x(i), y(p[key])]);
    if (pts.length < 2) return '';
    let d = `M ${pts[0][0]} ${pts[0][1]}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] ?? pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] ?? p2;
        const t = 0.18;
        d += ` C ${p1[0] + (p2[0] - p0[0]) * t} ${p1[1] + (p2[1] - p0[1]) * t}, ${p2[0] - (p3[0] - p1[0]) * t} ${p2[1] - (p3[1] - p1[1]) * t}, ${p2[0]} ${p2[1]}`;
    }
    return d;
};
const submittedLine = computed(() => curve('submitted'));
const approvedLine = computed(() => curve('approved'));
const area = computed(() => (submittedLine.value ? `${submittedLine.value} L ${x(props.points.length - 1)} ${y(0)} L ${x(0)} ${y(0)} Z` : ''));

const active = ref(null);
const colW = computed(() => innerW / Math.max(1, props.points.length - 1));
const tip = computed(() => {
    if (active.value === null) return null;
    const p = props.points[active.value];
    const left = (x(active.value) / W) * 100;
    return { p, left: Math.min(88, Math.max(12, left)) };
});

const drawn = ref(false);
onMounted(() => requestAnimationFrame(() => (drawn.value = true)));
</script>

<template>
    <div class="relative">
        <svg :viewBox="`0 0 ${W} ${H}`" class="h-auto w-full overflow-visible" role="img" aria-label="Submitted and approved HR mechanisms per month, last 12 months">
            <defs>
                <linearGradient id="area-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="var(--color-csc-blue-light)" stop-opacity="0.32" />
                    <stop offset="100%" stop-color="var(--color-csc-blue-light)" stop-opacity="0.02" />
                </linearGradient>
            </defs>

            <g v-for="t in ticks" :key="t">
                <line :x1="pad.left" :x2="W - pad.right" :y1="y(t)" :y2="y(t)" stroke="var(--color-border)" stroke-dasharray="3 4" />
                <text :x="pad.left - 8" :y="y(t) + 4" text-anchor="end" class="fill-text-muted" font-size="11">{{ t }}</text>
            </g>

            <path :d="area" fill="url(#area-fill)" class="transition-opacity duration-700 motion-reduce:transition-none" :class="drawn ? 'opacity-100' : 'opacity-0'" />
            <path :d="submittedLine" fill="none" stroke="var(--color-csc-blue-light)" stroke-width="2.5" stroke-linecap="round" pathLength="1" class="line" :class="{ drawn }" />
            <path :d="approvedLine" fill="none" stroke="var(--color-success)" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="1 0" pathLength="1" class="line" :class="{ drawn }" />

            <g v-if="active !== null">
                <line :x1="x(active)" :x2="x(active)" :y1="pad.top" :y2="y(0)" stroke="var(--color-border-strong)" stroke-dasharray="2 3" />
                <circle :cx="x(active)" :cy="y(points[active].submitted)" r="5" fill="var(--color-surface)" stroke="var(--color-csc-blue-light)" stroke-width="2.5" />
                <circle :cx="x(active)" :cy="y(points[active].approved)" r="5" fill="var(--color-surface)" stroke="var(--color-success)" stroke-width="2.5" />
            </g>

            <text v-for="(p, i) in points" :key="p.month" :x="x(i)" :y="H - 8" text-anchor="middle" class="fill-text-muted" font-size="11">{{ p.label }}</text>

            <!-- One tall target per month. -->
            <rect
                v-for="(p, i) in points"
                :key="`hit-${p.month}`"
                :x="x(i) - colW / 2"
                :y="pad.top"
                :width="colW"
                :height="innerH"
                fill="transparent"
                tabindex="0"
                :aria-label="`${p.month}: ${p.submitted} submitted, ${p.approved} approved`"
                class="cursor-crosshair focus:outline-none"
                @mouseenter="active = i"
                @mouseleave="active = null"
                @focus="active = i"
                @blur="active = null"
            />
        </svg>

        <div v-if="tip" class="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-lg border border-border bg-surface px-3 py-2 text-xs shadow-lg" :style="{ left: `${tip.left}%` }">
            <p class="font-semibold text-text">{{ tip.p.month }}</p>
            <p class="mt-1 flex items-center gap-2 text-text-muted"><span class="size-2 rounded-full bg-chart-1" />Submitted <b class="ml-auto pl-3 text-text">{{ tip.p.submitted }}</b></p>
            <p class="mt-0.5 flex items-center gap-2 text-text-muted"><span class="size-2 rounded-full bg-success" />Approved <b class="ml-auto pl-3 text-text">{{ tip.p.approved }}</b></p>
        </div>

        <table class="sr-only">
            <caption>Submitted and approved per month</caption>
            <thead><tr><th>Month</th><th>Submitted</th><th>Approved</th></tr></thead>
            <tbody><tr v-for="p in points" :key="p.month"><td>{{ p.month }}</td><td>{{ p.submitted }}</td><td>{{ p.approved }}</td></tr></tbody>
        </table>
    </div>
</template>

<style scoped>
.line {
    stroke-dasharray: 1;
    stroke-dashoffset: 1;
    transition: stroke-dashoffset 1.1s ease-out;
}
.line.drawn {
    stroke-dashoffset: 0;
}
@media (prefers-reduced-motion: reduce) {
    .line {
        stroke-dashoffset: 0;
        transition: none;
    }
}
</style>
