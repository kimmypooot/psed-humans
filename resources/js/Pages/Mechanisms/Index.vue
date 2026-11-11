<script setup>
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Table from '@/Components/UI/Table.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchBox from '@/Components/Forms/SearchBox.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import StatusBadge from '@/Components/Mechanisms/StatusBadge.vue';
import MechanismDetailsModal from '@/Components/Mechanisms/MechanismDetailsModal.vue';
import TurnaroundChip from '@/Components/Mechanisms/TurnaroundChip.vue';
import WorkflowActions from '@/Components/Mechanisms/WorkflowActions.vue';
import { useListFilters } from '@/Composables/useListFilters';
import { formatDate } from '@/Utils/format';

/** Every mechanism the person may see, filtered by status, kind, office or a search. */
const props = defineProps({
    mechanisms: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    types: { type: Array, required: true },
    fieldOffices: { type: Array, default: () => [] },
});

// A notification links here with ?open=<id>: that mechanism's details open over the list, also when this page is already showing.
const viewing = ref(props.filters.open ? Number(props.filters.open) : null);
watch(
    () => props.filters.open,
    (id) => {
        if (id) viewing.value = Number(id);
    },
);

const filters = useListFilters('/mechanisms', {
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    type: props.filters.type ?? '',
    field_office: props.filters.field_office ?? '',
    mine: props.filters.mine ? 1 : '',
});
const isPsed = computed(() => usePage().props.auth.user.sees_all_offices);

const officeOptions = computed(() => props.fieldOffices.map((o) => ({ value: String(o.id), label: o.name })));
const rows = computed(() => props.mechanisms.data);
</script>

<template>
    <Head title="HR Mechanisms" />

    <StaffLayout>
        <PageHeader title="HR Mechanisms" description="Every submission and where it stands." :breadcrumbs="[{ label: 'HR Mechanisms' }]">
            <template #actions>
                <Button variant="outline" href="/" external><Icon name="externalLink" size="sm" />Public submission page</Button>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <SearchBox v-model="filters.search" placeholder="Search agency, mechanism or tracking code" label="Search mechanisms" />
            <div class="w-52">
                <label for="f-status" class="block text-sm font-medium text-text">Status</label>
                <Combobox id="f-status" v-model="filters.status" :options="statuses" :allow-custom="false" placeholder="All statuses" />
            </div>
            <div class="w-56">
                <label for="f-type" class="block text-sm font-medium text-text">HR mechanism</label>
                <Combobox id="f-type" v-model="filters.type" :options="types" :allow-custom="false" placeholder="All kinds" />
            </div>
            <label v-if="isPsed" class="flex items-center gap-2 pb-2 text-sm font-medium text-text">
                <input v-model="filters.mine" type="checkbox" :true-value="1" false-value="" class="size-4 rounded border-border-strong text-primary focus:ring-focus-ring" />
                Routed to me
            </label>
            <div v-if="officeOptions.length" class="w-56">
                <label for="f-office" class="block text-sm font-medium text-text">Field office</label>
                <Combobox id="f-office" v-model="filters.field_office" :options="officeOptions" :allow-custom="false" placeholder="All offices" />
            </div>
        </div>

        <Table :empty="rows.length === 0">
            <template #empty>
                <EmptyState title="No HR mechanisms found" description="Nothing matches. Try a different search or clear the filters." icon="clipboard" />
            </template>
            <thead>
                <tr>
                    <th>Tracking code</th>
                    <th>Agency</th>
                    <th>HR mechanism</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in rows" :key="m.id">
                    <td class="font-mono text-xs whitespace-nowrap">{{ m.tracking_code }}</td>
                    <td>
                        <p class="font-medium">{{ m.agency }}</p>
                        <p v-if="m.field_office" class="text-xs text-text-muted">{{ m.field_office }}</p>
                    </td>
                    <td>{{ m.label }}</td>
                    <td>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <StatusBadge :status="m.status" :tone="m.tone" />
                            <TurnaroundChip :turnaround="m.turnaround" />
                        </div>
                        <p v-if="m.officer" class="mt-1 text-xs text-text-muted">{{ m.is_mine ? 'You' : m.officer }}</p>
                    </td>
                    <td class="whitespace-nowrap">{{ formatDate(m.date_submitted) }}</td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <WorkflowActions :mechanism="m" />
                            <Button size="sm" variant="outline" @click="viewing = m.id"><Icon name="eye" size="sm" />View</Button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </Table>

        <Pagination
            class="mt-4"
            :prev-url="mechanisms.prev_page_url"
            :next-url="mechanisms.next_page_url"
            :current-page="mechanisms.current_page"
            :last-page="mechanisms.last_page"
        />

        <MechanismDetailsModal :id="viewing" @close="viewing = null" />
    </StaffLayout>
</template>
