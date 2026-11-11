<script setup>
/**
 * القنوات التي يعمل عليها ردٌّ آلي أو flow: واتساب، تيليجرام، المحادثة المباشرة.
 * الخيارات من الخادم (AutomationChannels::options) بأسماء مترجمة.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    error: { type: String, default: null },
    name: { type: String, default: 'channels' },
})

const emit = defineEmits(['update:modelValue'])

const toggle = (value, checked) => {
    const next = checked
        ? [...props.modelValue.filter((v) => v !== value), value]
        : props.modelValue.filter((v) => v !== value)

    // بترتيب الخيارات لا بترتيب الضغط.
    emit('update:modelValue', props.options.map((o) => o.value).filter((v) => next.includes(v)))
}
</script>

<template>
    <div>
        <div class="flex flex-wrap gap-2" role="group">
            <label v-for="option in options" :key="option.value"
                class="inline-flex cursor-pointer select-none items-center gap-x-2 rounded-md border px-3 py-1.5 text-sm transition"
                :class="modelValue.includes(option.value) ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'">
                <input type="checkbox" :name="name + '[]'" :value="option.value"
                    :checked="modelValue.includes(option.value)"
                    @change="toggle(option.value, $event.target.checked)"
                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
                <span>{{ option.label }}</span>
            </label>
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
