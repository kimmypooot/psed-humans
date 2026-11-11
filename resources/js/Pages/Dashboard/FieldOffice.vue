<script setup>
import { Head, Link } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatusTiles from '@/Components/Mechanisms/StatusTiles.vue';
import { toneClasses } from '@/Components/UI/tones';
import { formatDate } from '@/Utils/format';

/** Read-only: where each agency of the office stands. Nothing here changes a mechanism. */
defineProps({
    office: { type: String, default: null },
    counts: { type: Array, required: true },
    agencies: { type: Array, required: true },
});

const pill = (tone, n) => (n > 0 ? toneClasses[tone] : 'text-text-muted');

// The five counts, in the order a mechanism goes, as the columns of the table and the bar under the name.
const columns = [
    { key: 'received', label: 'Received', tone: 'neutral', bar: 'bg-chart-neutral' },
    { key: 'in_process', label: 'In process', tone: 'info', bar: 'bg-chart-1' },
    { key: 'for_compliance', label: 'For Compliance', tone: 'danger', bar: 'bg-danger' },
    { key: 'reviewed', label: 'Reviewed by Chief', tone: 'warning', bar: 'bg-warning' },
    { key: 'approved', label: 'Approved', tone: 'success', bar: 'bg-success' },
];
const share = (a, key) => (a.total ? (a[key] / a.total) * 100 : 0);
</script>

<template>
    <Head title="Dashboard" />

    <StaffLayout>
        <PageHeader :title="office ?? 'Field Office'" description="The HR mechanisms of the agencies in your office, as PSED has them. View only.">
            <template #actions>
                <Button variant="outline" href="/mechanisms">All mechanisms</Button>
            </template>
        </PageHeader>

        <StatusTiles :counts="counts" />

        <Card title="Agencies" icon="building">
            <Table :empty="agencies.length === 0">
                <template #empty>
                    <EmptyState title="No agencies" description="No agencies are set up for your office yet. Please contact PSED." icon="building" />
                </template>
                <thead>
                    <tr>
                        <th>Agency</th>
                        <th>Sector</th>
                        <th v-for="c in columns" :key="c.key" class="text-center">{{ c.label }}</th>
                        <th>Last activity</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in agencies" :key="a.id">
                        <td class="min-w-56 font-medium">
                            <Link :href="`/mechanisms?search=${encodeURIComponent(a.name)}`" class="text-primary hover:underline">{{ a.name }}</Link>
                            <!-- Where this agency's mechanisms stand, at a glance. -->
                            <div v-if="a.total" class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-surface-muted" role="img" :aria-label="`${a.total} in total: ${columns.map((c) => `${a[c.key]} ${c.label}`).join(', ')}`">
                                <span v-for="c in columns" :key="c.key" class="h-full" :class="c.bar" :style="{ width: share(a, c.key) + '%' }" />
                            </div>
                        </td>
                        <td>{{ a.sector ?? '—' }}</td>
                        <td v-for="c in columns" :key="c.key" class="text-center"><span class="badge" :class="pill(c.tone, a[c.key])">{{ a[c.key] }}</span></td>
                        <td class="whitespace-nowrap">{{ formatDate(a.last_activity) }}</td>
                    </tr>
                </tbody>
            </Table>
        </Card>
    </StaffLayout>
</template>
