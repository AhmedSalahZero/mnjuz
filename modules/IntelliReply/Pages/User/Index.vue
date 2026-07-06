<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Enable AI Assistant') }}</h4>
                                    <div class="mb-1 text-slate-500">{{ $t('Activate AI-generated responses in your conversations') }}</div>
                                </div>
                                <div class="ml-auto">
                                    <div class="flex items-center gap-x-3">
                                        <div v-if="settings?.ai?.api_key != null || getValueByKey('enable_api_key_input') != 1" class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1" :class="{ 'bg-primary': form.active}" @click="toggleState(active)">
                                            <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out" :class="{ 'translate-x-6': form.active}"></div>
                                        </div>
                                        <div v-else class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1" :class="{ 'bg-primary': form2.active}" @click="form2.active = true; isOpenFormModal = true">
                                            <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out" :class="{ 'translate-x-6': form2.active}"></div>
                                        </div>

                                        <div v-if="settings?.ai?.api_key != null || getValueByKey('enable_api_key_input') != 1">
                                            |
                                        </div>
                                        <button v-if="settings?.ai?.api_key != null || getValueByKey('enable_api_key_input') != 1" @click="isOpenFormModal = true" class="bg-primary text-white h-8 rounded-lg text-[13px] px-3 w-fit">
                                            {{ $t('Update') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form @submit.prevent="submitForm3()" v-if="settings?.ai?.api_key != null || getValueByKey('enable_api_key_input') != 1" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-4">
                        <div class="flex items-center justify-between px-4 pt-2 pb-4">
                            <div @click="toggleSetupForm()" class="w-[90%] cursor-pointer">
                                <h4 class="text-[16px]">{{ $t('AI Assistant Setup') }}</h4>
                                <div class="text-slate-500">{{ $t('Setup keywords for AI assistance') }}</div>
                            </div>
                            <div class="w-[10%]">
                                <button type="button" @click="toggleSetupForm()" class="hover:bg-slate-50 rounded-full p-1 float-right">
                                    <svg v-if="setupForm" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="black" fill-rule="evenodd" d="M16.53 14.03a.75.75 0 0 1-1.06 0L12 10.56l-3.47 3.47a.75.75 0 0 1-1.06-1.06l4-4a.75.75 0 0 1 1.06 0l4 4a.75.75 0 0 1 0 1.06" clip-rule="evenodd"/></svg>
                                    <svg v-if="!setupForm" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="black" fill-rule="evenodd" d="M16.53 8.97a.75.75 0 0 1 0 1.06l-4 4a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 1 1 1.06-1.06L12 12.44l3.47-3.47a.75.75 0 0 1 1.06 0" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                        </div>
                        <div v-if="setupForm">
                            <div class="flex space-x-10 border-b w-full px-4 py-6">
                                <div class="w-[70%]">
                                    <span class="text-slate-600">{{ $t('Enable automatic AI assistance for new conversations') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('Turn on this option to let users automatically get help from the AI whenever they start a new conversation or ticket. If enabled, this will override the keywords set for initiating AI chat.') }}</span>
                                    </div>
                                </div>
                                <div class="w-[30%]">
                                    <div class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1" :class="{ 'bg-primary': form3.enable_automatic_responses}" @click="toggleState2()">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out" :class="{ 'translate-x-6': form3.enable_automatic_responses}"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex space-x-10 border-b w-full px-4 py-6">
                                <div class="w-[40%]">
                                    <span class="text-slate-600">{{ $t('Keyword(s) to start the AI agent') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('Set word or phrase') }}</span>
                                    </div>
                                </div>
                                <div class="w-[60%]">
                                    <FormInput v-model="form3.start_keywords" :error="form3.errors.start_keywords" :name="''" :type="'text'" :class="'col-span-4'"/>
                                </div>
                            </div>
                            <div class="flex space-x-10 border-b w-full px-4 py-6">
                                <div class="w-[40%]">
                                    <span class="text-slate-600">{{ $t('Keyword(s) to stop the AI agent') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('Set word or phrase') }}</span>
                                    </div>
                                </div>
                                <div class="w-[60%]">
                                    <FormTextArea v-model="form3.stop_keywords" :error="form3.errors.stop_keywords" :name="''" :type="'text'" :class="'col-span-4'"/>
                                </div>
                            </div>
                            <div class="flex px-4 pt-1">
                                <div class="ml-auto mt-2">
                                    <button type="submit" class="float-right bg-primary text-white h-8 rounded-lg text-[13px] px-3 w-fit" :disabled="form3.processing">
                                        <svg v-if="form3.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                        <span v-else>{{ $t('Save') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div v-if="settings?.ai?.api_key != null || getValueByKey('enable_api_key_input') != 1" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-20 pb-4 px-4">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md w-[70%]">
                                    <h4 class="text-[16px]">{{ $t('Knowledge Base') }}</h4>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Enhance your AI assistant by uploading information to improve client interactions.') }}
                                    </span> 
                                </div>
                                <div class="ml-auto w-[40%]">
                                    <div class="float-right flex items-center gap-x-2">
                                        <button @click="isOpenModal = true" class="rounded-md bg-primary px-3 h-8 text-[13px] text-white shadow-sm hover:bg-black focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Upload Documents') }}</button>
                                    </div>
                                </div>
                            </div>
                            <div class="w-5/5">
                                <!-- Table Component-->
                                <DocumentTable :rows="props.rows" :filters="props.filters"/>
                                <div class="px-4 pb-4">
                                    <Pagination class="mt-3" :pagination="props.rows.meta"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :label="$t('AI Assistant Setup')" :isOpen=isOpenFormModal>
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm2()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">
                    <FormInput v-if="getValueByKey('enable_api_key_input') == 1" v-model="form2.api_key" :error="form2.errors.api_key" :name="$t('OpenAI API Key')" :type="'password'" :class="'sm:col-span-6'"/>
                    <FormSelect v-if="getValueByKey('enable_model_selection') == 1" v-model="form2.model" :error="form2.errors.model" :name="$t('Model')" :type="'text'"  :options="models" :class="'sm:col-span-6'"/>
                    <div class="sm:col-span-6 border rounded-md p-2">
                        <div :class="'sm:col-span-6'">
                            <label class="block text-sm leading-6 text-gray-900 mb-1">{{ $t('Integrate AI assistant into chat form') }}</label>
                            <FormToggleSwitch v-model="form2.ai_chat_form_active" :error="form2.errors.ai_chat_form_active" :class="'sm:col-span-6'"/>
                        </div>
                    </div>
                    <div class="sm:col-span-6 border rounded-md p-2">
                        <div class="flex sm:col-span-6 grid grid-cols-6">
                            <div :class="'sm:col-span-3'">
                                <label class="block text-sm leading-6 text-gray-900 mb-1">{{ $t('Activate audio responses') }}</label>
                                <FormToggleSwitch v-model="form2.allow_audio_response" :error="form2.errors.allow_audio_response" :class="'sm:col-span-6'"/>
                            </div>
                            <FormSelect v-model="form2.voice" :error="form2.errors.voice" :name="$t('Audio voice')" :type="'text'"  :options="voices" :class="'sm:col-span-3'"/>
                        </div>
                    </div>

                    <div class="mt-4 flex">
                        <button v-if="settings?.ai?.api_key == null" type="button" @click.self="isOpenFormModal = false; form2.active = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                        <button v-else type="button" @click.self="isOpenFormModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                        <button :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]" :disabled="form2.processing">
                            <svg v-if="form2.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </SettingLayout>

    <DocumentUploadModal :type="'contact'" v-model:modelValue="isOpenModal"/>
</template>
<script setup>
    import SettingLayout from "./../../../../resources/js/Pages/User/Automation/Layout.vue";
    import { ref, watch } from 'vue';
    import { router, useForm } from "@inertiajs/vue3";
    import { trans } from 'laravel-vue-i18n';
    import DocumentTable from '@/Components/Tables/DocumentTable.vue';
    import DocumentUploadModal from '@/Components/DocumentUploadModal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
    import Modal from '@/Components/Modal.vue';
    import Pagination from '@/Components/Pagination.vue';

    const props = defineProps(['appSettings', 'rows', 'filters', 'settings', 'aimodule', 'fbmodule', 'models', 'voices']);
    const config = ref(props.settings.metadata);
    const settings = ref(config.value ? JSON.parse(config.value) : null);
    const isOpenModal = ref(false);
    const isOpenFormModal = ref(false);
    const setupForm = ref(false);

    const getValueByKey = (key) => {
        const found = props.appSettings.find(item => item.key === key);
        return found ? found.value : '';
    };

    const form = useForm({
        active: settings.value?.ai?.active ?? false,
    });

    const form2 = useForm({
        active: settings.value?.ai?.active ?? false,
        api_key: settings.value?.ai?.api_key ?? null,
        model: settings.value?.ai?.model ?? null,
        voice: settings.value?.ai?.voice ?? null,
        allow_audio_response: settings.value?.ai?.allow_audio_response ?? null,
        max_tokens: settings.value?.ai?.max_tokens ?? null,
        temperature: settings.value?.ai?.temperature ?? null,
        ai_chat_form_active: settings.value?.ai?.ai_chat_form_active ?? false,
    });

    const form3 = useForm({
        enable_automatic_responses: settings.value?.ai?.enable_automatic_responses ?? false,
        start_keywords: settings.value?.ai?.start_keywords ?? null,
        stop_keywords: settings.value?.ai?.stop_keywords ?? null,
    });

    const toggleSetupForm = () => {
        setupForm.value = !setupForm.value;
    }

    const toggleState = () => {
        form.active = !form.active;
        submitForm();
    }

    const toggleState2 = () => {
        form3.enable_automatic_responses = !form3.enable_automatic_responses;
    }

    const submitForm = async () => {
        form.post('/automation/ai/activate', {
            preserveScroll: true,
        })
    };

    const submitForm2 = () => {
        form2.post('/automation/ai/setup', {
            preserveScroll: true,
            onSuccess: () => {
                router.visit('/automation/ai', {
                    preserveState: false,
                });
            }
        })
    }

    const submitForm3 = () => {
        form3.post('/automation/ai/assistant-setup', {
            preserveScroll: true,
            onSuccess: () => {
                router.visit('/automation/ai', {
                    preserveState: false,
                });
            }
        })
    }
</script>