<script setup>
    import { ref } from 'vue';

    const props = defineProps({
        modelValue: File,
        name: String,
        label: String,
        className: String,
        error: String,
        disabled: Boolean,
        imageUrl: String
    })


    const imageViewUrl = ref(props.imageUrl);
    const emit = defineEmits(['update:modelValue']);

    const updateValue = (event) => {
        const fileSizeLimit = 5 * 1024 * 1024;
        const file = event.target.files[0];

        if (file && file.size > fileSizeLimit) {
            alert(`File size exceeds the limit. Max allowed size: ${fileSizeLimit} bytes`);
            event.target.value = null;
        } else {
            const reader = new FileReader();

            reader.onload = (e) => {
                imageViewUrl.value = e.target.result;
            };

            emit('update:modelValue', file);
            reader.readAsDataURL(file);
        }
    }
</script>
<template>
    <div :class="className">
        <div class="flex items-center gap-x-3">
            <img v-if="imageViewUrl" :src="imageViewUrl" alt="" class="w-20 h-20 rounded-full dark:bg-gray-500">
            <svg v-else class="h-20 w-20 text-gray-300" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M18.685 19.097A9.723 9.723 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75S2.25 6.615 2.25 12a9.723 9.723 0 003.065 7.097A9.716 9.716 0 0012 21.75a9.716 9.716 0 006.685-2.653zm-12.54-1.285A7.486 7.486 0 0112 15a7.486 7.486 0 015.855 2.812A8.224 8.224 0 0112 20.25a8.224 8.224 0 01-5.855-2.438zM15.75 9a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" clip-rule="evenodd" />
            </svg>
            <input type="file" class="sr-only" :accept="'.png, .jpg'" id="file-upload" @change="updateValue" :disabled="disabled"/>
            <label for="file-upload" class="cursor-pointer rounded-md bg-white px-2.5 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">{{ props.label }}</label>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>
