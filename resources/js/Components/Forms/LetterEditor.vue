<script setup>
import { onMounted, ref, watch } from 'vue';

/**
 * A small text editor for one paragraph of a letter: type, and make words bold or italic (buttons, or
 * Ctrl+B / Ctrl+I). The value is a list of runs, [text] or [text, 'b' | 'i' | 'bi'], the same shape the
 * server writes the Word file from. Pasting keeps the text only, and Enter does nothing: it is one paragraph.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    id: { type: String, required: true },
    label: { type: String, required: true },
    edited: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const box = ref(null);
let last = '';

const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const toHtml = (runs) =>
    (runs ?? [])
        .map(([text, style]) => {
            let html = escape(text ?? '');
            if (style?.includes('i')) html = `<i>${html}</i>`;
            if (style?.includes('b')) html = `<b>${html}</b>`;

            return html;
        })
        .join('');

// Walks what was typed and gathers runs, with the bold and italic in force at each piece of text.
const toRuns = (root) => {
    const runs = [];

    const walk = (node, bold, italic) => {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = node.textContent.replace(/\s+/g, ' ');
            if (text === '') return;

            const style = (bold ? 'b' : '') + (italic ? 'i' : '');
            const previous = runs[runs.length - 1];

            if (previous && (previous[1] ?? '') === style) previous[0] += text;
            else runs.push(style ? [text, style] : [text]);

            return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) return;

        const tag = node.tagName.toLowerCase();
        const css = node.style ?? {};
        const isBold = bold || tag === 'b' || tag === 'strong' || css.fontWeight === 'bold' || Number(css.fontWeight) >= 600;
        const isItalic = italic || tag === 'i' || tag === 'em' || css.fontStyle === 'italic';

        if (tag === 'br') {
            walk(document.createTextNode(' '), isBold, isItalic);

            return;
        }

        node.childNodes.forEach((child) => walk(child, isBold, isItalic));
    };

    root.childNodes.forEach((child) => walk(child, false, false));

    return runs;
};

const render = (runs) => {
    if (box.value) box.value.innerHTML = toHtml(runs);
};

const onInput = () => {
    const runs = toRuns(box.value);
    last = JSON.stringify(runs);
    emit('update:modelValue', runs);
};

const format = (command) => {
    box.value?.focus();
    document.execCommand('styleWithCSS', false, false);
    document.execCommand(command);
    onInput();
};

const onPaste = (event) => {
    event.preventDefault();
    document.execCommand('insertText', false, (event.clipboardData?.getData('text/plain') ?? '').replace(/\s+/g, ' '));
};

onMounted(() => {
    last = JSON.stringify(props.modelValue);
    render(props.modelValue);
});

// Changed from outside (the standard wording came back, or was reset): show it. Typing in the box is not re-rendered.
watch(
    () => props.modelValue,
    (runs) => {
        if (JSON.stringify(runs) !== last) {
            last = JSON.stringify(runs);
            render(runs);
        }
    },
    { deep: true },
);

const button = 'rounded border border-border-strong px-2 py-0.5 text-xs text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <div class="space-y-1">
        <div class="flex items-center justify-between gap-2">
            <label :id="`${id}-label`" :for="id" class="text-xs font-medium text-text">
                {{ label }}
                <span v-if="edited" class="ml-1 rounded border border-warning-border bg-warning-bg px-1.5 py-0.5 text-[10px] font-semibold text-warning">Edited</span>
            </label>
            <div class="flex gap-1" role="group" :aria-label="`Format ${label}`">
                <button type="button" :class="button" class="font-bold" aria-label="Bold" title="Bold (Ctrl+B)" @mousedown.prevent @click="format('bold')">B</button>
                <button type="button" :class="button" class="italic" aria-label="Italic" title="Italic (Ctrl+I)" @mousedown.prevent @click="format('italic')">I</button>
            </div>
        </div>
        <div
            :id="id"
            ref="box"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            :aria-labelledby="`${id}-label`"
            spellcheck="true"
            class="min-h-10 w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm leading-relaxed text-text shadow-xs focus:border-primary focus:ring-2 focus:ring-focus-ring focus:outline-none"
            @input="onInput"
            @paste="onPaste"
            @keydown.enter.prevent
        />
    </div>
</template>
