<template>
    <div>
        <div class="mb-1 text-xs text-slate-600">{{ label }}</div>
        <div class="inline-flex rounded-md border border-slate-200 bg-white p-0.5" role="radiogroup" :aria-label="label">
            <button v-for="option in options" :key="option.value" type="button" role="radio"
                :aria-checked="modelValue === option.value"
                class="rounded px-3 py-1 text-xs"
                :class="modelValue === option.value ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-50'"
                @click="emit('update:modelValue', option.value)">
                {{ $t(option.label) }}
            </button>
        </div>
    </div>
</template>

<script setup>
/** بداية/وسط/نهاية — بداية السطر يمين في العربية ويسار في الإنجليزية. */
defineProps({
    modelValue: { type: String, default: 'start' },
    label: { type: String, default: '' },
    options: {
        type: Array,
        default: () => [
            { value: 'start', label: 'Start' },
            { value: 'center', label: 'Center' },
            { value: 'end', label: 'End' },
        ],
    },
})

const emit = defineEmits(['update:modelValue'])
</script>
