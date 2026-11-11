<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { formatDate } from '@/Utils/format';

/**
 * The four stops of an HR mechanism: Submitted, PSED review, For signature, Approved.
 *
 * It lays itself out by the width of the box it sits in, not the screen (a container query),
 * so the same stepper runs across a wide page and stacks down the side of a narrow modal.
 * A stop is done (green), current (blue, with a soft pulse), returned (red: the agency has to
 * act) or upcoming (outline). State is also written out for screen readers, never colour alone.
 */
defineProps({
    steps: { type: Array, required: true },
});

const circle = {
    done: 'bg-success text-white',
    current: 'bg-primary text-white',
    returned: 'bg-danger text-white',
    upcoming: 'border-2 border-border-strong bg-surface text-text-muted',
};
const title = {
    done: 'text-text',
    current: 'text-primary',
    returned: 'text-danger',
    upcoming: 'text-text-muted',
};
const spoken = { done: 'Done', current: 'In progress', returned: 'Returned, needs action', upcoming: 'Not yet' };
</script>

<template>
    <div class="@container">
        <ol class="grid gap-0 @2xl:grid-cols-5" aria-label="Progress of the HR mechanism">
            <li
                v-for="(step, i) in steps"
                :key="step.key"
                class="relative flex gap-4 pb-6 transition-all duration-500 ease-out last:pb-0 starting:translate-y-2 starting:opacity-0 motion-reduce:transition-none @2xl:flex-col @2xl:items-center @2xl:gap-3 @2xl:pb-0 @2xl:text-center"
                :style="{ transitionDelay: `${i * 90}ms` }"
                :aria-current="step.state === 'current' || step.state === 'returned' ? 'step' : undefined"
            >
                <!-- The line to the next stop: down the side when stacked, across when wide. -->
                <span
                    v-if="i < steps.length - 1"
                    aria-hidden="true"
                    class="absolute top-9 bottom-1 left-4 w-0.5 -translate-x-1/2 rounded-full @2xl:top-4 @2xl:right-[-50%] @2xl:bottom-auto @2xl:left-1/2 @2xl:h-0.5 @2xl:w-full @2xl:translate-x-4 @2xl:-translate-y-1/2"
                    :class="step.state === 'done' ? 'bg-success' : 'bg-border'"
                />

                <span class="relative z-10 grid size-8 shrink-0 place-items-center rounded-full text-sm font-semibold" :class="circle[step.state]">
                    <span
                        v-if="step.state === 'current' || step.state === 'returned'"
                        aria-hidden="true"
                        class="absolute inset-0 -z-10 rounded-full motion-safe:animate-ping"
                        :class="step.state === 'returned' ? 'bg-danger/40' : 'bg-primary/40'"
                    />
                    <Icon v-if="step.state === 'done'" name="check" size="sm" />
                    <Icon v-else-if="step.state === 'returned'" name="exclamation" size="sm" />
                    <template v-else>{{ i + 1 }}</template>
                </span>

                <div class="min-w-0 @2xl:px-2">
                    <p class="text-sm font-semibold" :class="title[step.state]">
                        {{ step.label }}
                        <span class="sr-only">: {{ spoken[step.state] }}</span>
                    </p>
                    <p v-if="step.date" class="text-xs font-medium text-text-muted">{{ formatDate(step.date) }}</p>
                    <p class="mt-0.5 text-sm leading-snug text-text-muted" :class="step.state === 'upcoming' && 'opacity-70'">{{ step.hint }}</p>
                </div>
            </li>
        </ol>
    </div>
</template>
