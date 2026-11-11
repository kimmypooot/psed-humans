<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import FieldInput from '@/Components/Forms/FieldInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';

/** Finds a submission from its tracking code and the email address left on it. */
const form = useForm({ tracking_code: '', contact_email: '' });
const find = () => form.post('/track');
</script>

<template>
    <Head title="Track an HR mechanism" />

    <PublicLayout>
        <div class="mx-auto mt-10 max-w-lg sm:mt-16">
            <div class="mb-6 text-center">
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-info-bg text-primary ring-8 ring-surface-muted" aria-hidden="true">
                    <Icon name="search" size="md" />
                </span>
                <h1 class="mt-4 text-2xl font-bold text-primary text-balance">Track your HR mechanism</h1>
                <p class="mt-2 text-sm text-text-muted text-pretty">See where it stands, what PSED said and what to do next. Enter the tracking code from your confirmation email and the email address you used.</p>
            </div>

            <form class="space-y-5 rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-7" @submit.prevent="find">
                <FormGroup id="code" label="Tracking code" required :error="form.errors.tracking_code">
                    <FieldInput id="code" v-model="form.tracking_code" placeholder="HRM-2027-00001" :maxlength="40" :invalid="!!form.errors.tracking_code" class="font-mono uppercase" />
                </FormGroup>
                <FormGroup id="mail" label="Email address" required :error="form.errors.contact_email">
                    <FieldInput id="mail" v-model="form.contact_email" type="email" autocomplete="email" inputmode="email" :invalid="!!form.errors.contact_email" />
                </FormGroup>
                <Button type="submit" class="w-full" :loading="form.processing" :disabled="!form.tracking_code || !form.contact_email">
                    <Icon name="search" size="sm" />Find my submission
                </Button>
            </form>

            <p class="mt-5 text-center text-sm text-text-muted">
                Have not submitted yet? <a href="/" class="font-medium text-primary hover:underline">Submit an HR mechanism</a>
            </p>
        </div>
    </PublicLayout>
</template>
