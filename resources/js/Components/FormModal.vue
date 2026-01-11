<script setup>
    import axios from 'axios';
    import { router } from '@inertiajs/vue3';
    import { ref } from 'vue';
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormImage from '@/Components/FormImage.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import { toast } from 'vue3-toastify';
    import 'vue3-toastify/dist/index.css';

    const props = defineProps({
        modelValue: Boolean,
        label: String,
        url: String,
        form: Object,
        formInputs: Array,
    })

    const form = ref(props.form);
    const isLoading = ref(false);
    const errorMessages = ref({});

    const submitForm = async (event) => {
        isLoading.value = true;
        errorMessages.value = {};

        try {
            const formData = new FormData();

            Object.keys(form.value).forEach((key) => {
                const value = form.value[key];

                if (Array.isArray(value)) {
                    // Handle arrays (e.g., file inputs)
                    value.forEach((item) => {
                        formData.append(key + '[]', item);
                    });
                } else {
                    // Handle other form fields
                    formData.append(key, value);
                }
            });

            const response = await axios.post(props.url,  formData, {
                headers: {
                    'Content-Type': 'multipart/form-data', // Set content type to handle file uploads
                },
            });

            if (response.status === 200 && response.data.success) {
                handleSuccessResponse(response);
            } else {
                handleFailedResponse(response);
            }
        } catch (error) {
            handleError(error);
        } finally {
            setTimeout(() => {
                isLoading.value = false;
            }, 1000);
        }
    };

    const handleSuccessResponse = (response) => {
        setTimeout(() => {
            onClose();
            router.reload();
            toast(response.data.message, {
                autoClose: 3000,
            });
            emit('callback', response.data);
        }, 1000);
    };

    const handleFailedResponse = (response) => {
        setTimeout(() => {
            if (response.data.errors) {
                errorMessages.value = response.data.errors;
            }
        }, 1000);
    };

    const handleError = (error) => {
        setTimeout(() => {
            errorMessages.value = error.response.data.errors;
        }, 1000);
    };

    const emit = defineEmits(['update:modelValue', 'callback']);
    function onClose() {
        emit('update:modelValue', false);
    }
</script>
<template>
    <Modal :label="label" :isOpen=modelValue>
        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <form @submit.prevent="submitForm()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">

                <template v-for="(input, index) in formInputs" :key="index">
                    <FormInput v-if="input.inputType == 'FormInput'" v-model="form[input.name]" :error="errorMessages?.[input.name]?.[0]" :name="input.label" :type="input.type" :class="input.className"/>
                    <FormSelect v-else-if="input.inputType == 'FormSelect'" v-model="form[input.name]" :error="errorMessages?.[input.name]?.[0]" :options="input.options" :name="input.label" :class="input.className" :placeholder="input.placeholder"/>
                    <FormImage v-else-if="input.inputType == 'FormImage'" v-model="form[input.name]" :error="errorMessages?.[input.name]?.[0]" :options="input.options" :label="input.label" :class="input.className"/>
                </template>

                <div class="mt-4 flex">
                    <button type="button" @click.self="onClose" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button 
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                        :disabled="isLoading"
                    >
                        <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>