<script setup>
    import { router } from '@inertiajs/vue3';
    import { ref, watch, reactive } from 'vue';
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormImage from '@/Components/FormImage.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';

    const props = defineProps({
        modelValue: Boolean,
        label: String,
        url: String,
        form: Object,
        formInputs: Array,
        formMethod: String,
        errors: Object
    })

    const form = reactive(props.form);
    const isLoading = ref(false);

    const submitForm = async () => {
        router.visit(props.url, {
            method: props.formMethod,
            data: form,
            preserveState: true,
            onStart: visit => {
                isLoading.value = true;
            },
            onFinish: visit => {
                isLoading.value = false;
            },
            onSuccess: visit => {
                emit('closeModal', true);
            },
        })
    };

    const emit = defineEmits(['update:modelValue', 'closeModal']);

    function onClose() {
        emit('update:modelValue', false);
    }
</script>
<template>
    <Modal :label="label" :isOpen=modelValue>
        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <form @submit.prevent="submitForm()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">
                <template v-for="(input, index) in formInputs" :key="index">
                    <FormInput v-if="input.inputType == 'FormInput'" v-model="form[input.name]" :name="input.label" :error="$page.props?.errors?.[input.name]" :type="input.type" :class="input.className"/>
                    <FormSelect v-else-if="input.inputType == 'FormSelect'" v-model="form[input.name]" :options="input.options" :name="input.label" :error="$page.props?.errors?.[input.name]" :class="input.className" :placeholder="input.placeholder"/>
                    <FormImage v-else-if="input.inputType == 'FormImage'" v-model="form[input.name]" :error="$page.props?.errors?.[input.name]" :options="input.options" :label="input.label" :class="input.className"/>
                    <FormTextArea v-else-if="input.inputType == 'FormTextArea'" v-model="form[input.name]" :error="$page.props?.errors?.[input.name]" :options="input.options" :name="input.label" :label="input.label" :class="input.className" :textAreaRows="input.textAreaRows"/>
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