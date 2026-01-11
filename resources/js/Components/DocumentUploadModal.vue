<script setup>
    import { computed, ref, watch } from 'vue';
    import { Link, router, useForm, usePage } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import Modal from '@/Components/Modal.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps(['type', 'modelValue']);
    const emit = defineEmits(['update:modelValue']);
    const flashResponse = computed(() => usePage().props.flash.status);
    
    const modalLabel = trans('Upload Document');
    const isOpenModal = ref(props.modelValue);
    const fileName = ref(null);
    const displayFileName = ref(fileName.value);
    const progressStatus = ref(null);

    const form = useForm({
        title: null,
        file: null,
    });

    watch(() => props.modelValue, (newValue) => {
        isOpenModal.value = newValue;
    });
    
    const handleFileUpload = (event) => {
        event.preventDefault();
        const file = event.target.files[0];
        uploadFile(file);  
    }

    const handleDrop = (event) => {
        event.preventDefault();
        const file = event.dataTransfer.files[0];
        uploadFile(file);  
    };

    function uploadFile(file){
        if (!['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'].includes(file.type)) {
            alert(trans('please select a PDF, Word, or TXT file'));
            return;
        }

        fileName.value = file.name;
        displayFileName.value = getMiddleEllipsis(fileName.value, 28);
        form.file = file;
    }

    const getMiddleEllipsis = (text, maxLength) => {
        if (text.length <= maxLength) return text;
        const middle = Math.floor(maxLength / 2);
        const start = text.slice(0, middle);
        const end = text.slice(-middle);
        return `${start}...${end}`;
    };

    function removeFile() {
        form.file = null;
    }

    function submit(){
        const formData = new FormData();
        formData.append('title', form.title);
        formData.append('file', form.file);

        // Use Inertia router to send the POST request
        form.post('/automation/upload/document', {
            preserveScroll: true,
            onProgress: event => {
                progressStatus.value = 'pending';
            },
            onSuccess: () => {
                form.reset();
                progressStatus.value = 'complete';
                closeModal();
            },
            onError: (errors) => {
                progressStatus.value = null;
            }
        });
    }

    function closeModal(){
        isOpenModal.value = false;
        emit('update:modelValue', false);
        setTimeout(() => {
            progressStatus.value = null;
            form.reset();
            form.clearErrors();
        }, 500);
    }
