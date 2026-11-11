<script setup>
/**
 * A plain text-like field for use inside a FormGroup (which supplies the label, helper text and
 * error). Same look as the other controls.
 */
defineProps({
    id: { type: String, required: true },
    modelValue: { type: String, default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: null },
    autocomplete: { type: String, default: 'off' },
    maxlength: { type: Number, default: 255 },
    required: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    inputmode: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <input
        :id="id"
        :value="modelValue"
        :type="type"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :maxlength="maxlength"
        :required="required"
        :inputmode="inputmode"
        :aria-invalid="invalid || undefined"
        class="block w-full rounded-md border bg-surface px-3 py-2 text-sm text-text shadow-xs placeholder:text-text-muted/70 focus:border-primary focus:ring-2 focus:ring-focus-ring focus:outline-none aria-invalid:border-danger"
        :class="invalid ? 'border-danger' : 'border-border-strong'"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
