<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import FieldInput from '@/Components/Forms/FieldInput.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Textarea from '@/Components/Forms/Textarea.vue';

/**
 * The public page where an agency submits an HR mechanism. No account: the agency picks itself
 * from the list psed-app keeps, attaches the file and leaves an email address for the updates.
 */
defineProps({
    agencies: { type: Array, required: true },
    types: { type: Array, required: true },
    maxMb: { type: Number, default: 20 },
});

const form = useForm({
    agency_id: '',
    mechanism_type: '',
    title: '',
    contact_name: '',
    contact_email: '',
    note: '',
    document: null,
    website: '',
});

const fileKey = ref(0);
const send = () => form.post('/submit', { forceFormData: true, preserveScroll: true, onError: () => (fileKey.value += 0) });

const how = [
    { title: 'You submit', text: 'Pick your agency, attach the file and leave your email. We email you a receipt right away.' },
    { title: 'PSED evaluates', text: 'It is routed to an Action Officer, who evaluates it within 20 working days.' },
    { title: 'The PSED Chief reviews', text: 'If approved, we email you the reviewed HR mechanism, the transmittal and the HR checklist. If it needs corrections, we email them too, with what to fix.' },
    { title: 'Print, sign, approved', text: 'Print three (3) copies for the Head of Agency to sign. The Regional Office gives the final approval when it has them.' },
];
</script>

<template>
    <Head title="Submit an HR mechanism" />

    <PublicLayout>
        <template #hero>
            <div class="mx-auto max-w-6xl px-4 pt-8 pb-28 sm:px-6 sm:pt-12 sm:pb-32">
                <p class="text-xs font-semibold tracking-widest text-white/70 uppercase">Civil Service Commission · Regional Office VIII</p>
                <h1 class="mt-2 max-w-2xl text-3xl leading-tight font-bold text-balance sm:text-4xl">Submit your HR mechanism</h1>
                <p class="mt-3 max-w-2xl text-base leading-relaxed text-white/75 text-pretty">
                    Human Resource Mechanisms Assessment and Network Documentation System. No account needed: send the file, leave your email, and follow it every step of the way.
                </p>
            </div>
        </template>

        <div class="-mt-20 grid gap-6 sm:-mt-24 lg:grid-cols-[minmax(0,1fr)_21rem]">
            <form class="@container rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-8" novalidate @submit.prevent="send">
                <Alert v-if="Object.keys(form.errors).length" tone="danger" class="mb-6" title="Please check the form">
                    Some answers need your attention. They are marked below.
                </Alert>

                <!-- 1 -->
                <fieldset class="space-y-5">
                    <legend class="mb-4 flex items-center gap-3 text-base font-semibold text-text">
                        <span class="grid size-7 place-items-center rounded-full bg-primary text-sm text-white" aria-hidden="true">1</span>
                        Your agency and document
                    </legend>

                    <FormGroup id="agency" label="Agency" required :error="form.errors.agency_id" helper-text="Type to search the list. Agencies are grouped by the Field Office that oversees them.">
                        <Combobox
                            id="agency"
                            v-model="form.agency_id"
                            :options="agencies"
                            :allow-custom="false"
                            :invalid="!!form.errors.agency_id"
                            placeholder="Search for your agency"
                            :max-visible="400"
                        />
                    </FormGroup>

                    <div class="grid gap-5 @2xl:grid-cols-2">
                        <FormGroup id="type" label="Kind of HR mechanism" required :error="form.errors.mechanism_type">
                            <Combobox id="type" v-model="form.mechanism_type" :options="types" :allow-custom="false" :invalid="!!form.errors.mechanism_type" placeholder="Choose one" />
                        </FormGroup>
                        <FormGroup id="title" label="Name of the document" :error="form.errors.title" helper-text="Optional. For example: Revised Rewards and Recognition System.">
                            <FieldInput id="title" v-model="form.title" :invalid="!!form.errors.title" />
                        </FormGroup>
                    </div>

                    <FormGroup id="file" label="File" required :error="form.errors.document">
                        <FileInput
                            id="file"
                            :key="fileKey"
                            accept=".docx"
                            :max-size-mb="maxMb"
                            :invalid="!!form.errors.document"
                            @update:model-value="form.document = $event"
                        />
                    </FormGroup>
                </fieldset>

                <hr class="my-8 border-border" />

                <!-- 2 -->
                <fieldset class="space-y-5">
                    <legend class="mb-4 flex items-center gap-3 text-base font-semibold text-text">
                        <span class="grid size-7 place-items-center rounded-full bg-primary text-sm text-white" aria-hidden="true">2</span>
                        Where we send the updates
                    </legend>

                    <div class="grid gap-5 @2xl:grid-cols-2">
                        <FormGroup id="email" label="Email address" required :error="form.errors.contact_email" helper-text="We email you each time the status changes, with a link to your tracking page.">
                            <FieldInput id="email" v-model="form.contact_email" type="email" autocomplete="email" inputmode="email" placeholder="name@agency.gov.ph" :invalid="!!form.errors.contact_email" />
                        </FormGroup>
                        <FormGroup id="name" label="Contact person" :error="form.errors.contact_name" helper-text="Optional.">
                            <FieldInput id="name" v-model="form.contact_name" autocomplete="name" :invalid="!!form.errors.contact_name" />
                        </FormGroup>
                    </div>

                    <FormGroup id="note" label="Note for PSED" :error="form.errors.note" helper-text="Optional.">
                        <Textarea id="note" v-model="form.note" :rows="3" />
                    </FormGroup>

                    <!-- Only a script fills this in: people never see it. -->
                    <div class="absolute -left-[9999px]" aria-hidden="true">
                        <label for="website">Leave this empty</label>
                        <input id="website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
                    </div>
                </fieldset>

                <div class="mt-8 flex flex-col-reverse items-stretch gap-3 border-t border-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-text-muted">Your email is used only to send you updates on this submission.</p>
                    <Button type="submit" :loading="form.processing" :disabled="form.processing">
                        <Icon name="upload" size="sm" />Submit to PSED
                    </Button>
                </div>
            </form>

            <aside class="space-y-6 lg:pt-1">
                <section class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                    <h2 class="text-sm font-semibold tracking-wide text-text uppercase">How it works</h2>
                    <ol class="mt-4 space-y-5">
                        <li v-for="(s, i) in how" :key="s.title" class="relative flex gap-3 pb-0 not-last:before:absolute not-last:before:top-8 not-last:before:-bottom-4 not-last:before:left-[0.9375rem] not-last:before:w-0.5 not-last:before:bg-border">
                            <span class="relative z-10 grid size-8 shrink-0 place-items-center rounded-full border-2 border-primary bg-surface text-sm font-semibold text-primary">{{ i + 1 }}</span>
                            <div>
                                <p class="text-sm font-semibold text-text">{{ s.title }}</p>
                                <p class="text-sm leading-snug text-text-muted">{{ s.text }}</p>
                            </div>
                        </li>
                    </ol>
                </section>

                <section class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-text">
                        <Icon name="search" size="sm" class="text-primary" />Already submitted?
                    </h2>
                    <p class="mt-1 text-sm text-text-muted">Follow it on the tracking page with the link we emailed you, or with your tracking code.</p>
                    <Button variant="outline" size="sm" href="/track" class="mt-3">Track my submission</Button>
                </section>
            </aside>
        </div>
    </PublicLayout>
</template>
