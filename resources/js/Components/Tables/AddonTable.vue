<script setup>
    import { ref } from 'vue';
    import debounce from 'lodash/debounce';
    import { Link, router, useForm } from '@inertiajs/vue3';
    import AlertModal from '@/Components/AlertModal.vue';
    import { useAlertModal } from '@/Composables/useAlertModal';
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
    import Pagination from '@/Components/Pagination.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
        filters: {
            type: Object
        },
        config: {
            type: Array
        }
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const isOpenModal = ref(false);
    const isOpenInstallModal = ref(false);
    const isLoading = ref(false);
    const currentURL = ref(window.location.origin);
    const isUpdateModal = ref(false);

    const emit = defineEmits(['edit', 'delete']);

    const form = useForm({'test': null});
    const form2 = useForm({
        uuid: null,
        settings: {},
        is_active: null,
    });

    const form3 = useForm({
        uuid: null,
        purchase_code: null,
        addon: null
    });

    function deleteItem(id) {
        emit('delete', id);
    }

    const deleteAction = (key) => {
        form.delete('/admin/faqs/' + key);
    }

    const isLastRow = (index) => {
      return index === props.rows.data.length - 1;
    }

    const params = ref({
        search: props.filters.search,
    });

    const isSearching = ref(false);

    const clearSearch = () => {
        params.value.search = null;
        runSearch();
    }

    const search = debounce(() => {
        isSearching.value = true;
        runSearch();
    }, 1000);

    const runSearch = () => {
        const url = window.location.pathname;

        router.visit(url, {
            method: 'get',
            data: params.value,
        })
    }

    const formUrl = ref(null);
    const modalLogo = ref(null);
    const modalTitle = ref(null);
    const modalDescription = ref(null);
    const installDescription = ref(null);
    const modalInputs = ref([]);

    const setupAddon = (item) => {
        modalLogo.value = item.logo;
        modalTitle.value = item.name;
        modalDescription.value = item.description;
        const inputFields = JSON.parse(item.metadata)?.input_fields || [];
        modalInputs.value = inputFields;

        form2.settings = {};
        let urlSegment = modalTitle.value.toLowerCase().replace(/ /g, '-');
        formUrl.value = '/admin/addons/setup/' + urlSegment;
        form2.is_active = item.is_active;
        form2.uuid = item.uuid;

        // Populate form2.settings with input fields
        inputFields.forEach(input => {
            form2.settings[input.name] = getValueByKey(input.name, input.element);
        });

        isOpenModal.value = true
    }

    const setupInstallModal = (item, type) => {
        const metadata = item.metadata ? JSON.parse(item.metadata) : null;
        modalLogo.value = item.logo;
        modalTitle.value = item.name;
        modalDescription.value = item.description;
        installDescription.value = type == 'extended' ? trans('Enter your extended license to install') : trans('Enter the addon license to install');

        form3.uuid = item.uuid
        form3.addon = metadata?.name || item.name; //Change this to null once everyone updates past v2.7
        isOpenInstallModal.value = true;
    }

    const getValueByKey = (key, type = null) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : type == 'toggle' ? 0 : '';
    };

    const submitForm = () => {
        form2.post(formUrl.value, {
            preserveScroll: true,
            onSuccess: () => {
                isOpenModal.value = false
            }
        });
    }

    const submitForm3 = () => {
        form3.post('/admin/addons/install', {
            preserveScroll: true,
            onSuccess: () => {
                isOpenInstallModal.value = false
            }
        });
    }
