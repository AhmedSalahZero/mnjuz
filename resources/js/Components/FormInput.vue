<script setup>
    import { ref } from 'vue';

    const props = defineProps({
        modelValue: [String, Number],
        label: String,
        name: String,
        placeholder: String,
        type: String,
        min: Number,
        className: String,
        labelClass: String,
        required: Boolean,
        error: String,
        disabled: Boolean
    })

    const emit = defineEmits(['update:modelValue']);
    const updateValue = (event) => {
        emit('update:modelValue', event.target.value);
    };
</script>
<template>
    <div :class="className">
        <label for="name" class="block text-sm leading-6 text-gray-900" :class="labelClass">{{ label ?? name }}</label>
        <div>
            <input
            class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
            :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
            :type="type"
            :value="props.modelValue"
            @input="updateValue"
            :step="'any'"
            :min="min"
            :placeholder="placeholder"
            :disabled="disabled"
            :required="required"
            />
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>