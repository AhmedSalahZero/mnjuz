<script setup>
    import { ref } from 'vue';
    import debounce from 'lodash/debounce';
    import { router } from '@inertiajs/vue3';
    import { useForm } from "@inertiajs/vue3";
    import AlertModal from '@/Components/AlertModal.vue';
    import { useAlertModal } from '@/Composables/useAlertModal';
    import 'vue3-toastify/dist/index.css';
    import FormCheckbox from '@/Components/FormCheckbox.vue';
    import FormInput from '@/Components/FormInput.vue';
    import Modal from '@/Components/Modal.vue';
    import Table from '@/Components/Table.vue';
    import TableHeader from '@/Components/TableHeader.vue';
    import TableHeaderRow from '@/Components/TableHeaderRow.vue';
    import TableHeaderRowItem from '@/Components/TableHeaderRowItem.vue';
    import TableBody from '@/Components/TableBody.vue';
    import TableBodyRow from '@/Components/TableBodyRow.vue';
    import TableBodyRowItem from '@/Components/TableBodyRowItem.vue';
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
    import DropdownItem from '@/Components/DropdownItem.vue';

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
        events:{
            type: Object
        }
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const form = useForm({'test': null});
    const copiedRef = ref(null);
    const unMaskedRef = ref(null);
    const isOpenModal = ref(false);
    const isOpenTestModal = ref(false);
    const webhookUuid = ref(null);

    const deleteAction = (key) => {
        form.delete('/developer-tools/webhooks/' + key);
    }

    const isLastRow = (index) => {
      return index === props.rows.data.length - 1;
    }

    const emit = defineEmits(['delete']);

    function deleteItem(id) {
        emit('delete', id);
    }

    const copyRow = async (token) => {
        copiedRef.value = token;

        const tempInput = document.createElement("textarea");
        tempInput.value = token;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand("copy");
        document.body.removeChild(tempInput);

        setTimeout(() => {
            copiedRef.value = null;
        }, 2000);   
    };

    const maskToken = (token) => {
        if(unMaskedRef.value === token){
            return token;
        } else {
            return token.replace(/./g, '*'); // Replace each character with '*'
        }
    };

    const unMask = (token) => {
        unMaskedRef.value = token; // Toggle the 'masked' property of the item
    };

    const form2 = useForm({
        url: null,
        events: [],
    });

    const editWebhook = (webhook) => {
        form2.url = webhook.url;
        form2.events = webhook.events.map(event => event.event);
        isOpenModal.value = true;
    }

    const openTestModal = (webhook) => {
        form2.url = webhook.url;
        form2.events = webhook.events.map(event => event.event);
        isOpenTestModal.value = true;
    }

    const form3 = useForm({
        url: null,
    });

    const loadingEvent = ref(null);

    const triggerTest = (url, event) => {
        form3.url = url;
        loadingEvent.value = event;

        form3.post(`/webhooks/trigger/${event}/test`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                // Success handler logic
                loadingEvent.value = null;
            },
            onError: () => {
                // Reset the loading state even if there's an error
                loadingEvent.value = null;
            }
        });
    }

    const submitForm = async () => {
        form2.post('/developer-tools/webhooks/' + webhookUuid.value, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                isOpenModal.value = false
            }
        })
    };
</script>
<template>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                <TableHeaderRowItem :position="'first'">{{ $t('Notification URL') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Events') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="!isLastRow(index) ? 'border-b' : ''">
                <TableBodyRowItem :position="'first'" >
                    <div class="flex">
                        <div class="text-left mr-3 text-sm relative w-[10em] truncate">
                            <span>{{ item.url }}</span> 
                        </div> 
                    </div>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <div class="py-1 px-2 bg-gray-50 rounded-[5px] border border-dashed w-[20em] truncate text-xs capitalize">
                        <span v-for="event in item.events">{{ event.event }}, </span>
                    </div>
                </TableBodyRowItem>
                <TableBodyRowItem :position="'last'">
                    <div class="flex items-center">
                        <button @click="openTestModal(item)" class="bg-slate-100 border rounded-md py-1 px-4 h-[fit-content]">Test</button>
                        <Dropdown v-if="item.role != 'admin'" :align="'right'" class="mt-2">
                            <button class="inline-flex w-full justify-center rounded-md text-sm font-medium text-black hover:bg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75">
                                <span class="hover:bg-[#F6F7F9] hover:rounded-full w-[fit-content] p-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                        <path fill="currentColor" d="M12 16a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2Z"/>
                                    </svg>
                                </span>
                            </button>
                            <template #items>
                                <DropdownItemGroup>
                                    <DropdownItem as="button" @click="editWebhook(item); webhookUuid = item.uuid;">{{ $t('View/edit') }}</DropdownItem>
                                    <DropdownItem as="button" @click="openAlert(item.uuid)">{{ $t('Delete') }}</DropdownItem>
                                </DropdownItemGroup>
                            </template>
                        </Dropdown>
                    </div>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>

    <Modal :label="$t('Edit/View Webhook')" :isOpen=isOpenModal>
        <div class="mt-5 grid grid-cols-1 gap-x-6">
            <form @submit.prevent="submitForm()" class="grid gap-x-6 sm:grid-cols-6">
                <FormInput v-model="form2.url" :error="form2.errors.url" :name="$t('URL')" :type="'text'" :class="'sm:col-span-6'"/>
                <h4 class="mt-4 text-sm">{{ $t('Events') }}</h4>
                <FormCheckbox v-for="event in props.events" :key="event" v-model="form2.events" :error="form2.errors.events" :name="$t(event)" :label="$t(event)" :value="event" :type="'text'" :class="'sm:col-span-6'"/>
                
                <div class="mt-4 flex">
                    <button type="button" @click.self="isOpenModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button 
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]"
                        :disabled="form2.processing"
                    >
                        <svg v-if="form2.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>

    <Modal :label="$t('Test Webhook Notifications')" :isOpen=isOpenTestModal>
        <div class="mt-5 grid grid-cols-1 gap-x-6">
            <div>
                <h4 class="mb-2">Webhook URL</h4>
                <h5 class="text-sm bg-slate-100 py-1 rounded-md px-2">{{ form2.url }}</h5>
            </div>
            <div class="mt-4">
                <h4 class="mb-2">Events</h4>
                <div v-for="event in form2.events">
                    <div class="flex items-center justify-between border-b py-1 text-sm">
                        <div>
                            <span class="capitalize">{{ event }}</span>
                        </div>
                        <div>
                            <button @click="triggerTest(form2.url, event)" class="bg-slate-100 border rounded-md py-1 px-4 h-[fit-content]">
                                {{ loadingEvent === event ? 'Sending...' : 'Test' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="grid gap-x-6 sm:grid-cols-6">
                <div class="mt-4 flex">
                    <button type="button" @click.self="isOpenTestModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                </div>
            </div>
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