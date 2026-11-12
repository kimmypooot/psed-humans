<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Textarea from '@/Components/Forms/Textarea.vue';
import FieldInput from '@/Components/Forms/FieldInput.vue';
import LetterEditor from '@/Components/Forms/LetterEditor.vue';
import { request, requestJson, downloadFile, errorMessage } from '@/Utils/http';

/**
 * The buttons of the PSED process on one mechanism. Only what the server says this person may do
 * (`mechanism.can`) shows, and the server checks again on every request:
 *   Route (PSED staff) -> Acknowledge receipt (Action Officer, starts the 20-day clock) ->
 *   Forward to the Chief (with the reviewed HR mechanism and the HR checklist) ->
 *   Approve / For compliance / Return to the officer (PSED Chief; the Chief signs the checklist with their PNPKI certificate) ->
 *   Generate the transmittal (Action Officer) -> Transmit (Action Officer: the Regional Director signed the printed transmittal; this emails the agency) ->
 *   Final approve (Regional Director or PSED) -> Record distribution (Action Officer).
 * The Chief reviews what was forwarded and decides. Nothing reaches the agency until the Action Officer transmits.
 * The Chief only signs the checklist. The Action Officer makes the transmittal letter from the letter fields
 * (addressee, subject, and the Director IV or Director III who signs), which the form starts filled in.
 *
 * `attachments` (from the details dialog) tells which of the two are already attached;
 * a list row does not know, so the form says a file left empty keeps the one already there.
 */
const props = defineProps({
    mechanism: { type: Object, required: true },
    attachments: { type: Array, default: null },
    size: { type: String, default: 'sm' },
});

const emit = defineEmits(['done']);

const page = usePage();
const officers = computed(() => page.props.officers ?? []);
const myId = computed(() => String(page.props.auth.user.id));

const action = ref(null);
const fileKey = ref(0);
const blankLetter = () => ({ date: '', addressee_name: '', addressee_position: '', addressee_address: '', salutation: '', subject: '', signatory_id: '' });
const form = useForm({ officer_id: '', note: '', remarks: '', reviewed: null, checklist: null, signed_checklist: null, signed_transmittal: null, transmittal: blankLetter() });

const kinds = [
    { key: 'reviewed', label: 'Reviewed HR mechanism' },
    { key: 'checklist', label: 'HR checklist' },
];
const attached = (key) => props.attachments?.find((a) => a.kind === key)?.name ?? null;

