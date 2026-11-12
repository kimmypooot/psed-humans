<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Textarea from '@/Components/Forms/Textarea.vue';
import StatusBadge from '@/Components/Mechanisms/StatusBadge.vue';
import TrackingStepper from '@/Components/Mechanisms/TrackingStepper.vue';
import HistoryTimeline from '@/Components/Mechanisms/HistoryTimeline.vue';
import { formatDate, formatDateTime } from '@/Utils/format';

/**
 * The tracking page of one submission, reached by its signed link: the code, the status, what
 * to do next, the stepper and the full history. When PSED returned it, the corrected file is
 * uploaded here.
 */
const props = defineProps({
    mechanism: { type: Object, required: true },
    resubmitUrl: { type: String, default: null },
    maxMb: { type: Number, default: 20 },
});

const page = usePage();
const justSubmitted = computed(() => page.props.flash?.submitted);

const copied = ref(false);
const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.mechanism.tracking_code);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Copying is a convenience; the code is on the screen.
    }
};

const form = useForm({ document: null, note: '' });
const fileKey = ref(0);
const resubmit = () =>
    form.post(props.resubmitUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            fileKey.value++;
        },
    });

const callouts = {
    'Received by PSED': { tone: 'info', icon: 'clock', title: 'PSED received your submission', text: 'It will be routed to an Action Officer. You do not need to do anything now. We will email you as soon as something needs your attention.' },
    'Routed to Action Officer': { tone: 'info', icon: 'clock', title: 'Routed to an Action Officer', text: 'The Action Officer will acknowledge it and start evaluating it. You do not need to do anything now.' },
    'Processing/Evaluating': { tone: 'info', icon: 'clock', title: 'Being evaluated', text: 'The Action Officer is evaluating your HR mechanism. You do not need to do anything now.' },
    'Reviewed by PSED Chief': { tone: 'info', icon: 'clock', title: 'With the PSED Chief', text: 'The evaluation is done and the PSED Chief is reviewing it. We will email you the result.' },
    Reviewed: { tone: 'info', icon: 'clock', title: 'Reviewed by PSED', text: "PSED reviewed your HR mechanism and is preparing the transmittal for the Regional Director's signature. We will email you the documents. You do not need to do anything now." },
    'For Compliance': { tone: 'info', icon: 'clock', title: 'Reviewed by PSED', text: "PSED reviewed your HR mechanism and is preparing the transmittal for the Regional Director's signature. We will email you what needs correcting. You do not need to do anything now." },
    'Transmittal (Approved)': { tone: 'warning', icon: 'printer', title: 'Next step: print three (3) copies', text: 'Print three (3) copies of the HR Mechanism for the signature of the Head of Agency.' },
    Approved: { tone: 'success', icon: 'checkCircle', title: 'Approved', text: 'The Regional Office approved this HR mechanism. Nothing more is needed.' },
};
const callout = computed(() => callouts[props.mechanism.status] ?? null);
const tones = {
    info: 'bg-info-bg text-info ring-info-border',
    warning: 'bg-warning-bg text-warning ring-warning-border',
    success: 'bg-success-bg text-success ring-success-border',
};
</script>

