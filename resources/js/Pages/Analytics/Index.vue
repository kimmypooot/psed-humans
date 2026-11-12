<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import KpiCard from '@/Components/UI/KpiCard.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import AreaChart from '@/Components/Charts/AreaChart.vue';
import DonutChart from '@/Components/Charts/DonutChart.vue';
import BarList from '@/Components/Charts/BarList.vue';
import { useListFilters } from '@/Composables/useListFilters';
import { statusColors } from '@/Utils/statusColors';
import { formatDateTime } from '@/Utils/format';

/**
 * Analytics: the headline numbers, the last twelve months, where everything stands, which kinds
 * come in most, how long approvals take and which offices and agencies send the most.
 * Read-only, and only what the signed-in person may see.
 */
const props = defineProps({
    data: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    fieldOffices: { type: Array, default: () => [] },
    scope: { type: String, default: null },
    generatedAt: { type: String, required: true },
});

const filters = useListFilters('/analytics', { type: props.filters.type ?? '', field_office: props.filters.field_office ?? '' });
const officeOptions = computed(() => props.fieldOffices.map((o) => ({ value: String(o.id), label: o.name })));
const k = computed(() => props.data.kpis);
const hasData = computed(() => k.value.total > 0);

const slices = computed(() => props.data.statuses.map((s) => ({ label: s.label, total: s.total, color: statusColors[s.label] ?? '#8a8d9e' })));
const typeRows = computed(() => props.data.types.map((t) => ({ label: t.label, total: t.total, approved: t.approved, hint: t.name })));
const turnaround = computed(() => props.data.turnaround.map((b) => ({ label: b.label, total: b.total })));
const withinTwenty = computed(() => {
    const all = props.data.turnaround.reduce((s, b) => s + b.total, 0);
    return all ? Math.round((props.data.turnaround[0].total / all) * 100) : null;
});

const insight = computed(() => {
    if (!hasData.value) return null;
    const parts = [`${k.value.approved.toLocaleString()} of ${k.value.total.toLocaleString()} mechanisms are approved (${k.value.approval_rate}%).`];
    if (k.value.avg_days_to_approval !== null) parts.push(`An approval takes ${k.value.median_days_to_approval} days at the median.`);
    if (k.value.overdue > 0) parts.push(`${k.value.overdue} ${k.value.overdue === 1 ? 'is' : 'are'} past the 20-working-day turnaround.`);
    else parts.push('None is past the 20-working-day turnaround.');
    return parts.join(' ');
});
</script>

<template>
    <Head title="Analytics" />

    <StaffLayout>
        <PageHeader title="Analytics" description="How HR mechanisms are moving: volume, status, kinds and turnaround." :breadcrumbs="[{ label: 'Insights' }, { label: 'Analytics' }]">
            <template #actions>
                <Button variant="outline" href="/reports"><Icon name="documentText" size="sm" />Open reports</Button>
            </template>
        </PageHeader>

        <!-- Scope and filters, on a soft brand band. -->
        <div class="relative z-20 mb-6 rounded-xl border border-border bg-gradient-to-br from-csc-blue via-csc-blue to-csc-blue-light text-white">
            <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4 p-5 sm:p-6">
                <div class="min-w-0 max-w-2xl">
                    <p class="text-xs font-semibold tracking-widest text-white/70 uppercase">{{ scope }}</p>
                    <p v-if="insight" class="mt-2 text-lg leading-snug font-medium text-balance sm:text-xl">{{ insight }}</p>
                    <p v-else class="mt-2 text-lg font-medium">Nothing matches these filters yet.</p>
                    <p class="mt-3 text-xs text-white/60">Updated {{ formatDateTime(generatedAt) }} · counts the latest attempt of each mechanism</p>
                </div>
                <div class="flex flex-wrap gap-3 text-text">
                    <div class="w-60">
                        <label for="a-type" class="mb-1 block text-xs font-medium text-white/80">HR mechanism</label>
                        <Combobox id="a-type" v-model="filters.type" :options="types" :allow-custom="false" placeholder="All kinds" />
                    </div>
                    <div v-if="officeOptions.length" class="w-56">
                        <label for="a-office" class="mb-1 block text-xs font-medium text-white/80">Field office</label>
                        <Combobox id="a-office" v-model="filters.field_office" :options="officeOptions" :allow-custom="false" placeholder="All offices" />
                    </div>
                </div>
            </div>
        </div>

        <ul class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Key numbers">
            <li><KpiCard label="Submitted" :value="k.total" icon="clipboard" tone="info" :delta="k.submitted_this_month - k.submitted_last_month" /></li>
            <li><KpiCard label="Approved" :value="k.approved" icon="checkCircle" tone="success" :hint="`${k.approval_rate}% of all submissions`" /></li>
            <li><KpiCard label="In process" :value="k.in_process" icon="activity" tone="info" hint="From received to signing" /></li>
            <li><KpiCard label="For compliance" :value="k.for_compliance" icon="exclamation" tone="danger" hint="Sent back, waiting on the agency" /></li>
            <li><KpiCard label="Overdue" :value="k.overdue" icon="clock" :tone="k.overdue ? 'danger' : 'success'" :hint="k.overdue ? 'Past the 20-working-day turnaround' : 'Everything is within the turnaround'" /></li>
            <li>
                <KpiCard
                    label="Time to approval"
                    :value="k.median_days_to_approval ?? '—'"
                    :suffix="k.median_days_to_approval !== null ? 'days' : null"
                    icon="calendar"
                    tone="warning"
                    :hint="k.avg_days_to_approval !== null ? `Median. Average ${k.avg_days_to_approval} days` : 'No approvals yet'"
                />
            </li>
        </ul>

        <div class="mb-6 grid gap-6 xl:grid-cols-5">
            <Card class="xl:col-span-3" title="Submissions over time" icon="activity" description="Last 12 months">
                <template #actions>
                    <span class="flex items-center gap-4 text-xs text-text-muted">
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-chart-1" />Submitted</span>
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-success" />Approved</span>
                    </span>
                </template>
                <AreaChart :points="data.trend" />
            </Card>

            <Card class="xl:col-span-2" title="Where everything stands" icon="grid" description="By status">
                <DonutChart :slices="slices" center-label="mechanisms" />
            </Card>
        </div>

        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            <Card title="By kind of HR mechanism" icon="documentText" description="Approved share shown in green">
                <BarList :rows="typeRows" />
            </Card>

            <Card title="How long approvals take" icon="clock" description="Days from submission to approval">
                <BarList :rows="turnaround" single />
                <p v-if="withinTwenty !== null" class="mt-4 rounded-lg bg-surface-muted px-3 py-2 text-xs text-text-muted">
                    <b class="text-text">{{ withinTwenty }}%</b> of approvals were done within 20 days of submission.
                </p>
            </Card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card v-if="fieldOffices.length" title="By field office" icon="building" description="Most submissions first">
                <BarList :rows="data.offices" />
            </Card>
            <Card :class="fieldOffices.length ? '' : 'lg:col-span-2'" title="Agencies that submit the most" icon="users" description="Top 8 by number of submissions">
                <BarList :rows="data.agencies" />
            </Card>
        </div>
    </StaffLayout>
</template>
