<script setup>
import { computed } from 'vue';
import Icon from '@/Components/UI/Icon.vue';
import { toneClasses } from '@/Components/UI/tones';
import { formatDate } from '@/Utils/format';

/**
 * The 20-working-day countdown of the Action Officer, from the day receipt was acknowledged.
 * Calm while there is time, orange in the last five days, red once overdue. It shows only
 * while the clock runs (Processing and For PSED Chief Review).
 */
const props = defineProps({
    turnaround: { type: Object, default: null },
    /** The long form, with the target date, for the details dialog. */
    long: { type: Boolean, default: false },
});

const tone = computed(() => {
    const t = props.turnaround;
    if (!t) return 'neutral';
    if (t.overdue) return 'danger';
    return t.days_left <= 5 ? 'warning' : 'info';
});

const text = computed(() => {
    const t = props.turnaround;
    if (!t) return '';
    const n = Math.abs(t.days_left);
    const days = `${n} working day${n === 1 ? '' : 's'}`;
    if (t.overdue) return `${days} overdue`;
    return t.days_left === 0 ? 'Due today' : `${days} left`;
});
</script>

<template>
    <span
        v-if="turnaround && turnaround.running"
        class="badge"
        :class="toneClasses[tone]"
        :title="`Target date ${formatDate(turnaround.due)}`"
    >
        <Icon name="clock" size="sm" class="size-3.5" />{{ text }}<template v-if="long"> · target {{ formatDate(turnaround.due) }}</template>
    </span>
</template>
