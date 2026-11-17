<template>
    <div>
        <div class="mb-1 flex items-center justify-between text-xs text-slate-600">
            <label :for="inputId">{{ label }}</label>
            <span dir="ltr">{{ modelValue }}{{ unit }}</span>
        </div>
        <input :id="inputId" type="range" :min="min" :max="max" :step="step" :value="modelValue"
            class="w-full accent-primary"
            @input="emit('update:modelValue', Number($event.target.value))" />
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>

<script setup>
defineProps({
    modelValue: { type: Number, default: 0 },
    label: { type: String, default: '' },
    min: { type: Number, default: 0 },
    max: { type: Number, default: 100 },
    step: { type: Number, default: 1 },
    unit: { type: String, default: 'px' },
    error: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const inputId = `range-${Math.random().toString(36).slice(2, 9)}`
</script>