const steps = {
    route: { title: 'Route to an Action Officer', url: 'route', button: 'Route', variant: 'primary', icon: 'arrowRight', text: 'Choose the PSED staff member who will evaluate this HR mechanism. You may choose yourself.' },
    acknowledge: { title: 'Acknowledge receipt', url: 'acknowledge', button: 'Acknowledge receipt', variant: 'primary', icon: 'check', text: 'The status becomes Processing/Evaluating. The 20-working-day turnaround has been running since it was routed to you.' },
    forward: { title: 'Forward to the PSED Chief', url: 'forward', button: 'Forward to the Chief', variant: 'primary', icon: 'arrowRight', docs: true, text: 'You finished evaluating. Attach the reviewed HR mechanism and the HR checklist. The PSED Chief reviews them and decides: approve, for compliance, or send it back to you to evaluate again.' },
    compliance: { title: 'Mark For Compliance', url: 'compliance', button: 'Mark For Compliance', variant: 'danger', icon: 'exclamation', remarks: true, forwarded: 'compliance', signed: true, text: 'Your review found it needs corrections. Sign the HR checklist with your DICT PNPKI certificate and upload it. The Action Officer then generates the For Compliance transmittal, has the Regional Director sign it, and sends it to the agency.' },
    back: { title: 'Return to the Action Officer', url: 'return-to-officer', button: 'Return for re-evaluation', variant: 'outline', icon: 'chevronLeft', remarks: true, remarksLabel: 'What the Action Officer should look at again', text: 'You are not approving it yet. It goes back to the Action Officer to evaluate again, then comes back to you. The 20-day clock keeps running.' },
    chief: { title: 'Approve (PSED Chief)', url: 'chief-approve', button: 'Approve', variant: 'success', icon: 'checkCircle', forwarded: 'approval', signed: true, text: 'Your review found it ready for approval. Sign the HR checklist with your DICT PNPKI certificate and upload it. The status becomes Reviewed. The Action Officer then generates the For Approval transmittal, has the Regional Director sign it, and sends it to the agency.' },
    generate: { title: 'Generate the transmittal', url: 'generate-transmittal', button: 'Generate the transmittal', variant: 'primary', icon: 'arrowRight', letter: 'auto', text: 'The PSED Chief decided. Fill in the transmittal letter and generate it. Then open it under Documents, print it, and have the Regional Director (or Assistant Regional Director) sign it. You can generate it again until it is sent.' },
    transmit: { title: 'Transmit to the agency', url: 'transmit', button: 'Email the agency', variant: 'primary', icon: 'arrowRight', transmit: true, text: 'Upload the transmittal the Regional Director signed. The status becomes Transmittal (Approved) or Transmittal (For Compliance), and the agency is emailed the reviewed HR mechanism, the signed transmittal and the HR checklist.' },
    final: { title: 'Final approval', url: 'final-approve', button: 'Final approve', variant: 'success', icon: 'shield', text: "Confirm the Head of Agency's signed copies reached the Regional Office. The mechanism becomes Approved." },
    distribute: { title: 'Record distribution', url: 'distribute', button: 'Record distribution', variant: 'primary', icon: 'check', text: 'Confirm that one approved copy went to the CSC Field Office, one to the agency, and the third is kept in the records of the Policies and Systems Evaluation Division.' },
};

const letterLoading = ref(false);
const letterFailed = ref(false);
const signatories = ref([]);
const documents = ref([]);
const draftError = ref('');
const drafting = ref(false);
// The letter shown on screen before anything is made: the Chief reads it, then goes back to edit or approves.
const previewing = ref(false);
const previewLoading = ref(false);
const previewError = ref('');
const previewSlots = ref(null);

// The wording editor: the standard wording of each paragraph (for the fields as they stand now), and what the editors
// show. A paragraph PSED changed is sent with the form and replaces the standard one; the others follow the fields.
const EDITABLE = [
    ['p1', 'Paragraph 1'],
    ['p2', 'Paragraph 2'],
    ['p3', 'Paragraph 3'],
    ['thanks', 'Thanks'],
    ['closing', 'Closing'],
];
const wordingOpen = ref(false);
const wordingLoading = ref(false);
const wordingError = ref('');
const body = ref({});
const standard = ref({});
const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const editedKeys = computed(() => EDITABLE.map(([key]) => key).filter((key) => body.value[key] !== undefined && !same(body.value[key], standard.value[key])));
const bodyError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('transmittal.body'))?.[1] ?? null);

// The words of one paragraph: runs of [text, 'b' | 'i' | 'bi'].
const Runs = (props) => (props.runs ?? []).map(([text, style], i) => h('span', { key: i, class: [style?.includes('b') && 'font-bold', style?.includes('i') && 'italic'] }, text));
Runs.props = ['runs'];
// The salutation follows the name and position until PSED types one of their own.
const salutationEdited = ref(false);

const open = (key) => {
    form.reset();
    form.clearErrors();
    fileKey.value++;
    draftError.value = '';
    previewing.value = false;
    previewError.value = '';
    wordingOpen.value = false;
    wordingError.value = '';
    body.value = {};
    standard.value = {};
    action.value = key;

    if (steps[key].letter) loadLetter(letterType.value);
    else if (steps[key].forwarded) loadLetter(steps[key].forwarded, true);
};
const close = () => {
    action.value = null;
    previewing.value = false;
};
const step = computed(() => (action.value ? steps[action.value] : null));
// The letter the Chief's decision calls for: For Compliance when the Chief marked it so.
const letterType = computed(() => (step.value?.letter === 'auto' ? (props.mechanism.status === 'For Compliance' ? 'compliance' : 'approval') : step.value?.letter));

