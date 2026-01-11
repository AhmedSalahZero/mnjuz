<script setup>
    import { computed } from 'vue';

    const props = defineProps({
        modelValue: [String, Number, Boolean],
        value: { type: [Boolean, Object] },
        name: String,
        className: String,
        label: String,
        disabled: Boolean
    })

    const emit = defineEmits(['update:modelValue']);
    const model = computed({
        get() {
            return props.modelValue;
        },
        set(value) {
            emit("update:modelValue", value);
        },
    });
</script>
<template>
    <div :class="className">
        <div class="relative flex mb-2">
            <div class="flex h-6 items-center mr-2">
                <input 
                    v-model="model"
                    :value="value"
                    :id="name"
                    :name="name"
                    type="checkbox" 
                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                >
            </div>
            <div class="text-sm leading-6">
                <label :for="name" class="text-gray-900 capitalize">{{ label }}</label>
            </div>
        </div>
    </div>
</template>