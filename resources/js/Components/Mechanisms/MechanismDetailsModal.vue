<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import StatusBadge from './StatusBadge.vue';
import TrackingStepper from './TrackingStepper.vue';
import HistoryTimeline from './HistoryTimeline.vue';
import WorkflowActions from './WorkflowActions.vue';
import TurnaroundChip from './TurnaroundChip.vue';
import { request, requestJson, errorMessage } from '@/Utils/http';
import { formatDate } from '@/Utils/format';

/**
 * One HR mechanism in a dialog, over whatever list it was opened from: status and stepper,
 * the details and dates, the history, and the actions the person may take. It fetches the
 * mechanism when `id` is set and fetches it again after an action, so it never shows stale data.
 */
const props = defineProps({
    id: { type: Number, default: null },
});

const emit = defineEmits(['close']);

const m = ref(null);
const loading = ref(false);
const failed = ref(false);

const load = async () => {
    if (props.id === null) return;
    loading.value = m.value === null || m.value.id !== props.id;
    failed.value = false;

    const { ok, data } = await request('GET', `/mechanisms/${props.id}`);

    if (props.id === null) return;
    ok && data ? (m.value = data) : (failed.value = true);
    loading.value = false;
};

watch(
    () => props.id,
    (id) => {
        if (id === null) return;
        m.value = null;
        load();
    },
    { immediate: true },
);

// PSED staff can name initials nobody has identified yet; everyone then sees the name.
const naming = ref(null);
const nameDraft = ref('');
const nameError = ref('');
const savingName = ref(false);

// The IMIS employees ("Last, First M."), fetched once the first time someone names an officer.
const employees = ref([]);
const loadingEmployees = ref(false);
let employeesLoaded = false;

const loadEmployees = async () => {
    if (employeesLoaded) return;
    employeesLoaded = true;
    loadingEmployees.value = true;
    const { ok, data } = await request('GET', '/action-officers/directory');
    loadingEmployees.value = false;
    ok && data ? (employees.value = data.employees ?? []) : (employeesLoaded = false);
};

const startNaming = (officer) => {
    naming.value = officer.id;
    nameDraft.value = officer.name ?? '';
    nameError.value = '';
    loadEmployees();
};

const saveName = async (officer) => {
    savingName.value = true;
    nameError.value = '';

    // A listed employee is saved by IMIS id (the server takes the name from IMIS); anything else is a typed name.
    const picked = employees.value.find((e) => e.value === nameDraft.value);
    const result = await requestJson('PATCH', `/action-officers/${officer.id}`, picked ? { imis_user_id: picked.id } : { name: nameDraft.value });

    savingName.value = false;

    if (!result.ok) {
        nameError.value = errorMessage(result);
        return;
    }

    naming.value = null;
    load();
};

const open = computed(() => props.id !== null);
const dates = computed(() => (m.value?.dates ?? []).filter((d) => d.value));

// The documents PSED sends back. An imported (legacy) record has none of its own, but once it is worked in
// the system (routed, forwarded to the Chief) its documents must show like any other.
const showDocs = computed(() => !!m.value && (!m.value.is_legacy || m.value.attachments.some((a) => a.name) || m.value.can.acknowledge || m.value.can.work || m.value.can.review || m.value.can.generateTransmittal || m.value.can.transmit));
</script>

