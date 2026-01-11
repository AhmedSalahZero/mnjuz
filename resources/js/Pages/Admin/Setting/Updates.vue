<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-6">{{ $t('Swiftchats Updates') }}</h2>

            <div class="bg-white border px-2 py-2 rounded-md mb-8">
                <div class="text-sm mb-2">Current Version: v{{ version }}</div>
                <div class="text-sm">Released Date: {{ releaseDate }}</div>
            </div>

            <div class="text-sm flex items-center gap-x-4 mb-2">
                <span>Last checked on {{ getValueByKey('last_update_check') }}</span>
                <button @click="submitForm3()" :disabled="form3.processing" class="border px-2 py-1 text-sm rounded-md bg-primary text-white">
                    <svg v-if="form3.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                    <span v-else>Check Again</span>
                </button>
            </div>

            <div v-if="getValueByKey('is_update_available') == 0" class="mb-8">
                <p class="flex items-center text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span class="ml-1 mt-1">{{ $t('You have installed the latest version of Swiftchats') }}</span>
                </p>
            </div>

            <div v-else>
                <div class="flex items-center gap-x-1 text-sm bg-orange-100 py-3 px-2 rounded-md mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span><b>Important:</b> Before updating backup your database and files</span>
                </div>

                <div class="bg-white text-sm flex items-center justify-between border rounded-md px-2 py-2 mb-6">
                    <div class="flex items-center gap-x-2">
                        <img class="h-5 w-full" :src="'/images/logo.png'" alt="Swiftchats Logo">
                        <span>v{{ getValueByKey('available_version') }}</span>
                    </div>
                    <button @click="openModal()" class="border rounded-md px-4 py-1">{{ $t('Update to version') + ' ' + getValueByKey('available_version') }}</button>
                </div>
            </div>

            <h2 class="text-xl mb-2">Addons</h2>
            <p v-if="rows.data.length == 0" class="flex items-center text-sm mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Your addons are all upto date.') }}</span>
            </p>

            <div v-else class="grid grid-cols-2 gap-x-4 gap-y-1">
                <div v-for="addon in rows.data" class="bg-white text-sm flex items-center justify-between border rounded-md px-2 py-2">
                    <div class="flex items-center gap-x-2">
                        <img class="h-8 w-8" :src="'/images/'+ addon.logo" :alt="addon.name">
                        <span>{{ addon.name }}</span>
                    </div>
                    <button v-if="getValueByKey('is_update_available') == 0" @click="updateAddon(addon)" class="border rounded-md px-4 py-1">Update</button>
                    <div v-else class="border rounded-md px-4 py-1 bg-red-700 text-white flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1">{{ $t('Update script first') }}</span>
                    </div>
                </div>
            </div>

            <Pagination class="mt-3" :pagination="rows.meta"/>
        </div>

        <Modal :isOpen=isUpdateModal>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4">
                <div class="bg-slate-50 mx-[-30px] px-6 mt-[-25px] py-4 border-b">
                    <div class="flex items-center gap-x-3">
                        <div>
                            <img class="h-8 w-8" :src="'/images/' + modalLogo" alt="Whatsapp Logo">
                        </div>
                        <div class="text-[16px]">{{ $t(modalTitle) }}</div>
                    </div>
                </div>
                
                <form @submit.prevent="submitForm()">
                    <div>
                        <div class="text-[17px] text-gray-800 mb-2">{{ $t('Update module') }}</div>
                        <h4 class="text-sm">{{ $t('A new version is available. Would you like to proceed with updating the module?') }}</h4>
                    </div>
                    <div class="mt-3 border-t pt-5 flex">
                        <button type="button" @click.self="isUpdateModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('No, not now') }}</button>
                        <button 
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]"
                            :disabled="form.processing"
                        >
                            <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Yes, run the update') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :isOpen=isOpenUpdateModal>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4">
                <div class="bg-slate-50 mx-[-30px] px-6 mt-[-25px] py-4 border-b">
                    <div class="flex items-center gap-x-3">
                        <div class="text-[16px]">{{ $t('Update to the latest version') }}</div>
                    </div>
                </div>
                
                <form @submit.prevent="submitForm2()">
                    <div>
                        <h4 class="text-sm">{{ $t('Would you like to proceed with updating Swiftchats?') }}</h4>
                    </div>
                    <div class="mt-3 border-t pt-5 flex">
                        <button type="button" @click.self="isOpenUpdateModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('No, not now') }}</button>
                        <button 
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form2.processing }]"
                            :disabled="form2.processing"
                        >
                            <svg v-if="form2.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Yes, run the update') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./Layout/App.vue";
    import { computed, ref } from 'vue';
    import { useForm, usePage, Link } from "@inertiajs/vue3";
    import Modal from '@/Components/Modal.vue';
    import Pagination from '@/Components/Pagination.vue';

    const props = defineProps({
        config: {
            type: Object,
            required: true
        },
        rows: {
            type: Array,
            required: true
        }
    });

    const version = computed(() => usePage().props.applicationVersion);
    const releaseDate = computed(() => usePage().props.applicationReleaseDate);
    const modalLogo = ref(null);
    const modalTitle = ref(null);
    const modalDescription = ref(null);
    const isCheckLoading = ref(false);
    const isOpenUpdateModal = ref(false);
    const isUpdateModal = ref(false);

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const form = useForm({
        uuid: null,
        purchase_code: null,
        addon: null,
        name: null,
    });

    const form2 = useForm({
        update: true
    });

    const form3 = useForm({
        checkUpdate: true
    });

    const openModal = () => {
        isOpenUpdateModal.value = true
    }

    const updateAddon = (item) => {
        modalLogo.value = item.logo;
        modalTitle.value = item.name;
        modalDescription.value = item.description;
        const metadata = item.metadata ? JSON.parse(item.metadata) : null;
        form.uuid = item.uuid
        form.name = item.name;
        form.addon = metadata?.name || item.name;
        isUpdateModal.value = true
    }

    const submitForm = () => {
        form.post('/admin/addons/update', {
            preserveScroll: true,
            onSuccess: () => {
                isUpdateModal.value = false
            }
        });
    }

    const submitForm2 = () => {
        form2.post('/admin/update', {
            preserveScroll: true,
            onSuccess: () => {
                isOpenUpdateModal.value = false
            }
        });
    }

    const submitForm3 = () => {
        form3.get('/admin/updates/check', {
            preserveScroll: true,
            onSuccess: () => {
                isCheckLoading.value = false
            }
        });
    }
</script>