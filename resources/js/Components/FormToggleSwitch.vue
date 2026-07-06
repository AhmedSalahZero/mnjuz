<script setup>
    import { ref } from 'vue';

    const props = defineProps({
        modelValue: Boolean,
        className: String,
        disabled:{
            type: Boolean,
            default: false
        },
    })

    const emit = defineEmits(['update:modelValue']);
    const updateValue = (event) => {
        if(props.disabled == false){
            emit('update:modelValue', !props.modelValue);
        }
    };
</script>
<template>
    <div :class="className">
        <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1" :class="{ 'bg-primary': props.modelValue == true || props.modelValue == 1}" @click="updateValue">
            <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out" :class="{ 'translate-x-6': props.modelValue == true || props.modelValue == 1,}"></div>
        </div>
    </div>
</template>