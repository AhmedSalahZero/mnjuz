<template>
    <AppLayout>
        <div class="flex justify-between">
            <div>
                <h2 class="text-xl mb-1">{{ $t('Language translations:') }} {{ props.language.name }}</h2>
                <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span class="ml-1 mt-1">{{ $t('Translate your language') }}</span>
                </p>
            </div>
            <div class="flex items-center space-x-2">
                <button @click="isOpenModal = true" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Bulk edit translations') }}</button>
                <Link href="/admin/languages" class="rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-black focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
            </div>
        </div>
        
        <!-- Table Component-->
        <LangTranslationsTable :rows="props.rows" :filters="props.filters" :language="props.language" />

        <!-- Import Modal-->
        <Modal :label="$t('Bulk Edit')" :isOpen=isOpenModal>
            <ol class="list-decimal ml-4 mt-2 space-y-0 text-sm">
                <li class="mb-0">
                    <span class="text-sm text-slate-600">{{ $t('Download .xlsx file with all translation strings.') }}</span>
                    <br>
                    <span class="text-sm text-slate-600 underline">
                        <a :href="'/admin/languages/'+ props.language.code +'/export'">{{ $t('Click here to download') }}</a>
                    </span>
                </li>
                <li>
                    <div class="text-sm text-slate-600 mt-4 mb-0">{{ $t('Edit the xlsx file with your own translations') }}</div>
                </li>
                <li>
                    <div class="text-sm text-slate-600 mt-4 mb-0">{{ $t('Reupload the edited xlsx file below') }}</div>
                </li>
            </ol>
            
            <div class="max-w-md w-full space-y-8">
                <div class="mt-8 space-y-6">
                    <div @dragover.prevent @drop="handleDrop" class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                        <input
                            type="file"
                            class="sr-only"
                            accept=".xslx"
                            ref="fileInput"
                            id="file-upload"
                            @change="handleFileUpload($event)"
                        />
                        <div class="text-center">
                            <div>
                                <svg class="mx-auto h-12 w-12 text-gray-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none"><path fill="currentColor" d="m15.393 4.054l-.502.557l.502-.557Zm3.959 3.563l-.502.557l.502-.557Zm2.302 2.537l-.685.305l.685-.305ZM3.172 20.828l.53-.53l-.53.53Zm17.656 0l-.53-.53l.53.53ZM14 21.25h-4v1.5h4v-1.5ZM2.75 14v-4h-1.5v4h2.5Zm18.5-.437V14h2.5v-.437h-1.5ZM14.891 4.61l3.959 3.563l1.003-1.115l-3.958-3.563l-1.004 1.115Zm7.859 8.952c0-1.689.015-2.758-.41-3.714l-1.371.61c.266.598.281 1.283.281 3.104h2.5Zm-3.9-5.389c1.353 1.218 1.853 1.688 2.119 2.285l1.37-.61c-.426-.957-1.23-1.66-2.486-2.79L18.85 8.174ZM10.03 2.75c1.582 0 2.179.012 2.71.216l.538-1.4c-.852-.328-1.78-.316-3.248-.316v1.5Zm5.865.746c-1.086-.977-1.765-1.604-2.617-1.93l-.537 1.4c.532.204.98.592 2.15 1.645l1.004-1.115ZM10 21.25c-1.907 0-3.261-.002-4.29-.14c-1.005-.135-1.585-.389-2.008-.812l-1.06 1.06c.748.75 1.697 1.081 2.869 1.239c1.15.155 2.625.153 4.489.153v-1.5ZM1.25 14c0 1.864-.002 3.338.153 4.489c.158 1.172.49 2.121 1.238 2.87l1.06-1.06c-.422-.424-.676-1.004-.811-2.01c-.138-1.027-.14-2.382-.14-4.289h-1.5ZM14 22.75c1.864 0 3.338.002 4.489-.153c1.172-.158 2.121-.49 2.87-1.238l-1.06-1.06c-.424.422-1.004.676-2.01.811c-1.027.138-2.382.14-4.289.14v1.5ZM21.25 14c0 1.907-.002 3.262-.14 4.29c-.135 1.005-.389 1.585-.812 2.008l1.06 1.06c.75-.748 1.081-1.697 1.239-2.869c.155-1.15.153-2.625.153-4.489h-1.5Zm-18.5-4c0-1.907.002-3.261.14-4.29c.135-1.005.389-1.585.812-2.008l-1.06-1.06c-.75.748-1.081 1.697-1.239 2.869C1.248 6.661 1.25 8.136 1.25 10h2.5Zm7.28-8.75c-1.875 0-3.356-.002-4.511.153c-1.177.158-2.129.49-2.878 1.238l1.06 1.06c.424-.422 1.005-.676 2.017-.811c1.033-.138 2.395-.14 4.312-.14v-1.5Z"/><path stroke="currentColor" stroke-width="1.5" d="M13 2.5V5c0 2.357 0 3.536.732 4.268C14.464 10 15.643 10 18 10h4"/></g></svg>
                            <div class="flex text-sm text-gray-600">
                                <label
                                for="file-upload"
                                class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500"
                                >
                                <span>{{ $t('Click to upload a file') }}</span>
                                </label>
                                <p class="pl-1">{{ $t('Or drag and drop') }}</p>
                            </div>
                            <p class="text-xs text-gray-500">{{ $t('XLSX files only') }}</p>
                            </div>
                        </div>
                    </div>
                    <div v-if="uploads.length" class="mt-4">
                    <ul class="mt-2 rounded-md bg-[#F5F7FB] px-6">
                        <li v-for="upload in uploads" :key="upload.name" class="py-2 flex justify-between items-center">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-indigo-600 truncate">{{ upload.name }}</p>
                                <div class="mt-1 flex items-center text-sm text-gray-500">
                                    <span v-if="upload.progress !== 100">{{ upload.progress }}% - </span>
                                    <span v-else>{{ $t('Complete') }}</span>
                                </div>
                            </div>
                            <div class="ml-4 flex-shrink-0">
                                <div v-if="upload.progress !== 100" class="relative w-48">
                                    <div class="overflow-hidden h-2 text-xs flex rounded bg-indigo-200">
                                        <div :style="{ width: `${upload.progress}%` }" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500"></div>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <div class="mt-2 w-full">
                    <button type="button" @click="isOpenModal = false" class="inline-flex float-right justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ $t('Close') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "../Layout/App.vue";
    import { ref } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import LangTranslationsTable from '@/Components/Tables/LangTranslationsTable.vue';
    import Modal from '@/Components/Modal.vue';

    const props = defineProps({ language: Object, rows: Object, filters: String });
    const uploads = ref([]);
    const isOpenModal = ref(false);

    const form = useForm({
        _method: 'put',
    })

    const submitForm = async () => {
        const url = window.location.pathname;

        form.put(url, {
            preserveScroll: true,
        });
    };

    const handleFileUpload = (event) => {
        const files = event.target.files;
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            if (!['text/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].includes(file.type)) {
                alert(trans('please select a CSV or XLSX file'));
                return;
            }
            const formData = new FormData();
            formData.append('file', file);

            const xhr = new XMLHttpRequest();
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    const progress = Math.round((event.loaded / event.total) * 100);
                    const fileIndex = uploads.value.findIndex((item) => item.name === file.name);
                    uploads.value[fileIndex].progress = progress;
                }
            });

            xhr.open('POST', '/admin/languages/'+ props.language.code +'/import');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);

            xhr.onload = () => {
                if (xhr.status === 200) {
                    // Handle success
                    //console.log('File uploaded successfully!');
                } else {
                    // Handle error
                    //console.error('File upload failed.');
                }
            };

            xhr.send(formData);

            uploads.value.push({
                name: file.name,
                progress: 0,
            });
        }
    };

    const handleDrop = (event) => {
        event.preventDefault();
        const files = event.dataTransfer.files;
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            if (!['text/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].includes(file.type)) {
                alert(trans('please select a CSV or XLSX file'));
                return;
            }
            const formData = new FormData();
            formData.append('file', file);

            const xhr = new XMLHttpRequest();
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    const progress = Math.round((event.loaded / event.total) * 100);
                    const fileIndex = uploads.value.findIndex((item) => item.name === file.name);
                    uploads.value[fileIndex].progress = progress;
                }
            });

            xhr.open('POST', '/admin/languages/'+ props.language.code +'/import');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);

            xhr.onload = () => {
                if (xhr.status === 200) {
                    // Handle success
                    //console.log('File uploaded successfully!');
                } else {
                    // Handle error
                    //console.error('File upload failed.');
                }
            };

            xhr.send(formData);

            uploads.value.push({
                name: file.name,
                progress: 0,
            });
        }
    };
</script>