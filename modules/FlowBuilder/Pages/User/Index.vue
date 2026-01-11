<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm pb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-2 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('My Automations') }}</h4>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Respond automatically to messages based on your own criteria') }}
                                    </span> 
                                </div>
                                <div class="ml-auto">
                                    <button @click="isOpenFormModal = true;" class="float-right rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('New Automation') }}</button>
                                </div>
                            </div>
                            <div class="w-5/5">
                                <!-- Table Component-->
                                <FlowsTable :rows="props.rows" :filters="props.filters"/>
                                <div class="px-4 pb-4">
                                    <Pagination class="mt-3" :pagination="props.rows.meta"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :label="$t('Create Automation')" :isOpen=isOpenFormModal>
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">
                    <FormInput v-model="form.name" :error="form.errors.name" :name="$t('Name')" :type="'text'" :class="'sm:col-span-6'"/>
                    <FormTextArea v-model="form.description" :error="form.errors.description" :name="$t('Description')" :class="'sm:col-span-6'"/>

                    <div class="mt-4 flex">
                        <button type="button" @click.self="isOpenFormModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                        <button :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]" :disabled="form.processing">
                            <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </SettingLayout>
</template>
<script setup>
    import SettingLayout from "./../../../../resources/js/Pages/User/Automation/Layout.vue";
    import { ref } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import FlowsTable from '@modules/FlowBuilder/Pages/User/Components/FlowsTable.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import Modal from '@/Components/Modal.vue';
    import Pagination from '@/Components/Pagination.vue';

    const props = defineProps(['rows', 'filters', 'aimodule', 'fbmodule']);
    const isOpenFormModal = ref(false);

    const form = useForm({
        name: null,
        description: null
    });

    const submitForm = () => {
        form.post('/automation/flows', {
            preserveScroll: true,
            onSuccess: () => {
                router.visit('/automation/ai', {
                    preserveState: false,
                });
            }
        })
    }
</script>