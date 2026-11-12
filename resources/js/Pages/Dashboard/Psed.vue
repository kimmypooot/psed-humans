<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import MechanismDetailsModal from '@/Components/Mechanisms/MechanismDetailsModal.vue';
import TurnaroundChip from '@/Components/Mechanisms/TurnaroundChip.vue';
import WorkflowActions from '@/Components/Mechanisms/WorkflowActions.vue';
import { toneClasses } from '@/Components/UI/tones';
import { breakable, formatDate } from '@/Utils/format';

/**
 * The work board of PSED and the Director IV: one column per status, in the order a mechanism
 * goes, each headed by how many are in it. A sticky strip shows what is waiting on the signed-in
 * person. A card opens the full details in a dialog (its title is the one link) and carries the
 * actions that person may take.
 */
const props = defineProps({
    counts: { type: Array, required: true },
    columns: { type: Array, required: true },
    mine: { type: Object, required: true },
});

const viewing = ref(null);
const role = computed(() => usePage().props.auth.user.role);
const intro = computed(() =>
    role.value === 'director'
        ? 'Mechanisms waiting for your final approval are in the Transmittal (Approved) column.'
        : 'Route what came in, acknowledge and evaluate what is routed to you, and review what is forwarded to the Chief.',
);

// Each column wears an icon as well as a colour, so a status never rests on colour alone.
const statusIcon = {
    'Received by PSED': 'archive',
    'Routed to Action Officer': 'arrowRight',
    'Processing/Evaluating': 'activity',
    'Reviewed by PSED Chief': 'shield',
    Reviewed: 'documentText',
    'For Compliance': 'printer',
    'Transmittal (Approved)': 'documentText',
    'Transmittal (For Compliance)': 'exclamation',
    Approved: 'checkCircle',
};
// The column holds only the newest cards; this is how many there really are.
const totalOf = (status) => props.counts.find((c) => c.status === status)?.total ?? 0;

const strip = computed(() =>
    [
        { key: 'to_route', label: 'To route', icon: 'arrowRight', tone: 'info' },
        { key: 'to_acknowledge', label: 'To acknowledge', icon: 'check', tone: 'warning' },
        { key: 'processing', label: 'Processing', icon: 'clock', tone: 'info' },
        { key: 'overdue', label: 'Overdue', icon: 'exclamation', tone: 'danger' },
        { key: 'to_review', label: 'For Chief review', icon: 'shield', tone: 'info' },
        { key: 'to_transmit', label: 'To transmit', icon: 'printer', tone: 'warning' },
    ].filter((s) => props.mine[s.key] > 0 || ['to_acknowledge', 'processing'].includes(s.key)),
);
</script>

<template>
    <Head title="Dashboard" />

    <StaffLayout>
        <PageHeader title="HR Mechanisms" :description="intro">
            <template #actions>
                <Button variant="outline" href="/analytics"><Icon name="chartBar" size="sm" />Analytics</Button>
                <Button variant="outline" href="/mechanisms">All mechanisms</Button>
            </template>
        </PageHeader>

        <!-- What waits on you: slim, and it stays in view while the board scrolls. -->
        <div v-if="role !== 'director'" class="sticky top-16 z-20 -mx-4 mb-4 bg-surface-muted/90 px-4 py-2 backdrop-blur-sm sm:-mx-6 sm:px-6">
            <ul class="flex gap-2 overflow-x-auto" aria-label="Waiting on you">
                <li v-for="s in strip" :key="s.key" class="flex shrink-0 items-center gap-2.5 rounded-full border border-border bg-surface py-1.5 pr-4 pl-1.5">
                    <span class="grid size-7 place-items-center rounded-full" :class="toneClasses[mine[s.key] > 0 ? s.tone : 'neutral']"><Icon :name="s.icon" size="sm" /></span>
                    <span class="text-base leading-none font-semibold text-text tabular-nums">{{ mine[s.key] }}</span>
                    <span class="text-xs text-text-muted">{{ s.label }}</span>
                </li>
            </ul>
        </div>

        <!-- The board scrolls sideways and snaps to a column; each column fits the screen. -->
        <div class="-mx-4 snap-x snap-mandatory overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6" tabindex="0" role="region" aria-label="Board of HR mechanisms by status">
            <div class="flex gap-4">
                <section
                    v-for="col in columns"
                    :key="col.status"
                    class="flex max-h-[calc(100dvh-16rem)] min-h-64 w-[clamp(17rem,22vw,20rem)] shrink-0 snap-start flex-col rounded-lg border border-border bg-surface-muted/60"
                >
                    <header class="border-b border-border p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="badge" :class="toneClasses[col.tone]"><Icon :name="statusIcon[col.status] ?? 'tag'" size="sm" class="size-3.5" />{{ breakable(col.status) }}</span>
                            <span class="text-sm font-semibold text-text tabular-nums" :title="`${totalOf(col.status)} in total`">{{ totalOf(col.status).toLocaleString() }}</span>
                        </div>
                        <p class="mt-1 text-xs text-text-muted">{{ col.hint }}</p>
                        <p v-if="totalOf(col.status) > col.items.length" class="mt-0.5 text-xs text-text-muted">Showing the {{ col.items.length }} {{ col.status === 'Approved' ? 'most recent' : 'next due' }}. <a href="/mechanisms" class="text-primary underline">See all</a></p>
                    </header>

                    <ul class="space-y-3 overflow-y-auto p-3">
                        <li v-if="!col.items.length" class="flex flex-col items-center gap-2 py-8 text-center text-sm text-text-muted">
                            <span class="grid size-10 place-items-center rounded-full bg-surface text-text-muted/70 ring-1 ring-border" aria-hidden="true"><Icon :name="statusIcon[col.status] ?? 'tag'" size="md" /></span>
                            Nothing waiting here.
                        </li>
                        <li
                            v-for="m in col.items"
                            :key="m.id"
                            class="group relative rounded-lg border bg-surface p-3 transition duration-150 hover:-translate-y-0.5 hover:shadow-sm motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                            :class="m.is_mine ? 'border-primary/40 ring-1 ring-primary/15' : 'border-border hover:border-primary/30'"
                        >
                            <!-- The one way into the details: this link stretches over the whole card. -->
                            <button type="button" class="text-left text-sm font-semibold text-primary after:absolute after:inset-0 after:rounded-lg group-hover:underline focus-visible:after:ring-2 focus-visible:after:ring-focus-ring focus-visible:outline-none" @click="viewing = m.id">{{ m.agency }}</button>
                            <p class="mt-0.5 text-sm text-text">{{ m.label }}</p>

                            <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-muted">
                                <span class="font-mono">{{ m.tracking_code }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ formatDate(m.date_submitted) }}</span>
                                <template v-if="m.officer">
                                    <span aria-hidden="true">·</span>
                                    <span class="inline-flex items-center gap-1"><Icon name="user" size="sm" class="size-3.5" />{{ m.is_mine ? 'You' : m.officer }}</span>
                                </template>
                            </p>

                            <TurnaroundChip :turnaround="m.turnaround" class="mt-2" />

                            <!-- Above the stretched link, so the buttons still work. -->
                            <div v-if="Object.values(m.can).some(Boolean)" class="relative z-10 mt-3">
                                <WorkflowActions :mechanism="m" />
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <MechanismDetailsModal :id="viewing" @close="viewing = null" />
    </StaffLayout>
</template>
