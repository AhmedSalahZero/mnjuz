<template>
    <div>
        <label v-if="label" class="block text-xs text-slate-600 mb-1">{{ label }}</label>
        <textarea v-if="multiline" ref="field" :value="modelValue" :rows="rows" :maxlength="maxlength || undefined" dir="auto"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-slate-400"
            @input="emit('update:modelValue', $event.target.value)"></textarea>
        <input v-else ref="field" type="text" :value="modelValue" :maxlength="maxlength || undefined" :dir="dir"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-slate-400"
            :placeholder="placeholder"
            @input="emit('update:modelValue', $event.target.value)" />
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>

        <div v-if="chips.length" class="mt-2">
            <div class="mb-1 text-[11px] text-slate-500">{{ $t('Click a variable to insert it at the cursor.') }}</div>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="chip in chips" :key="chip.value" type="button"
                    class="rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                    @mousedown.prevent
                    @click="insert(chip.value)">
                    {{ chip.label }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * نصّ أو سطر مع شرائح المتغيّرات: الضغط يُدرج الرمز مكان المؤشّر (أو
 * يستبدل المحدد) ويُبقي التركيز في الحقل.
 */
import { computed, nextTick, ref } from 'vue'
import { insertAtCursor } from '@/Composables/insertAtCursor.js'

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    placeholders: { type: Array, default: () => [] },
    multiline: { type: Boolean, default: true },
    rows: { type: Number, default: 4 },
    maxlength: { type: Number, default: 0 },
    error: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    dir: { type: String, default: 'auto' },
    // في الروابط: المتغيّرات المرمّزة {url:...} وحدها.
    urlTokens: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
const field = ref(null)

const chips = computed(() => props.placeholders
    .filter((item) => String(item.value).startsWith('{url:') === props.urlTokens)
    .slice(0, 20))

const insert = async (token) => {
    const { text, caret } = insertAtCursor(props.modelValue, token, field.value)
    emit('update:modelValue', text)

    await nextTick()
    field.value?.focus()
    field.value?.setSelectionRange?.(caret, caret)
}
</script>