</script>
<template>
    <div class="md:bg-white flex items-center border border-primary md:border-none md:shadow-sm h-12 md:h-10 w-full md:w-80 rounded-[0.5rem] mb-6 text-xl md:text-sm">
        <span class="pl-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
        </span>
        <input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full" :placeholder="$t('Search')">
        <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
        </button>
        <span v-if="isSearching" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
        </span>
    </div>

    <div class="grid md:grid-cols-3 gap-x-5 gap-y-4">
        <div v-for="addon in rows.data" :key="addon.id" class="bg-white rounded-[0.5rem] shadow-md text-sm space-y-3">
            <div class="flex items-center gap-x-3 pb-2 bg-slate-50 px-4 pt-4 rounded-tr-[0.5rem] rounded-tl-[0.5rem]">
                <div>
                    <img class="h-8 w-8" :src="'/images/' + addon.logo" alt="Whatsapp Logo">
                </div>
                <div class="text-[16px]">{{ $t(addon.name) }}</div>
            </div>
            <div class="text-slate-500 border-b px-4 text-xs pt-2 pb-4">{{ addon.description }}</div>
            <div class="flex justify-between items-center px-4 pb-4 text-xs">
                <div>
                    <span v-if="addon.status == 0" class=" py-1 px-3 rounded-md text-slate-600 bg-red-600 text-white">{{ $t('Not installed') }}</span>
                    <span v-else-if="addon.status == 1 && addon.update_available == 0" class=" py-1 px-3 rounded-md text-slate-600 bg-slate-50">{{ $t('Installed') }}</span>
                    <Link v-else-if="addon.status == 1 && addon.update_available == 1" href="/admin/updates" class="py-1 px-3 rounded-md text-slate-600 bg-green-600 text-white">{{ $t('Update available') }}</Link>
                </div>
                <div>
                    <button v-if="addon.status == 0" @click="setupInstallModal(addon, addon.license)" class="rounded-full border-2 w-full px-10 py-2 hover:border-secondary hover:bg-secondary hover:text-white text-secondary">{{ $t('Install') }}</button>
                    <button v-else @click="setupAddon(addon)" class="rounded-full border-2 w-full px-10 py-2 hover:border-secondary hover:bg-secondary hover:text-white text-secondary">{{ $t('Setup') }}</button>
                </div>
            </div>
        </div>
    </div>

    <Pagination class="mt-3" :pagination="rows.meta"/>

    <Modal :isOpen=isOpenModal>
        <div class="grid grid-cols-1 gap-x-6 gap-y-4">
            <div class="bg-slate-50 mx-[-30px] px-6 mt-[-25px] py-4 border-b">
                <div class="flex items-center gap-x-3">
                    <div>
                        <img class="h-8 w-8" :src="'/images/' + modalLogo" alt="Whatsapp Logo">
                    </div>
                    <div class="text-[16px]">{{ $t(modalTitle) }}</div>
                </div>
                <div class="text-slate-500 font-light text-xs pt-4">{{ modalDescription }}</div>
            </div>
            
            <form @submit.prevent="submitForm()">
                <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div v-for="input in modalInputs" :class="input.class">
                        <FormInput v-if="input.element == 'input'" v-model="form2.settings[input.name]" :error="form2.errors[`settings.${input.name}`]" :name="$t(input.label)" :type="input.type" :class="input.class" />
                        <div v-if="input.element == 'toggle'">
                            <div class="text-sm mb-2">{{ $t(input.label) }}</div>
                            <FormToggleSwitch v-if="input.element == 'toggle'" v-model="form2.settings[input.name]"/>
                        </div>
                        <FormSelect v-if="input.element == 'select'" v-model="form2.settings[input.name]" :name="$t(input.label)" :type="'text'"  :options="input.options" :error="form2.errors[`settings.${input.name}`]" :class="input.class"/>
                        <FormTextArea v-if="input.element == 'textarea'" v-model="form2.settings[input.name]" :error="form2.errors[`settings.${input.name}`]" :name="$t(input.label)" :class="input.class" />
                        <div v-if="input.description">
                            <span class="text-[11px] text-gray-500">{{ input.description }}</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm mb-2">{{ $t('Enable/disable addon') }}</div>
                        <FormToggleSwitch v-model="form2.is_active"/>
                    </div>
                    <div v-if="modalTitle == 'Embedded Signup'" class="bg-orange-100 p-2 rounded-md shadow-sm mb-1 col-span-2">
                        <div class="flex items-center gap-x-1 border-b border-slate-500 pb-2 mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                            <p class="text-sm">{{ $t('Webhook setup') }}</p>
                        </div>
                        <span>
                            <p class="text-sm leading-6 break-all">{{ $t('Callback URL') }}: {{ currentURL + '/webhook/waba' }}</p>
                            <p class="text-sm leading-6 break-all">{{ $t('Verify token') }}: {{ getValueByKey('whatsapp_callback_token') }}</p>
                        </span>
                    </div>
                </div>
                <div class="mt-5 border-t pt-5 flex">
                    <button type="button" @click.self="isOpenModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button 
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form2.processing }]"
                        :disabled="form2.processing"
                    >
                        <svg v-if="form2.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>

    <Modal :isOpen=isOpenInstallModal>
        <div class="grid grid-cols-1 gap-x-6 gap-y-4">
            <div class="bg-slate-50 mx-[-30px] px-6 mt-[-25px] py-4 border-b">
                <div class="flex items-center gap-x-3">
                    <div>
                        <img class="h-8 w-8" :src="'/images/' + modalLogo" alt="Whatsapp Logo">
                    </div>
                    <div class="text-[16px]">{{ $t(modalTitle) }}</div>
                </div>
                <div class="text-slate-500 font-light text-xs pt-4">{{ modalDescription }}</div>
            </div>
            
            <form @submit.prevent="submitForm3()">
                <div class="grid gap-x-6 sm:grid-cols-2">
                    <h4 class="text-sm">{{ $t('Envato purchase code') }}</h4>
                    <span class="col-span-2 text-xs text-slate-600 mb-2">{{ installDescription }}</span>
                    <FormInput v-model="form3.purchase_code" :error="form3.errors.purchase_code" :name="''" :type="'text'" :class="'col-span-2'" />
                </div>
                <div class="mt-5 border-t pt-5 flex">
                    <button type="button" @click.self="isOpenInstallModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button 
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form3.processing }]"
                        :disabled="form3.processing"
                    >
                        <svg v-if="form3.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>

    <!-- Alert Modal Component-->
    <AlertModal 
        v-model="isOpenAlert" 
        @confirm="() => confirmAlert(deleteAction)"
        :label = "$t('Delete row')" 
        :description = "$t('Are you sure you want to delete this row? This action can not be undone')"
    />
</template>
  