<template>
    <Head :title="`${mechanism.tracking_code} · Tracking`" />

    <PublicLayout>
        <div class="mx-auto mt-8 max-w-4xl space-y-6 sm:mt-12">
            <Alert v-if="justSubmitted" tone="success" title="Submitted. Keep your tracking code.">
                We emailed this page's link to you. You can come back to it any time, or find it with your tracking code on the <a href="/track" class="font-medium underline">Track</a> page.
            </Alert>
            <Alert v-else-if="$page.props.flash?.status" tone="success">{{ $page.props.flash.status }}</Alert>

            <!-- Summary -->
            <section class="rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-widest text-text-muted uppercase">Tracking code</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <p class="font-mono text-2xl font-bold tracking-tight text-primary">{{ mechanism.tracking_code }}</p>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs font-medium text-text-muted transition-colors hover:border-primary/40 hover:text-primary focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:outline-none"
                                @click="copy"
                            >
                                <Icon :name="copied ? 'check' : 'copy'" size="sm" />{{ copied ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                    <StatusBadge :status="mechanism.status" :tone="mechanism.tone" class="px-3 py-1 text-sm" />
                </div>

                <dl class="mt-5 grid gap-x-8 gap-y-3 border-t border-border pt-5 text-sm sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <dt class="text-text-muted">HR mechanism</dt>
                        <dd class="mt-0.5 font-medium text-text">{{ mechanism.label }}</dd>
                        <dd class="text-text-muted">{{ mechanism.agency }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">Submitted</dt>
                        <dd class="mt-0.5 font-medium text-text">{{ formatDate(mechanism.date_submitted) }}</dd>
                        <dd class="text-xs text-text-muted">Updated {{ formatDateTime(mechanism.updated_at) }}</dd>
                    </div>
                </dl>
            </section>

            <!-- What to do next -->
            <section v-if="callout" class="flex gap-4 rounded-xl p-5 ring-1 ring-inset animate-rise" :class="tones[callout.tone]" aria-live="polite">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface/70" aria-hidden="true"><Icon :name="callout.icon" size="md" /></span>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold">{{ callout.title }}</h2>
                    <p class="mt-0.5 text-sm text-text">{{ callout.text }}</p>
                    <ol v-if="mechanism.status === 'Transmittal (Approved)'" class="mt-3 list-decimal space-y-1 pl-5 text-sm text-text marker:font-semibold">
                        <li>Print the approved HR mechanism: three (3) copies.</li>
                        <li>Have the Head of Agency sign every copy.</li>
                        <li>Submit the signed copies to the CSC Regional Office VIII (PSED), Government Center, Palo, Leyte.</li>
                    </ol>
                </div>
            </section>

            <section v-else-if="mechanism.status === 'Transmittal (For Compliance)'" class="rounded-xl bg-danger-bg p-5 ring-1 ring-danger-border ring-inset animate-rise" aria-live="polite">
                <div class="flex gap-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface/70 text-danger" aria-hidden="true"><Icon name="exclamation" size="md" /></span>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-danger">For compliance</h2>
                        <p class="mt-0.5 text-sm text-text">PSED emailed you the reviewed HR mechanism, the signed transmittal and the HR checklist. Please correct the HR mechanism and upload the new file below.</p>
                    </div>
                </div>
                <blockquote v-if="mechanism.remarks" class="mt-4 rounded-lg bg-surface px-4 py-3 text-sm whitespace-pre-line text-text shadow-xs">
                    <p class="mb-1 text-xs font-semibold tracking-wide text-text-muted uppercase">What needs correcting</p>
                    {{ mechanism.remarks }}
                </blockquote>

                <form v-if="resubmitUrl" class="mt-5 space-y-4 rounded-lg bg-surface p-4 shadow-xs" @submit.prevent="resubmit">
                    <FormGroup id="fix-file" label="Corrected file" required :error="form.errors.document" :helper-text="`Word (.docx) only, up to ${maxMb} MB.`">
                        <FileInput id="fix-file" :key="fileKey" accept=".docx" :max-size-mb="maxMb" :invalid="!!form.errors.document" @update:model-value="form.document = $event" />
                    </FormGroup>
                    <FormGroup id="fix-note" label="Note for PSED" :error="form.errors.note" helper-text="Optional. Say what you changed.">
                        <Textarea id="fix-note" v-model="form.note" :rows="3" />
                    </FormGroup>
                    <div class="flex justify-end">
                        <Button type="submit" variant="success" :loading="form.processing" :disabled="!form.document"><Icon name="upload" size="sm" />Submit again</Button>
                    </div>
                </form>
            </section>

            <!-- Stepper -->
            <section class="rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-7">
                <h2 class="mb-5 text-sm font-semibold tracking-wide text-text uppercase">Progress</h2>
                <TrackingStepper :steps="mechanism.steps" />
            </section>

            <!-- History -->
            <section class="rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-7">
                <h2 class="mb-5 text-sm font-semibold tracking-wide text-text uppercase">History</h2>
                <HistoryTimeline :history="mechanism.history" />
            </section>

            <p class="pb-4 text-center text-sm text-text-muted">Questions? Contact the Policies and Systems Evaluation Division (PSED), CSC Regional Office VIII.</p>
        </div>
    </PublicLayout>
</template>
