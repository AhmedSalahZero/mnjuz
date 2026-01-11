<script setup>
    import { ref } from 'vue';

    const props = defineProps({
        modelValue: [String, Number],
        showLabel: {
            type: Boolean,
            default: true
        },
        name: String,
        type: String,
        className: String,
        placeholder: String,
        textAreaRows: Number,
        error: String,
    })

    const emit = defineEmits(['update:modelValue']);
    const updateValue = (event) => {
        emit('update:modelValue', event.target.value);
    };
</script>
<template>
    <div :class="className">
        <label v-if="showLabel" for="name" class="block text-sm leading-6 text-gray-900">{{ name }}</label>
        <div class="mt-2">
            <textarea 
                class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
                :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
                :value="props.modelValue"
                @input="updateValue"
                :placeholder="placeholder"
                :rows="textAreaRows"
                >
                {{ props.modelValue }}
            </textarea>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>