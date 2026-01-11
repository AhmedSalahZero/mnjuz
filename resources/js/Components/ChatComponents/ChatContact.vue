<script setup>
    import axios from 'axios';
    import { ref, watchEffect } from 'vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
    import DropdownItem from '@/Components/DropdownItem.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import Modal from '@/Components/Modal.vue';
    import { trans } from 'laravel-vue-i18n';
    
    const props = defineProps(['contact', 'fields', 'locationSettings']);
    const contact = ref(props.contact);
    const metadata = (props.contact.metadata !== null && props.contact.metadata !== '') ? JSON.parse(props.contact.metadata) : {};
    const isOpenModal = ref(false);
    const isLoading = ref(false);
    const notes = ref(contact.notes);

    watchEffect(() => {
        contact.value = props.contact;
    });

    const favorite = async() => {
        contact.value.is_favorite = !contact.value.is_favorite;

        router.put('/contacts/favorite/' + contact.value.uuid, { favorite: contact.value.is_favorite });
    }

    const form = useForm({
        'notes': null,
        'contact': null
    });

    const deleteRow = async() => {
        form.delete('/contacts/' + contact.value.uuid);
    }

    const getAddressDetail = (value, key) => {
        const address = JSON.parse(value);
        return address?.[key] && address?.[key] != 'Not Set' ? address?.[key] : trans('not set');
    }

    const submitForm = () => {
        form.contact = contact.value.uuid;
        isLoading.value = true;

        form.post('/notes', {
            onSuccess: () => {
                form.reset();
                isOpenModal.value = false;
                contact.value = usePage().props.flash?.status?.contact;
            },
            onFinish: () => {
                isLoading.value = false;
            }
        });
    }
