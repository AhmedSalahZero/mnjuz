<template>
    <div>
        <label class="block text-xs text-slate-600 mb-1">{{ label }}</label>
        <div class="flex items-center gap-2">
            <input type="color" :value="safeColor" :aria-label="label"
                class="h-9 w-12 cursor-pointer rounded border border-slate-200 bg-white p-0.5"
                @input="emit('update:modelValue', $event.target.value)" />
            <input type="text" :value="modelValue" maxlength="7" dir="ltr" :aria-label="label"
                class="w-28 rounded-md border border-slate-200 px-2 py-1.5 text-xs uppercase focus:outline-none focus:ring-1 focus:ring-slate-400"
                @change="onHex($event.target.value)" />
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>

<script setup>
/** لون بمنتقي المتصفّح وحقل hex؛ القيمة #rrggbb وحدها (كما يقبلها الخادم). */
import { computed } from 'vue'

const props = defineProps({
    modelValue: { type: String, default: '#000000' },
    label: { type: String, default: '' },
    error: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const HEX = /^#[0-9a-fA-F]{6}$/

const safeColor = computed(() => (HEX.test(props.modelValue) ? props.modelValue : '#000000'))

const onHex = (value) => {
    const color = String(value).trim().startsWith('#') ? String(value).trim() : `#${String(value).trim()}`

    if (HEX.test(color)) {
        emit('update:modelValue', color.toLowerCase())
    }
}
</script>