const loadLetter = async (type, documentsOnly = false) => {
    letterLoading.value = true;
    letterFailed.value = false;
    salutationEdited.value = false;

    const { ok, data } = await request('GET', `/mechanisms/${props.mechanism.id}/transmittal/${type}${documentsOnly ? '?documents=1' : ''}`);

    letterLoading.value = false;

    if (!ok || !data) {
        letterFailed.value = true;
        return;
    }

    documents.value = data.documents ?? [];

    if (documentsOnly) return;

    signatories.value = data.signatories;
    form.transmittal = { ...blankLetter(), ...data.defaults };
};

const surname = (name) => {
    const words = name
        .replace(/^(hon|atty|dr|engr|mr|ms|mrs)\.?(\s+|$)/i, '')
        .replace(/\b(jr|sr|ii|iii|iv)\b\.?/gi, '')
        .replace(/[,.]/g, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    const last = words[words.length - 1] ?? '';

    return last ? last.charAt(0).toUpperCase() + last.slice(1).toLowerCase() : '';
};

const salutationFor = (name, position) => {
    const title = /mayor/i.test(position) ? 'Mayor' : /governor/i.test(position) ? 'Governor' : null;

    return title && surname(name) ? `${title} ${surname(name)}` : 'Sir/Madam';
};

watch(
    () => [form.transmittal.addressee_name, form.transmittal.addressee_position],
    ([name, position]) => {
        if (action.value && steps[action.value].letter && !salutationEdited.value) form.transmittal.salutation = salutationFor(name ?? '', position ?? '');
    },
);

// Brings the standard wording up to date with the fields. A paragraph PSED has not changed follows it; one they changed stays.
const loadStandard = async ({ force = false } = {}) => {
    wordingError.value = '';
    wordingLoading.value = true;

    const { body: _ignored, ...fields } = form.transmittal;
    const result = await requestJson('POST', `/mechanisms/${props.mechanism.id}/transmittal/${letterType.value}/view`, fields);

    wordingLoading.value = false;

    if (!result.ok || !result.data?.slots) {
        wordingError.value = errorMessage(result);
        return false;
    }

    const next = { ...body.value };
    const fresh = {};

    for (const [key] of EDITABLE) {
        const kept = !force && next[key] !== undefined && !same(next[key], standard.value[key]);
        fresh[key] = result.data.slots[key];
        if (!kept) next[key] = JSON.parse(JSON.stringify(fresh[key]));
    }

    standard.value = fresh;
    body.value = next;

    return true;
};

const toggleWording = async () => {
    wordingOpen.value = !wordingOpen.value;
    if (wordingOpen.value && !Object.keys(standard.value).length) await loadStandard();
};

const resetWording = () => loadStandard({ force: true });

// Only what was changed travels with the letter.
watch(
    [body, standard],
    () => {
        if (!editedKeys.value.length) {
            delete form.transmittal.body;
            return;
        }

        form.transmittal.body = Object.fromEntries(editedKeys.value.map((key) => [key, body.value[key]]));
    },
    { deep: true },
);

// The paragraphs follow the fields above them while the editor is open.
let wordingTimer = null;
watch(
    () => JSON.stringify([form.transmittal.addressee_name, form.transmittal.addressee_position, form.transmittal.addressee_address, form.transmittal.salutation, form.transmittal.subject]),
    () => {
        if (!wordingOpen.value) return;
        window.clearTimeout(wordingTimer);
        wordingTimer = window.setTimeout(loadStandard, 600);
    },
);

const showPreview = async () => {
    previewError.value = '';
    previewLoading.value = true;

    const result = await requestJson('POST', `/mechanisms/${props.mechanism.id}/transmittal/${letterType.value}/view`, form.transmittal);

    previewLoading.value = false;

    if (!result.ok || !result.data?.slots) {
        previewError.value = errorMessage(result);
        return;
    }

    previewSlots.value = result.data.slots;
    previewing.value = true;
};

const draft = async () => {
    draftError.value = '';
    drafting.value = true;

    const result = await downloadFile('POST', `/mechanisms/${props.mechanism.id}/transmittal/${letterType.value}/preview`, form.transmittal, 'Transmittal draft.docx');

    drafting.value = false;
    if (!result.ok) draftError.value = errorMessage(result);
};

const send = () => {
    form.post(`/mechanisms/${props.mechanism.id}/${step.value.url}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            close();
            emit('done');
        },
    });
};

const routeToMe = () => {
    form.officer_id = myId.value;
    send();
};

const docErrors = computed(() => [form.errors.reviewed, form.errors.transmittal, form.errors.checklist].filter(Boolean));
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button v-if="mechanism.can.route" :size="size" @click="open('route')">{{ mechanism.officer ? 'Re-route' : 'Route' }}</Button>
        <Button v-if="mechanism.can.acknowledge" :size="size" @click="open('acknowledge')"><Icon name="check" size="sm" />Acknowledge</Button>
        <Button v-if="mechanism.can.work" :size="size" @click="open('forward')">Forward to Chief</Button>
        <Button v-if="mechanism.can.review" :size="size" variant="success" @click="open('chief')">Approve</Button>
        <Button v-if="mechanism.can.returnForCompliance" :size="size" variant="danger" @click="open('compliance')">For compliance</Button>
        <Button v-if="mechanism.can.returnToOfficer" :size="size" variant="outline" @click="open('back')">Return to officer</Button>
        <Button v-if="mechanism.can.generateTransmittal" :size="size" :variant="mechanism.can.transmit ? 'outline' : 'primary'" @click="open('generate')">{{ mechanism.can.transmit ? 'Regenerate transmittal' : 'Generate transmittal' }}</Button>
        <Button v-if="mechanism.can.transmit" :size="size" @click="open('transmit')">Transmit to agency</Button>
        <Button v-if="mechanism.can.finalApprove" :size="size" variant="success" @click="open('final')">Final approve</Button>
        <Button v-if="mechanism.can.distribute" :size="size" @click="open('distribute')">Record distribution</Button>

        <Modal :open="action !== null" :title="step ? step.title : ''" :icon="step ? step.icon : null" :size="previewing ? 'xl' : 'lg'" @close="close">
            <form v-if="step" class="space-y-4" @submit.prevent="send">
                <div v-show="!previewing" class="space-y-4">
                <p class="text-sm text-text-muted">
                    <strong class="text-text">{{ mechanism.agency }}</strong> · {{ mechanism.label }} ({{ mechanism.tracking_code }})
                </p>
                <Alert tone="info">{{ step.text }}</Alert>

                <!-- Route -->
                <template v-if="action === 'route'">
                    <FormGroup id="wf-officer" label="Action Officer" required :error="form.errors.officer_id" helper-text="PSED staff who have opened this system appear here.">
                        <Combobox id="wf-officer" v-model="form.officer_id" :options="officers" :allow-custom="false" :invalid="!!form.errors.officer_id" placeholder="Choose the Action Officer" />
                    </FormGroup>
                </template>

                <!-- The three documents -->
                <template v-if="step.docs">
                    <Alert v-if="docErrors.length" tone="danger">{{ docErrors[0] }}</Alert>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormGroup v-for="k in kinds" :id="`wf-${k.key}`" :key="k.key" :label="k.label" :error="form.errors[k.key] && form.errors[k.key] !== docErrors[0] ? form.errors[k.key] : null">
                            <FileInput :id="`wf-${k.key}`" :key="`${k.key}-${fileKey}`" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip" :max-size-mb="20" @update:model-value="form[k.key] = $event" />
                            <p v-if="attached(k.key)" class="mt-1 text-xs text-success">Attached: {{ attached(k.key) }}. Choose a file only to replace it.</p>
                            <p v-else-if="attachments" class="mt-1 text-xs text-danger">Not attached yet.</p>
                        </FormGroup>
                    </div>
                    <p v-if="!attachments" class="text-xs text-text-muted">A file left empty keeps the one already attached. Both must be attached.</p>
                </template>

                <!-- The checklist the Chief signs with their DICT PNPKI certificate -->
                <FormGroup v-if="step.signed" id="wf-signed" label="HR checklist signed with your DICT PNPKI certificate" required :error="form.errors.signed_checklist" helper-text="A PDF carrying your digital signature. It replaces the checklist the Action Officer forwarded.">
                    <FileInput id="wf-signed" :key="`signed-${fileKey}`" accept=".pdf" :max-size-mb="20" @update:model-value="form.signed_checklist = $event" />
                </FormGroup>

                <!-- The transmittal the Regional Director signed -->
                <template v-if="step.transmit">
                    <FormGroup id="wf-signed-transmittal" label="Transmittal signed by the Regional Director" required :error="form.errors.signed_transmittal || form.errors.reviewed" helper-text="A scan of the signed transmittal: PDF, image or Word file.">
                        <FileInput id="wf-signed-transmittal" :key="`transmittal-${fileKey}`" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" :max-size-mb="20" @update:model-value="form.signed_transmittal = $event" />
                    </FormGroup>
                    <p class="text-xs text-text-muted">The transmittal you generated, the reviewed HR mechanism and the HR checklist are under Documents in the details. To change the letter, close this and choose Regenerate transmittal.</p>
                </template>

                <!-- What the Action Officer forwarded: the Chief opens and reviews these before deciding -->
                <template v-if="step.forwarded">
                    <div class="rounded-lg border border-border">
                        <h3 class="border-b border-border px-4 py-2 text-sm font-semibold text-text">Forwarded by the Action Officer</h3>
                        <ul class="divide-y divide-border text-sm">
                            <li v-for="d in documents" :key="d.kind" class="flex items-center justify-between gap-3 px-4 py-2">
                                <div class="min-w-0">
                                    <p class="text-text-muted">{{ d.label }}</p>
                                    <p v-if="d.name" class="truncate font-medium text-text">{{ d.name }}</p>
                                    <p v-else class="text-xs text-danger">Not attached</p>
                                </div>
                                <Button v-if="d.url" size="sm" variant="ghost" :href="d.url" external><Icon name="download" size="sm" />Open</Button>
                            </li>
                        </ul>
                    </div>
                </template>

                <!-- The transmittal letter -->
                <template v-if="step.letter">
                    <div class="space-y-4 rounded-lg border border-border p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-text">Transmittal ({{ letterType === 'approval' ? 'For Approval' : 'For Compliance' }})</h3>
                            <div class="flex flex-wrap gap-2">
                                <Button type="button" size="sm" variant="outline" :loading="previewLoading" :disabled="letterLoading || letterFailed" @click="showPreview"><Icon name="eye" size="sm" />Preview</Button>
                                <Button type="button" size="sm" variant="outline" :loading="drafting" :disabled="letterLoading || letterFailed" @click="draft"><Icon name="download" size="sm" />Download a draft</Button>
                            </div>
                        </div>
                        <Alert v-if="letterFailed" tone="danger">The transmittal could not be prepared. Close this and try again.</Alert>
                        <Alert v-if="draftError" tone="danger">{{ draftError }}</Alert>
                        <Alert v-if="previewError" tone="danger">{{ previewError }}</Alert>
                        <div v-if="letterLoading" class="animate-pulse space-y-3" role="status" aria-label="Loading the transmittal">
                            <div class="h-9 rounded bg-surface-muted" />
                            <div class="h-9 rounded bg-surface-muted" />
                        </div>
                        <div v-else-if="!letterFailed" class="grid gap-4 sm:grid-cols-2">
                            <FormGroup id="tr-date" label="Date of the letter" required :error="form.errors['transmittal.date']">
                                <FieldInput id="tr-date" v-model="form.transmittal.date" type="date" required :invalid="!!form.errors['transmittal.date']" />
                            </FormGroup>
                            <FormGroup id="tr-signatory" label="Signed by" required :error="form.errors['transmittal.signatory_id']" helper-text="The Director IV or the Director III.">
                                <Combobox id="tr-signatory" v-model="form.transmittal.signatory_id" :options="signatories" :allow-custom="false" :invalid="!!form.errors['transmittal.signatory_id']" placeholder="Choose who signs" />
                            </FormGroup>
                            <FormGroup id="tr-name" label="Addressee" required :error="form.errors['transmittal.addressee_name']" helper-text="Name of the head of the agency, e.g. HON. MICHAEL DRAGON T. JAVIER.">
                                <FieldInput id="tr-name" v-model="form.transmittal.addressee_name" :maxlength="120" required :invalid="!!form.errors['transmittal.addressee_name']" />
                            </FormGroup>
                            <FormGroup id="tr-position" label="Position" required :error="form.errors['transmittal.addressee_position']">
                                <FieldInput id="tr-position" v-model="form.transmittal.addressee_position" :maxlength="120" placeholder="Municipal Mayor" required :invalid="!!form.errors['transmittal.addressee_position']" />
                            </FormGroup>
                            <FormGroup id="tr-address" label="Agency and address" class="sm:col-span-2" :error="form.errors['transmittal.addressee_address']" helper-text="One line each, as they appear under the position.">
                                <Textarea id="tr-address" v-model="form.transmittal.addressee_address" :rows="3" :invalid="!!form.errors['transmittal.addressee_address']" />
                            </FormGroup>
                            <FormGroup id="tr-salutation" label="Dear" required :error="form.errors['transmittal.salutation']" helper-text="For example Mayor Javier.">
                                <FieldInput id="tr-salutation" v-model="form.transmittal.salutation" :maxlength="80" required :invalid="!!form.errors['transmittal.salutation']" @update:model-value="salutationEdited = true" />
                            </FormGroup>
                            <FormGroup id="tr-subject" label="HR mechanism, as named in the letter" required :error="form.errors['transmittal.subject']">
                                <FieldInput id="tr-subject" v-model="form.transmittal.subject" :maxlength="200" required :invalid="!!form.errors['transmittal.subject']" />
                            </FormGroup>
                        </div>

                        <!-- The wording of the paragraphs -->
                        <div v-if="!letterLoading && !letterFailed" class="space-y-3 border-t border-border pt-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h4 class="text-sm font-semibold text-text">Wording of the letter</h4>
                                    <p class="text-xs text-text-muted">The standard wording is used unless you change it here.</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Button v-if="wordingOpen && editedKeys.length" type="button" size="sm" variant="ghost" :disabled="wordingLoading" @click="resetWording">Reset to standard wording</Button>
                                    <Button type="button" size="sm" variant="outline" :loading="wordingLoading" @click="toggleWording"><Icon name="pencil" size="sm" />{{ wordingOpen ? 'Hide the editor' : 'Edit the wording' }}</Button>
                                </div>
                            </div>
                            <p v-if="!wordingOpen && editedKeys.length" class="text-xs text-warning">You changed the wording of {{ editedKeys.length }} {{ editedKeys.length === 1 ? 'paragraph' : 'paragraphs' }}. Open the editor to see or undo it.</p>
                            <Alert v-if="wordingError" tone="danger">{{ wordingError }}</Alert>
                            <Alert v-if="bodyError" tone="danger">{{ bodyError }}</Alert>
                            <div v-show="wordingOpen" class="space-y-3">
                                <LetterEditor v-for="[key, label] in EDITABLE" :id="`tr-body-${key}`" :key="key" :label="label" :edited="editedKeys.includes(key)" :model-value="body[key] ?? []" @update:model-value="body[key] = $event" />
                                <p class="text-xs text-text-muted">Select words and use <strong>B</strong> or <em>I</em> (or Ctrl+B, Ctrl+I) to make them bold or italic. Changing the fields above updates the paragraphs you have not edited; a paragraph you edited keeps your wording until you reset it.</p>
                            </div>
                        </div>
                    </div>
                </template>

                <FormGroup v-if="step.remarks" id="wf-remarks" :label="step.remarksLabel ?? 'What needs correcting'" required :error="form.errors.remarks">
                    <Textarea id="wf-remarks" v-model="form.remarks" :rows="4" required :invalid="!!form.errors.remarks" />
                </FormGroup>
                <FormGroup v-else-if="action !== 'acknowledge'" id="wf-note" label="Note (optional)" :error="form.errors.note">
                    <Textarea id="wf-note" v-model="form.note" :rows="3" />
                </FormGroup>

                </div>

                <!-- The letter as it will read -->
                <div v-if="previewing && previewSlots" class="space-y-3">
                    <Alert tone="info">This is how the transmittal will read. Nothing has been made or sent yet. Go back to edit it, or {{ step.button }} to make the letter. Then print it for the Regional Director's signature.</Alert>
                    <div class="mx-auto w-full max-w-xl overflow-hidden rounded border border-border-strong bg-white shadow-sm" style="container-type: inline-size">
                        <div
                            class="text-black"
                            style="aspect-ratio: 210 / 297; background-image: url('/images/transmittal-letterhead.png'); background-size: 100% 100%; background-repeat: no-repeat"
                            role="img"
                            :aria-label="`Preview of the ${letterType === 'approval' ? 'For Approval' : 'For Compliance'} transmittal`"
                        >
                            <div style="padding: 31cqw 12cqw 0; font-family: Arial, Helvetica, sans-serif; font-size: 2.02cqw; line-height: 1.25">
                                <p><Runs :runs="previewSlots.date" /></p>
                                <div style="height: 1.25em" />
                                <p><Runs :runs="previewSlots.name" /></p>
                                <p><Runs :runs="previewSlots.position" /></p>
                                <p v-for="(line, i) in previewSlots.address" :key="i">{{ line }}</p>
                                <div style="height: 1.25em" />
                                <p><Runs :runs="previewSlots.dear" /></p>
                                <div style="height: 1.25em" />
                                <template v-for="key in ['p1', 'p2', 'p3']" :key="key">
                                    <p style="text-align: justify"><Runs :runs="previewSlots[key]" /></p>
                                    <div style="height: 1.25em" />
                                </template>
                                <p><Runs :runs="previewSlots.thanks" /></p>
                                <div style="height: 2.5em" />
                                <p><Runs :runs="previewSlots.closing" /></p>
                                <div style="height: 3.75em" />
                                <p><Runs :runs="previewSlots.signer" /></p>
                                <p><Runs :runs="previewSlots.signer_position" /></p>
                            </div>
                        </div>
                    </div>
                    <p class="text-center text-xs text-text-muted">A close picture of the letter. Fonts and line breaks in the Word file may differ slightly.</p>
                </div>

                <div class="flex flex-wrap justify-end gap-3 pt-2">
                    <Button v-if="previewing" variant="secondary" @click="previewing = false"><Icon name="chevronLeft" size="sm" />Back to edit</Button>
                    <Button v-else variant="secondary" @click="close">Cancel</Button>
                    <Button v-if="action === 'route'" variant="outline" :loading="form.processing" @click="routeToMe">Route to myself</Button>
                    <Button type="submit" :variant="step.variant" :loading="form.processing" :disabled="(action === 'route' && !form.officer_id) || (step.letter && (letterLoading || letterFailed))">{{ step.button }}</Button>
                </div>
            </form>
        </Modal>
    </div>
</template>