</script>
<template>
    <div class="overflow-y-auto h-screen w-full">
        <div class="pb-6 border-b">
            <div class="flex justify-center w-full">
                <div class="rounded-full p-1">
                    <img v-if="contact.avatar" class="rounded-full w-20 h-20" :src="contact.avatar">
                    <div v-else class="rounded-full w-20 h-20">
                        <svg class="text-gray-300" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M18.685 19.097A9.723 9.723 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75S2.25 6.615 2.25 12a9.723 9.723 0 003.065 7.097A9.716 9.716 0 0012 21.75a9.716 9.716 0 006.685-2.653zm-12.54-1.285A7.486 7.486 0 0112 15a7.486 7.486 0 015.855 2.812A8.224 8.224 0 0112 20.25a8.224 8.224 0 01-5.855-2.438zM15.75 9a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
            </div>
            <div>
                <h3 class="text-xl text-center">{{ contact.first_name + ' ' + contact.last_name }}</h3>
                <div class="text-slate-500 truncate flex items-center justify-center">
                    <span class="text-sm">{{ contact.formatted_phone_number }}</span>
                </div>
                <div class="flex gap-x-3 mt-4 justify-center">
                    <button class="bg-gray-200 px-4 rounded-md flex items-center">
                        <Link :href="'/contacts/' + contact.uuid + '?edit=true'" class="text-sm">{{ $t('Edit') }}</Link>
                    </button>
                    <button @click="favorite()" class="bg-gray-200 px-4 rounded-md">
                        <svg v-if="contact.is_favorite === 0 || contact.is_favorite === false" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M9.153 5.408C10.42 3.136 11.053 2 12 2c.947 0 1.58 1.136 2.847 3.408l.328.588c.36.646.54.969.82 1.182c.28.213.63.292 1.33.45l.636.144c2.46.557 3.689.835 3.982 1.776c.292.94-.546 1.921-2.223 3.882l-.434.507c-.476.557-.715.836-.822 1.18c-.107.345-.071.717.001 1.46l.066.677c.253 2.617.38 3.925-.386 4.506c-.766.582-1.918.051-4.22-1.009l-.597-.274c-.654-.302-.981-.452-1.328-.452c-.347 0-.674.15-1.329.452l-.595.274c-2.303 1.06-3.455 1.59-4.22 1.01c-.767-.582-.64-1.89-.387-4.507l.066-.676c.072-.744.108-1.116 0-1.46c-.106-.345-.345-.624-.821-1.18l-.434-.508c-1.677-1.96-2.515-2.941-2.223-3.882c.293-.941 1.523-1.22 3.983-1.776l.636-.144c.699-.158 1.048-.237 1.329-.45c.28-.213.46-.536.82-1.182l.328-.588Z"/></svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="#031b4d" stroke="#031b4d" stroke-width="1.5" d="M9.153 5.408C10.42 3.136 11.053 2 12 2c.947 0 1.58 1.136 2.847 3.408l.328.588c.36.646.54.969.82 1.182c.28.213.63.292 1.33.45l.636.144c2.46.557 3.689.835 3.982 1.776c.292.94-.546 1.921-2.223 3.882l-.434.507c-.476.557-.715.836-.822 1.18c-.107.345-.071.717.001 1.46l.066.677c.253 2.617.38 3.925-.386 4.506c-.766.582-1.918.051-4.22-1.009l-.597-.274c-.654-.302-.981-.452-1.328-.452c-.347 0-.674.15-1.329.452l-.595.274c-2.303 1.06-3.455 1.59-4.22 1.01c-.767-.582-.64-1.89-.387-4.507l.066-.676c.072-.744.108-1.116 0-1.46c-.106-.345-.345-.624-.821-1.18l-.434-.508c-1.677-1.96-2.515-2.941-2.223-3.882c.293-.941 1.523-1.22 3.983-1.776l.636-.144c.699-.158 1.048-.237 1.329-.45c.28-.213.46-.536.82-1.182l.328-.588Z"/></svg>
                    </button>
                    <Dropdown class="h-100" :align="'right'">
                        <button class=" bg-gray-200 py-2 px-3 rounded-md flex items-center">
                            <span class="">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 16 16"><path fill="currentColor" d="M4 8a2 2 0 1 1-4 0a2 2 0 0 1 4 0Zm6 0a2 2 0 1 1-4 0a2 2 0 0 1 4 0Zm4 2a2 2 0 1 0 0-4a2 2 0 0 0 0 4Z"/></svg>
                            </span>
                        </button>
                        <template #items>
                            <DropdownItemGroup>
                                <!--<DropdownItem as="button" @click="openModal('edit')">Edit Contact</DropdownItem>-->
                                <DropdownItem as="button" @click="deleteRow()">{{ $t('Delete') }}</DropdownItem>
                            </DropdownItemGroup>
                        </template>
                    </Dropdown>
                </div>
            </div>
        </div>
        <div class="py-4 px-4 space-y-1 text-sm">
            <h4 class="mb-4">Contact Details</h4>
            <div class="flex">
                <div class="w-[30%]">
                    <span class="text-slate-500">{{ $t('Group') }}</span>
                </div>
                <div>
                    <span class="p-1 bg-gray-50 text-xs rounded-md text-gray-500 capitalize px-2">{{ contact.contact_group?.name ?? $t('not set') }}</span>
                </div>
            </div>
            <div class="flex">
                <div class="w-[30%]">
                    <span class="text-slate-500">{{ $t('Email') }}</span>
                </div>
                <div>
                    <span>{{ contact.email }}</span>
                </div>
            </div>
        </div>
        <div v-if="locationSettings === 'before' && fields.length > 0" class="border-gray-300 border-b py-4">
            <div v-for="(input, index) in props.fields" class="grid grid-cols-2 gap-x-2 text-sm">
                <div class="text-right text-slate-500 pb-2">
                    <span>{{ $t(input.name) }}</span>
                </div>
                <div>
                    <span v-if="metadata[input.name] != null">{{ metadata[input.name] }}</span>
                    <span v-else class="p-1 bg-slate-100 text-xs rounded-md text-gray-600">{{ $t('not set') }}</span>
                </div>
            </div>
        </div>
        <div class="border-t py-4 px-4 space-y-1 text-sm">
            <h4 class="mb-4">Address</h4>
            <div class="flex">
                <div class="w-[30%]">
                    <span class="text-slate-500">{{ $t('Street') }}</span>
                </div>
                <div>
                    <span>{{ getAddressDetail(contact.address, 'street') }}</span>
                </div>
            </div>
            <div class="flex">
                <div class="w-[30%]">
                    <span class="text-slate-500">{{ $t('City') }}</span>
                </div>
                <div>
                    <span>{{ getAddressDetail(contact.address, 'city') }}</span>
                </div>
            </div>
            <div class="flex">
                <div class="w-[30%]">
                    <span class="text-slate-500">{{ $t('State') }}</span>
                </div>
                <div>
                    <span>{{ getAddressDetail(contact.address, 'state') }}</span>
                </div>
            </div>
            <div class="flex">
                <div class="w-[30%]">
                    <span class="text-slate-500">{{ $t('Zip code') }}</span>
                </div>
                <div>
                    <span>{{ getAddressDetail(contact.address, 'zip') }}</span>
                </div>
            </div>
            <div class="flex">
                <div class="w-[30%]">
                    <span class="text-slate-500">{{ $t('Country') }}</span>
                </div>
                <div>
                    <span>{{ getAddressDetail(contact.address, 'country') }}</span>
                </div>
            </div>
        </div>
        <div class="border-t py-4 px-4 space-y-1">
            <div class="flex">
                <div class="mb-3">
                    <h4 class="mb-0">Notes</h4>
                    <span class="text-xs text-slate-500">Store notes for your team</span>
                </div>
                <div class="ml-auto">
                    <button @click="isOpenModal = true;" class="bg-slate-50 p-2 rounded-full shadow-md hover:bg-slate-100 hover:shadow-xl">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path fill="currentColor" fill-rule="evenodd" d="M10 3a1 1 0 0 1 1 1v5h5a1 1 0 1 1 0 2h-5v5a1 1 0 1 1-2 0v-5H4a1 1 0 1 1 0-2h5V4a1 1 0 0 1 1-1" clip-rule="evenodd"/></svg>
                    </button>
                </div>
            </div>
            <div v-for="(item, index) in contact.notes" :key="index" class="bg-gray-50 text-sm p-2 rounded-md">
                <div class="mb-1 font-regular">{{ item.content }}</div>
                <span class="font-light text-gray-500 text-xs">{{ item.created_at }}</span>
            </div>
        </div>
        <div v-if="locationSettings === 'after' && fields.length > 0" class="pr-20 border-gray-300 border-t py-4">
            <div v-for="(input, index) in props.fields" class="grid grid-cols-2 space-x-8 text-[14px]">
                <div class="text-right text-slate-500 pb-2">
                    <span>{{ $t(input.name) }}</span>
                </div>
                <div>
                    <span v-if="metadata[input.name] != null">{{ metadata[input.name] }}</span>
                    <span v-else class="p-1 bg-slate-100 text-xs rounded-md text-gray-600">{{ $t('not set') }}</span>
                </div>
            </div>
        </div>
    </div>

    <Modal :label="'Add Note'" :isOpen="isOpenModal">
        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <form @submit.prevent="submitForm()" class="">
                <FormTextArea v-model="form.notes" :error="form.errors.note" :name="''" :class="'col-span-2'"/>
                <div class="mt-4 flex">
                    <button type="button" @click.self="isOpenModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
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