</script>
<template>
    <!-- Import Modal-->
    <Modal :label="modalLabel" :isOpen="isOpenModal" :closeBtn="true" @close="closeModal()">
        <FormInput v-model="form.title" :error="form.errors.title" :name="'Title'" :type="'text'" :class="'mt-4'"/>
        <div class="block text-sm leading-6 text-gray-900 mt-4 mb-1">{{ $t('Document (PDF, word or TXT file)') }}</div>
        <div class="max-w-md w-full gap-y-8">
            <div class="space-y-6">
                <div v-if="form.file == null" @dragover.prevent @drop="handleDrop" class="flex justify-center px-6 pt-5 pb-6 shadow-sm outline-none ring-1 ring-inset rounded-md" :class="form.errors.file ? 'ring-[#b91c1c]' : 'ring-gray-300'">
                    <input type="file" class="sr-only" accept=".pdf,.txt,.doc,.docx" ref="fileInput" id="file-upload" @change="handleFileUpload($event)"/>
                    <label for="file-upload" class="text-center cursor-pointer">
                        <div>
                            <svg class="mx-auto h-12 w-12 text-gray-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none"><path fill="currentColor" d="m15.393 4.054l-.502.557l.502-.557Zm3.959 3.563l-.502.557l.502-.557Zm2.302 2.537l-.685.305l.685-.305ZM3.172 20.828l.53-.53l-.53.53Zm17.656 0l-.53-.53l.53.53ZM14 21.25h-4v1.5h4v-1.5ZM2.75 14v-4h-1.5v4h2.5Zm18.5-.437V14h2.5v-.437h-1.5ZM14.891 4.61l3.959 3.563l1.003-1.115l-3.958-3.563l-1.004 1.115Zm7.859 8.952c0-1.689.015-2.758-.41-3.714l-1.371.61c.266.598.281 1.283.281 3.104h2.5Zm-3.9-5.389c1.353 1.218 1.853 1.688 2.119 2.285l1.37-.61c-.426-.957-1.23-1.66-2.486-2.79L18.85 8.174ZM10.03 2.75c1.582 0 2.179.012 2.71.216l.538-1.4c-.852-.328-1.78-.316-3.248-.316v1.5Zm5.865.746c-1.086-.977-1.765-1.604-2.617-1.93l-.537 1.4c.532.204.98.592 2.15 1.645l1.004-1.115ZM10 21.25c-1.907 0-3.261-.002-4.29-.14c-1.005-.135-1.585-.389-2.008-.812l-1.06 1.06c.748.75 1.697 1.081 2.869 1.239c1.15.155 2.625.153 4.489.153v-1.5ZM1.25 14c0 1.864-.002 3.338.153 4.489c.158 1.172.49 2.121 1.238 2.87l1.06-1.06c-.422-.424-.676-1.004-.811-2.01c-.138-1.027-.14-2.382-.14-4.289h-1.5ZM14 22.75c1.864 0 3.338.002 4.489-.153c1.172-.158 2.121-.49 2.87-1.238l-1.06-1.06c-.424.422-1.004.676-2.01.811c-1.027.138-2.382.14-4.289.14v1.5ZM21.25 14c0 1.907-.002 3.262-.14 4.29c-.135 1.005-.389 1.585-.812 2.008l1.06 1.06c.75-.748 1.081-1.697 1.239-2.869c.155-1.15.153-2.625.153-4.489h-1.5Zm-18.5-4c0-1.907.002-3.261.14-4.29c.135-1.005.389-1.585.812-2.008l-1.06-1.06c-.75.748-1.081 1.697-1.239 2.869C1.248 6.661 1.25 8.136 1.25 10h2.5Zm7.28-8.75c-1.875 0-3.356-.002-4.511.153c-1.177.158-2.129.49-2.878 1.238l1.06 1.06c.424-.422 1.005-.676 2.017-.811c1.033-.138 2.395-.14 4.312-.14v-1.5Z"/><path stroke="currentColor" stroke-width="1.5" d="M13 2.5V5c0 2.357 0 3.536.732 4.268C14.464 10 15.643 10 18 10h4"/></g></svg>
                            <div class="flex text-sm text-gray-600">
                                <div class="relative bg-white rounded-md">
                                    <span>{{ $t('Click to upload a file') }}</span>
                                </div>
                                <p class="pl-1">{{ $t('Or drag and drop') }}</p>
                            </div>
                            <p class="text-xs text-gray-500">{{ $t('PDF, Word or TXT files only') }}</p>
                            <p class="text-xs text-gray-500">{{ $t('Maximum size') }} <b>20MB</b></p>
                        </div>
                    </label>
                </div>
                <div v-else class="flex justify-center px-6 pt-5 pb-6 shadow-sm outline-none ring-1 ring-inset rounded-md" :class="form.errors.file ? 'ring-[#b91c1c]' : 'ring-gray-300'">
                    <div>
                        <svg class="mx-auto h-12 w-12 text-gray-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none"><path fill="currentColor" d="m15.393 4.054l-.502.557l.502-.557Zm3.959 3.563l-.502.557l.502-.557Zm2.302 2.537l-.685.305l.685-.305ZM3.172 20.828l.53-.53l-.53.53Zm17.656 0l-.53-.53l.53.53ZM14 21.25h-4v1.5h4v-1.5ZM2.75 14v-4h-1.5v4h2.5Zm18.5-.437V14h2.5v-.437h-1.5ZM14.891 4.61l3.959 3.563l1.003-1.115l-3.958-3.563l-1.004 1.115Zm7.859 8.952c0-1.689.015-2.758-.41-3.714l-1.371.61c.266.598.281 1.283.281 3.104h2.5Zm-3.9-5.389c1.353 1.218 1.853 1.688 2.119 2.285l1.37-.61c-.426-.957-1.23-1.66-2.486-2.79L18.85 8.174ZM10.03 2.75c1.582 0 2.179.012 2.71.216l.538-1.4c-.852-.328-1.78-.316-3.248-.316v1.5Zm5.865.746c-1.086-.977-1.765-1.604-2.617-1.93l-.537 1.4c.532.204.98.592 2.15 1.645l1.004-1.115ZM10 21.25c-1.907 0-3.261-.002-4.29-.14c-1.005-.135-1.585-.389-2.008-.812l-1.06 1.06c.748.75 1.697 1.081 2.869 1.239c1.15.155 2.625.153 4.489.153v-1.5ZM1.25 14c0 1.864-.002 3.338.153 4.489c.158 1.172.49 2.121 1.238 2.87l1.06-1.06c-.422-.424-.676-1.004-.811-2.01c-.138-1.027-.14-2.382-.14-4.289h-1.5ZM14 22.75c1.864 0 3.338.002 4.489-.153c1.172-.158 2.121-.49 2.87-1.238l-1.06-1.06c-.424.422-1.004.676-2.01.811c-1.027.138-2.382.14-4.289.14v1.5ZM21.25 14c0 1.907-.002 3.262-.14 4.29c-.135 1.005-.389 1.585-.812 2.008l1.06 1.06c.75-.748 1.081-1.697 1.239-2.869c.155-1.15.153-2.625.153-4.489h-1.5Zm-18.5-4c0-1.907.002-3.261.14-4.29c.135-1.005.389-1.585.812-2.008l-1.06-1.06c-.75.748-1.081 1.697-1.239 2.869C1.248 6.661 1.25 8.136 1.25 10h2.5Zm7.28-8.75c-1.875 0-3.356-.002-4.511.153c-1.177.158-2.129.49-2.878 1.238l1.06 1.06c.424-.422 1.005-.676 2.017-.811c1.033-.138 2.395-.14 4.312-.14v-1.5Z"/><path stroke="currentColor" stroke-width="1.5" d="M13 2.5V5c0 2.357 0 3.536.732 4.268C14.464 10 15.643 10 18 10h4"/></g></svg>
                        <div class="rounded-md p-1 bg-slate-50 text-center text-sm flex items-center gap-x-1">
                            <span>{{ displayFileName }}</span>
                            <span @click="removeFile()" class="bg-slate-200 rounded-full cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20"><g fill="black"><path d="M6.854 13.854a.5.5 0 0 1-.708-.708l7-7a.5.5 0 0 1 .708.708z"/><path d="M6.146 6.854a.5.5 0 1 1 .708-.708l7 7a.5.5 0 0 1-.708.708z"/></g></svg>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <span v-if="form.errors.file" class="form-error text-[#b91c1c] text-xs">{{ form.errors.file }}</span>
        </div>

        <div class="mt-5">
            <div class="flex justify-center mt-2 w-full">
                <div v-if="form.processing" class="w-full inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <svg class="mx-auto text-white" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="white" stroke-dasharray="15" stroke-dashoffset="15" stroke-linecap="round" stroke-width="2" d="M12 3C16.9706 3 21 7.02944 21 12"><animate fill="freeze" attributeName="stroke-dashoffset" dur="0.3s" values="15;0"/><animateTransform attributeName="transform" dur="1.5s" repeatCount="indefinite" type="rotate" values="0 12 12;360 12 12"/></path></svg>
                </div>
                <button v-else type="button" @click="submit()" class="w-full inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ $t('Upload') }}</button>
            </div>
        </div>
    </Modal>
</template>