<template>
    <Modal :open="open" :title="m ? m.label : 'HR mechanism'" icon="clipboard" size="xl" @close="emit('close')">
        <template v-if="loading || (!m && !failed)">
            <div class="animate-pulse space-y-4" role="status" aria-label="Loading">
                <div class="h-6 w-2/3 rounded bg-surface-muted" />
                <div class="h-20 rounded bg-surface-muted" />
                <div class="h-32 rounded bg-surface-muted" />
            </div>
        </template>

        <Alert v-else-if="failed" tone="danger" title="Could not open this HR mechanism">It may have been removed, or you may not be allowed to see it.</Alert>

        <div v-else-if="m" class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-base font-semibold text-text">{{ m.agency }}</p>
                    <p class="text-sm text-text-muted">
                        <span class="font-mono">{{ m.tracking_code }}</span>
                        <template v-if="m.field_office"> · {{ m.field_office }}</template>
                    </p>
                </div>
                <StatusBadge :status="m.status" :tone="m.tone" class="px-3 py-1 text-sm" />
            </div>

            <Alert v-if="m.status === 'Processing/Evaluating' && m.remarks" tone="warning" title="Returned by the PSED Chief">{{ m.remarks }}</Alert>
            <Alert v-else-if="m.status === 'Transmittal (For Compliance)' && m.remarks" tone="danger" title="For compliance">{{ m.remarks }}</Alert>
            <Alert v-else-if="m.status === 'Reviewed'" tone="warning" title="Generate the transmittal">
                The PSED Chief reviewed it and signed the HR checklist. The Action Officer chooses the transmittal: Approved, or For Compliance if the HR mechanism needs corrections. Then print it, have the Regional Director sign it, and upload the signed copy to email the agency.
            </Alert>
            <Alert v-else-if="m.status === 'For Compliance'" tone="warning" title="For Compliance: print the transmittal">
                <template v-if="m.remarks">To correct: {{ m.remarks }}. </template>The For Compliance transmittal is made. Print it, have the Regional Director sign it, then upload the signed copy to email the agency.
            </Alert>
            <Alert v-else-if="m.status === 'Transmittal (Approved)'" tone="warning" title="Waiting for the signed copies">
                The agency was emailed the documents and told to print three (3) copies for the signature of the Head of Agency. Give the final approval once they reach the Regional Office.
            </Alert>
            <div v-if="m.turnaround?.running" class="flex flex-wrap items-center gap-2 text-sm text-text-muted">
                Turnaround
                <TurnaroundChip :turnaround="m.turnaround" long />
            </div>

            <TrackingStepper :steps="m.steps" />

            <div class="grid gap-6 @container lg:grid-cols-2">
                <section>
                    <h3 class="mb-3 text-sm font-semibold tracking-wide text-text uppercase">Details</h3>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                        <dt class="text-text-muted">Kind</dt>
                        <dd class="text-text">{{ m.type_label }}</dd>
                        <dt class="text-text-muted">Copies</dt>
                        <dd class="text-text">{{ m.copies ?? '—' }}</dd>
                        <template v-if="m.officers?.length">
                            <dt class="text-text-muted">{{ m.officers.length > 1 ? 'Action Officers' : 'Action Officer' }}</dt>
                            <dd class="text-text">
                                <div v-for="o in m.officers" :key="o.initials" class="flex flex-wrap items-center gap-x-2">
                                    <span class="font-medium">{{ o.initials }}</span>
                                    <form v-if="naming === o.id" class="flex flex-wrap items-center gap-2" @submit.prevent="saveName(o)">
                                        <div class="w-72 max-w-full">
                                            <Combobox :id="`officer-${o.id}`" v-model="nameDraft" :label="`Action Officer ${o.initials}`" :options="employees" :loading="loadingEmployees" placeholder="Search by last name, first name" />
                                        </div>
                                        <Button type="submit" size="sm" :loading="savingName" :disabled="!nameDraft.trim()">Save</Button>
                                        <Button type="button" size="sm" variant="ghost" @click="naming = null">Cancel</Button>
                                        <span v-if="nameError" class="w-full text-xs text-danger" role="alert">{{ nameError }}</span>
                                    </form>
                                    <template v-else>
                                        <span class="text-text-muted">{{ o.name ?? '(name not recorded)' }}</span>
                                        <button v-if="m.can_name_officers" type="button" class="text-xs text-primary underline" :aria-label="`${o.name ? 'Edit' : 'Add'} the name of ${o.initials}`" @click="startNaming(o)">{{ o.name ? 'Edit' : 'Add name' }}</button>
                                    </template>
                                </div>
                                <span v-if="m.is_mine" class="text-text-muted">(you)</span>
                            </dd>
                        </template>
                        <template v-else-if="m.officer"><dt class="text-text-muted">Action Officer</dt><dd class="text-text">{{ m.officer }}<span v-if="m.is_mine" class="text-text-muted"> (you)</span></dd></template>
                        <template v-else-if="m.processor"><dt class="text-text-muted">Processor</dt><dd class="text-text">{{ m.processor }}</dd></template>
                        <template v-if="m.contact_email">
                            <dt class="text-text-muted">Contact</dt>
                            <dd class="min-w-0 break-words text-text">
                                {{ m.contact_name }}<template v-if="m.contact_name"><br /></template>
                                <a :href="`mailto:${m.contact_email}`" class="text-primary hover:underline">{{ m.contact_email }}</a>
                            </dd>
                        </template>
                        <template v-if="m.location"><dt class="text-text-muted">Location</dt><dd class="text-text">{{ m.location }}</dd></template>
                        <template v-if="m.receipt_details"><dt class="text-text-muted">Receipt</dt><dd class="text-text">{{ m.receipt_details }}</dd></template>
                        <template v-if="m.remarks && m.status !== 'Transmittal (For Compliance)' && m.status !== 'Processing/Evaluating'"><dt class="text-text-muted">Remarks</dt><dd class="whitespace-pre-line text-text">{{ m.remarks }}</dd></template>
                        <template v-if="m.document_name"><dt class="text-text-muted">File</dt><dd class="min-w-0 break-words text-text">{{ m.document_name }}</dd></template>
                    </dl>
                    <p v-if="m.is_legacy" class="mt-3 text-xs text-text-muted">Brought over from the old monitoring workbook; the agency's own file was not imported.</p>

                    <template v-if="showDocs">
                        <h3 class="mt-6 mb-3 text-sm font-semibold tracking-wide text-text uppercase">Documents for the agency</h3>
                        <ul class="divide-y divide-border rounded-lg border border-border text-sm">
                            <li v-for="a in m.attachments" :key="a.kind" class="flex items-center justify-between gap-3 px-3 py-2">
                                <div class="min-w-0">
                                    <p class="text-text-muted">{{ a.label }}</p>
                                    <p v-if="a.name" class="truncate font-medium text-text">{{ a.name }}</p>
                                    <p v-else-if="a.kind === 'transmittal'" class="text-xs text-text-muted">Made when the Chief decides</p>
                                    <p v-else class="text-xs text-text-muted">Not attached yet</p>
                                </div>
                                <Button v-if="a.url" size="sm" variant="ghost" :href="a.url" external><Icon name="download" size="sm" />Open</Button>
                            </li>
                        </ul>
                        <p class="mt-2 text-xs text-text-muted">The Action Officer attaches the reviewed HR mechanism and the HR checklist and forwards them to the PSED Chief. The Chief decides: approve, for compliance, or back to the officer. The transmittal is made from the letter the Chief fills in; the Action Officer prints it, the Regional Director signs it, and the signed copy is uploaded to email all three to the agency.</p>
                    </template>

                    <h3 class="mt-6 mb-3 text-sm font-semibold tracking-wide text-text uppercase">Dates</h3>
                    <ul class="divide-y divide-border text-sm">
                        <li v-for="d in dates" :key="d.label" class="flex justify-between gap-3 py-1.5">
                            <span class="text-text-muted">{{ d.label }}</span>
                            <span class="font-medium text-text">{{ formatDate(d.value) }}</span>
                        </li>
                        <li v-if="!dates.length" class="py-1.5 text-text-muted">No dates were recorded.</li>
                    </ul>
                </section>

                <section>
                    <h3 class="mb-3 text-sm font-semibold tracking-wide text-text uppercase">History</h3>
                    <HistoryTimeline :history="m.history" />
                </section>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Button v-if="m?.can_download && m?.has_document" variant="outline" size="sm" :href="`/mechanisms/${m.id}/file`" external>
                        <Icon name="download" size="sm" />Download file
                    </Button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <WorkflowActions v-if="m" :mechanism="m" :attachments="showDocs ? m.attachments : null" size="md" @done="load" />
                    <Button variant="secondary" @click="emit('close')">Close</Button>
                </div>
            </div>
        </template>
    </Modal>
</template>
