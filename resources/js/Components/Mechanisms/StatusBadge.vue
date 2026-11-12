<script setup>
import Badge from '@/Components/UI/Badge.vue';
import { breakable } from '@/Utils/format';

/**
 * The status of an HR mechanism as a badge: grey Received, blue while PSED works on it, red For Compliance,
 * orange Transmittal (Approved) (waiting for the signed copies), green Approved. The label is always written out.
 */
defineProps({
    status: { type: String, required: true },
    tone: { type: String, required: true },
});
</script>

<template>
    <Badge :tone="tone" class="max-w-full text-left">{{ breakable(status) }}</Badge>
</template>
