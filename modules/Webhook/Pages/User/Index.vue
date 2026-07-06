<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit md:flex md:flex-grow md:overflow-y-hidden">
            <div class="md:w-[60%] m-8">
                <Menu />
                <div class="flex justify-between mt-8">
                    <div>
                        <h3 class="text-md mb-1">{{ $t('Webhooks') }}</h3>
                        <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2"
                                    d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                            </svg>
                            <span class="ml-1 mt-1">{{ $t('Manage webhook urls and events') }}</span>
                        </p>
                    </div>
                    <div>
                        <button @click="isOpenModal = true" type="button"
                            class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                            <span>{{ $t('Add Webhook') }}</span>
                        </button>
                    </div>
                </div>
                <WebhookTable :rows="props.rows" :events="props.events" />
            </div>
            <div class="md:w-[40%] border-l bg-black h-screen hidden md:block">
                <Documentation :apirequests="apirequests"/>
            </div>
        </div>

        <Modal :label="$t('Add Webhook')" :isOpen=isOpenModal>
            <div class="mt-5 grid grid-cols-1 gap-x-6">
                <form @submit.prevent="submitForm()" class="grid gap-x-6 sm:grid-cols-6">
                    <FormInput v-model="form.url" :error="form.errors.url" :name="$t('URL')" :type="'text'" :class="'sm:col-span-6'"/>
                    <h4 class="mt-4 text-sm">{{ $t('Events') }}</h4>
                    <FormCheckbox v-for="event in props.events" :key="event" v-model="form.events" :error="form.errors.events" :name="$t(event)" :label="$t(event)" :value="event" :type="'text'" :class="'sm:col-span-6'"/>

                    
                    <div class="mt-4 flex">
                        <button type="button" @click.self="isOpenModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                        <button 
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]"
                            :disabled="form.processing"
                        >
                            <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "../../../../resources/js/Pages/User/Layout/App.vue";
    import Documentation from "../../../../resources/js/Pages/User/Developer/Documentation.vue";
    import FormCheckbox from '@/Components/FormCheckbox.vue';
    import FormInput from '@/Components/FormInput.vue';
    import Menu from "../../../../resources/js/Pages/User/Developer/Menu.vue";
    import Modal from '@/Components/Modal.vue';
    import WebhookTable from './WebhookTable.vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import { ref } from 'vue';

    const props = defineProps({ rows: Object, url: String, apirequests: Object, events: Object });

    const isOpenModal = ref(false);
    const selectedEvents = ref([]);

    const form = useForm({
        url: null,
        events: [],
    });

    const submitForm = async () => {
        form.post('/developer-tools/webhooks', {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                isOpenModal.value = false
            }
        })
    };
</script>