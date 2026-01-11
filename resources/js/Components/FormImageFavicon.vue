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
        <label for="name" class="block text-sm leading-6 text-gray-900 mb-2">{{ name }}</label>
        <div class="flex items-center gap-x-3">
            <div v-if="imageViewUrl" class="h-16 w-16 rounded-lg border-2 border-dashed overflow-hidden">
                <img :src="imageViewUrl" alt="" class="object-contain w-full h-full">
            </div>
            <div v-else class="border-2 border-dashed h-16 w-16 flex rounded-lg items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M2 6a4 4 0 0 1 4-4h12a4 4 0 0 1 4 4v12a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V6Z"/><circle cx="8.5" cy="8.5" r="2.5"/><path d="M14.526 12.621L6 22h12.133A3.867 3.867 0 0 0 22 18.133V18c0-.466-.175-.645-.49-.99l-4.03-4.395a2 2 0 0 0-2.954.006Z"/></g></svg>
            </div>
            <input type="file" class="" :accept="'.png, .jpg'" id="file-upload" @change="updateValue" :disabled="disabled"/>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>
