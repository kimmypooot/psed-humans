<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import Table from '@/Components/UI/Table.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import StatusBadge from '@/Components/Mechanisms/StatusBadge.vue';
import { useListFilters } from '@/Composables/useListFilters';
import { statusColors } from '@/Utils/statusColors';
import { formatDate, formatDateTime } from '@/Utils/format';

/**
 * Reports: pick a period and what to include, read the counts by kind and status, scan the list,
 * then print it or take it as a CSV. Printing hides the menus and filters and adds a letterhead.
 */
const props = defineProps({
    rows: { type: Object, required: true },
    report: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    types: { type: Array, required: true },
    fieldOffices: { type: Array, default: () => [] },
    scope: { type: String, default: null },
    preparedBy: { type: Object, required: true },
    generatedAt: { type: String, required: true },
});

const filters = useListFilters('/reports', {
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    status: props.filters.status ?? '',
    type: props.filters.type ?? '',
    field_office: props.filters.field_office ?? '',
});

const officeOptions = computed(() => props.fieldOffices.map((o) => ({ value: String(o.id), label: o.name })));
const active = computed(() => Object.values(filters).some((v) => v !== '' && v !== null));
const exportUrl = computed(() => {
    const q = new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== '' && v !== null));
    return `/reports/export${q.size ? `?${q}` : ''}`;
});
const clear = () => Object.keys(filters).forEach((k) => (filters[k] = ''));

const totals = computed(() => props.report.totals);
const approved = computed(() => totals.value.Approved ?? 0);
const period = computed(() => {
    if (filters.from && filters.to) return `${formatDate(filters.from)} to ${formatDate(filters.to)}`;
    if (filters.from) return `From ${formatDate(filters.from)}`;
    if (filters.to) return `Up to ${formatDate(filters.to)}`;
    return 'All dates';
});
// Short column titles so the matrix fits; the full name is on hover.
const short = { 'Received by PSED': 'Received', 'Routed to Action Officer': 'Routed', 'Processing/Evaluating': 'Processing', 'For PSED Chief Review': 'Chief review', 'Reviewed by PSED Chief': 'Reviewed', 'For Compliance': 'Compliance', Approved: 'Approved' };
const print = () => window.print();
</script>

<template>
    <Head title="Reports" />

    <StaffLayout>
        <PageHeader title="Reports" description="Counts by kind and status for any period, ready to print or export." :breadcrumbs="[{ label: 'Insights' }, { label: 'Reports' }]">
            <template #actions>
                <Button variant="outline" class="print:hidden" @click="print"><Icon name="printer" size="sm" />Print</Button>
                <Button :href="exportUrl" external class="print:hidden"><Icon name="download" size="sm" />Export CSV</Button>
            </template>
        </PageHeader>

        <!-- The letterhead: only on paper. -->
        <div class="mb-6 hidden border-b-2 border-primary pb-4 print:block">
            <p class="text-xs font-semibold tracking-widest text-text-muted uppercase">Civil Service Commission · Regional Office VIII</p>
            <h1 class="text-2xl font-bold text-primary">HR Mechanisms Report</h1>
            <p class="mt-1 text-sm text-text-muted">Policies and Systems Evaluation Division</p>
        </div>

        <div class="mb-6 rounded-xl border border-border bg-surface p-4 print:hidden sm:p-5">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="r-from" class="block text-sm font-medium text-text">Submitted from</label>
                    <input id="r-from" v-model="filters.from" type="date" :max="filters.to || undefined" class="mt-1 h-10 rounded-md border border-border-strong bg-surface px-3 text-sm text-text focus:border-primary focus:ring-2 focus:ring-focus-ring/30 focus:outline-none" />
                </div>
                <div>
                    <label for="r-to" class="block text-sm font-medium text-text">to</label>
                    <input id="r-to" v-model="filters.to" type="date" :min="filters.from || undefined" class="mt-1 h-10 rounded-md border border-border-strong bg-surface px-3 text-sm text-text focus:border-primary focus:ring-2 focus:ring-focus-ring/30 focus:outline-none" />
                </div>
                <div class="w-52">
                    <label for="r-status" class="block text-sm font-medium text-text">Status</label>
                    <Combobox id="r-status" v-model="filters.status" :options="statuses" :allow-custom="false" placeholder="All statuses" />
                </div>
                <div class="w-56">
                    <label for="r-type" class="block text-sm font-medium text-text">HR mechanism</label>
                    <Combobox id="r-type" v-model="filters.type" :options="types" :allow-custom="false" placeholder="All kinds" />
                </div>
                <div v-if="officeOptions.length" class="w-56">
                    <label for="r-office" class="block text-sm font-medium text-text">Field office</label>
                    <Combobox id="r-office" v-model="filters.field_office" :options="officeOptions" :allow-custom="false" placeholder="All offices" />
                </div>
                <Button v-if="active" variant="ghost" @click="clear"><Icon name="close" size="sm" />Clear</Button>
            </div>
        </div>

        <!-- What this report covers. -->
        <dl class="mb-6 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="inline text-text-muted">Scope: </dt><dd class="inline font-medium text-text">{{ scope }}</dd></div>
            <div><dt class="inline text-text-muted">Period: </dt><dd class="inline font-medium text-text">{{ period }}</dd></div>
            <div><dt class="inline text-text-muted">Prepared by: </dt><dd class="inline font-medium text-text">{{ preparedBy.name }}, {{ preparedBy.role }}</dd></div>
            <div><dt class="inline text-text-muted">Generated: </dt><dd class="inline font-medium text-text">{{ formatDateTime(generatedAt) }}</dd></div>
        </dl>

        <ul class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Report totals">
            <li class="rounded-xl border border-border bg-surface p-4">
                <p class="text-xs font-medium tracking-wide text-text-muted uppercase">Mechanisms</p>
                <p class="mt-1 text-3xl font-semibold text-text tabular-nums">{{ totals.all.toLocaleString() }}</p>
            </li>
            <li class="rounded-xl border border-success-border bg-success-bg p-4">
                <p class="text-xs font-medium tracking-wide text-success uppercase">Approved</p>
                <p class="mt-1 text-3xl font-semibold text-success tabular-nums">{{ approved.toLocaleString() }}</p>
            </li>
            <li class="rounded-xl border border-info-border bg-info-bg p-4">
                <p class="text-xs font-medium tracking-wide text-info uppercase">Approval rate</p>
                <p class="mt-1 text-3xl font-semibold text-info tabular-nums">{{ totals.all ? Math.round((approved / totals.all) * 100) : 0 }}%</p>
            </li>
            <li class="rounded-xl border border-danger-border bg-danger-bg p-4">
                <p class="text-xs font-medium tracking-wide text-danger uppercase">For compliance</p>
                <p class="mt-1 text-3xl font-semibold text-danger tabular-nums">{{ (totals['For Compliance'] ?? 0).toLocaleString() }}</p>
            </li>
        </ul>

        <Card class="mb-6" title="Summary by kind and status" icon="grid" description="Each row adds up to its total">
            <div v-if="report.matrix.length" class="-mx-5 overflow-x-auto px-5">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead>
                        <tr class="border-b border-border text-xs tracking-wide text-text-muted uppercase">
                            <th class="py-2 pr-4 font-semibold">HR mechanism</th>
                            <th v-for="s in report.statuses" :key="s" class="px-2 py-2 text-right font-semibold" :title="s">
                                <span class="mr-1 inline-block size-2 rounded-full align-middle" :style="{ backgroundColor: statusColors[s] }" />{{ short[s] }}
                            </th>
                            <th class="py-2 pl-3 text-right font-semibold">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in report.matrix" :key="r.label" class="border-b border-border last:border-0 hover:bg-surface-muted/60">
                            <th scope="row" class="py-2.5 pr-4 font-medium text-text" :title="r.name">{{ r.label }}</th>
                            <td v-for="s in report.statuses" :key="s" class="px-2 py-2.5 text-right tabular-nums" :class="r.cells[s] ? 'font-medium text-text' : 'text-text-muted/50'">{{ r.cells[s] || '–' }}</td>
                            <td class="py-2.5 pl-3 text-right font-semibold text-primary tabular-nums">{{ r.total }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-border bg-surface-muted/60 font-semibold">
                            <th scope="row" class="py-2.5 pr-4">Total</th>
                            <td v-for="s in report.statuses" :key="s" class="px-2 py-2.5 text-right tabular-nums">{{ totals[s] }}</td>
                            <td class="py-2.5 pl-3 text-right text-primary tabular-nums">{{ totals.all }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <EmptyState v-else title="No HR mechanisms in this report" description="Try a wider period or clear the filters." icon="documentText" />
        </Card>

        <Table :empty="rows.data.length === 0" class="print:border-0">
            <template #empty>
                <EmptyState title="Nothing to list" description="No HR mechanisms match these filters." icon="clipboard" />
            </template>
            <thead>
                <tr>
                    <th>Tracking code</th>
                    <th>Agency</th>
                    <th>HR mechanism</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Approved</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in rows.data" :key="m.id">
                    <td class="font-mono text-xs whitespace-nowrap">{{ m.tracking_code }}</td>
                    <td>
                        <p class="font-medium">{{ m.agency }}</p>
                        <p v-if="m.field_office" class="text-xs text-text-muted">{{ m.field_office }}</p>
                    </td>
                    <td>{{ m.label }}</td>
                    <td><StatusBadge :status="m.status" :tone="m.tone" /></td>
                    <td class="whitespace-nowrap">{{ formatDate(m.date_submitted) }}</td>
                    <td class="whitespace-nowrap">{{ formatDate(m.date_approved) }}</td>
                </tr>
            </tbody>
        </Table>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-text-muted">Showing {{ rows.from ?? 0 }}–{{ rows.to ?? 0 }} of {{ rows.total.toLocaleString() }}. Print shows this page; the CSV has every row.</p>
            <Pagination class="print:hidden" :prev-url="rows.prev_page_url" :next-url="rows.next_page_url" :current-page="rows.current_page" :last-page="rows.last_page" />
        </div>
    </StaffLayout>
</template>
