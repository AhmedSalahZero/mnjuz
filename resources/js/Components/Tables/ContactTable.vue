<script setup>
    import { computed, ref, watchEffect, onMounted, onUpdated } from 'vue';
    import debounce from 'lodash/debounce';
    import { Link, router, usePage } from "@inertiajs/vue3";
    import ContactImportModal from '@/Components/ContactImportModal.vue';
    import ExportModal from '@/Components/ExportModal.vue';
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
    import DropdownItem from '@/Components/DropdownItem.vue';
    import Modal from '@/Components/Modal.vue';
    import Pagination from '@/Components/Pagination.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps(['rows', 'filters', 'type']);

    const params = ref({
        id: props.filters?.id,
        search: props.filters?.search,
        page: props.filters?.page
    });
    
    const isOpenModal = ref(false);
    const isExportModalOpen = ref(false);
    const isSearching = ref(false);
    const emit = defineEmits(['callback']);
    const bulkCheckbox = ref(false);
    const selectedCount = ref(0);
    const checkedContacts = ref([]);
    const checkedGroups = ref([]);

    function getRow(value) {
        params.value.id = value;

        const filteredParams = Object.fromEntries(
            Object.entries(params.value).filter(([_, value]) => value !== null)
        );

        emit('callback', filteredParams);
    }

    const clearSearch = () => {
        params.value.search = null;
        runSearch();
    }

    const search = debounce(() => {
        params.value.page = null;
        isSearching.value = true;
        runSearch();
    }, 1000);

    const runSearch = () => {
        const filteredParams = Object.fromEntries(
            Object.entries(params.value).filter(([_, value]) => value !== null)
        );

        router.visit(props.type === 'contact' ? '/contacts' : '/contact-groups', {
            method: 'get',
            data: filteredParams,
        })
    }

    // Save checked items to local storage
    function saveCheckedItems() {
        if(props.type === 'contact'){
            localStorage.setItem('checkedContacts', JSON.stringify(checkedContacts.value));
        } else {
            localStorage.setItem('checkedGroups', JSON.stringify(checkedGroups.value));
        }
    }

    // Load checked items from local storage
    function loadCheckedItems() {
        if(props.type === 'contact'){
            const savedItems = localStorage.getItem('checkedContacts');
            checkedContacts.value = savedItems ? JSON.parse(savedItems) : [];
        } else {
            const savedItems = localStorage.getItem('checkedGroups');
            checkedGroups.value = savedItems ? JSON.parse(savedItems) : [];
        }
    }

    // Function to update checked items array
    function updateCheckedItems(uuid, isChecked) {
        if(props.type === 'contact'){
            const index = checkedContacts.value.indexOf(uuid);

            if (isChecked && index === -1) {
                checkedContacts.value.push(uuid);
            } else if (!isChecked && index !== -1) {
                checkedContacts.value.splice(index, 1);
            }
        } else {
            const index = checkedGroups.value.indexOf(uuid);

            if (isChecked && index === -1) {
                checkedGroups.value.push(uuid);
            } else if (!isChecked && index !== -1) {
                checkedGroups.value.splice(index, 1);
            }
        }

        saveCheckedItems();
    }

    // Function to toggle individual checkbox
    function toggleCheckbox(contactUuid) {
        const contact = props.rows.data.find(contact => contact.uuid === contactUuid);
        contact.isChecked = !contact.isChecked;
        updateCheckedItems(contactUuid, contact.isChecked);
        updateBulkCheckboxState();
        updateSelectedCount();
    }

    // Function to toggle all checkboxes
    function toggleAllCheckboxes() {
        bulkCheckbox.value = !bulkCheckbox.value;
        props.rows.data.forEach(row => {
            row.isChecked = bulkCheckbox.value;
            updateCheckedItems(row.uuid, bulkCheckbox.value);
        });
        updateSelectedCount();
    }

    // Function to apply checked state from local storage
    function applyCheckedState() {
        props.rows.data.forEach(row => {
            row.isChecked = props.type === 'contact' ? checkedContacts.value.includes(row.uuid) : checkedGroups.value.includes(row.uuid);
        });
        updateBulkCheckboxState();
        updateSelectedCount();
    }

    // Function to update bulk checkbox state
    function updateBulkCheckboxState() {
        bulkCheckbox.value = props.rows.data.length > 0 && props.rows.data.every(row => row.isChecked);
    }

    // Function to update selected count based on checked items array
    function updateSelectedCount() {
        selectedCount.value = props.type === 'contact' ? checkedContacts.value.length : checkedGroups.value.length;
    }

    function deleteItems(value){
        const itemsToDelete = props.type === 'contact' ? checkedContacts.value : checkedGroups.value;

        router.visit(props.type === 'contact' ? '/contacts' : '/contact-groups', {
            method: 'delete',
            data: { 'uuids': value === 'all' ? [] : itemsToDelete },
            preserveState: true,
            onSuccess: () => {
                localStorage.removeItem(props.type === 'contact' ? 'checkedContacts' : 'checkedGroups');
                if(props.type === 'contact'){
                    checkedContacts.value = [];
                } else {
                    checkedGroups.value = [];
                }
            }
        })
    }

    // Lifecycle hooks
    onMounted(() => {
        loadCheckedItems();
        applyCheckedState();
    });

    onUpdated(() => {
        applyCheckedState();
    });

    watchEffect(() => {
        params.value.page = props.filters?.page;
        applyCheckedState();
    });
</script>
<template>
    <div class="px-4 pb-2">
        <div class="border border-[#f0f2f5] rounded-md mt-6 flex items-center py-[2px] md:py-[2px]">
            <span class="pl-3 py-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
            </span>
            <input 
                @input="search" 
                v-model="params.search" 
                type="text" 
                class="w-full outline-none rounded-xl py-2 pl-2 mr-2 text-sm" 
                :placeholder="type === 'contact' ? $t('Search name or phone or email') : $t('Search name')"
            >
            <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
            </button>
            <span v-if="isSearching" class="pr-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
            </span>
        </div>
    </div>
    <div class="flex justify-between px-4 border-b">
        <div class="flex items-center space-x-2">
            <label @click="toggleAllCheckboxes($event)" for="myCheckbox" class="cursor-pointer">
                <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center" :class="{ 'bg-secondary': bulkCheckbox }">
                    <svg v-if="bulkCheckbox" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
            </label>
            <label @click="toggleAllCheckboxes($event)" class="cursor-pointer text-sm">
                <span v-if="selectedCount == 0">{{ $t('Select all') }} ({{ selectedCount }})</span>
                <span v-else-if="selectedCount > 0">{{ selectedCount }} {{ $t('selected') }}</span>
            </label>
        </div>
        <div>
            <div class="float-right">
                <Dropdown :align="'right'" class="mt-2">
                    <button class="inline-flex w-full justify-center rounded-md text-sm font-medium text-black hbg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75">
                        <span class="hover:shadow-md bg-slate-50 rounded-full w-[fit-content] p-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 16 16"><path fill="black" d="M8 2.5a1.22 1.22 0 0 1 1.25 1.17A1.21 1.21 0 0 1 8 4.84a1.21 1.21 0 0 1-1.25-1.17A1.22 1.22 0 0 1 8 2.5m0 8.66a1.17 1.17 0 1 1-1.25 1.17A1.21 1.21 0 0 1 8 11.16m0-4.33a1.17 1.17 0 1 1 0 2.34a1.17 1.17 0 1 1 0-2.34"/></svg>
                        </span>
                    </button>
                    <template #items>
                        <DropdownItemGroup>
                            <DropdownItem as="button" @click="isOpenModal = true">{{ $t('Import rows') }}</DropdownItem>
                            <DropdownItem as="button" @click="isExportModalOpen = true">{{ $t('Export') }}</DropdownItem>
                            <DropdownItem v-if="selectedCount > 0" as="button" @click="deleteItems()">{{ $t('Delete selected') }}</DropdownItem>
                            <DropdownItem as="button" @click="deleteItems('all')">{{ $t('Delete all') }}</DropdownItem>
                        </DropdownItemGroup>
                    </template>
                </Dropdown>
            </div>
        </div>
    </div>
    <div class="h-[5vh]">
        <div class="flex justify-between text-sm border-b">
            <Link href="/contacts" class="pt-3 w-1/2 text-center pb-1 hover:bg-slate-50" :class="{ 'bg-gray-50 border-b-2 border-slate-700': $page.url.startsWith('/contacts') }">{{ $t('All contacts') }}</Link>
            <Link href="/contact-groups" class="pt-3 w-1/2 text-center pb-1 hover:bg-slate-50" :class="{ 'bg-gray-50 border-b-2 border-slate-700': $page.url.startsWith('/contact-groups') }">{{ $t('Groups') }}</Link>
        </div>
    </div>
    <div class="flex-grow overflow-y-auto h-[65vh]" ref="scrollContainer">
            <div v-if="type === 'contact'" @click="getRow(contact.uuid)" class="flex space-x-2 hover:bg-gray-50 cursor-pointer px-4 py-3 border-b" :class="contact.isChecked ? 'bg-gray-50' : ''" v-for="(contact, index) in rows.data" :key="index">
            <div>
                <label @click.stop="toggleCheckbox(contact.uuid)" for="myCheckbox" class="cursor-pointer">
                    <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center mt-1" :class="{ 'bg-secondary': contact.isChecked }">
                        <svg v-if="contact.isChecked" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </label>
            </div>
            <div class="w-[15%]">
                <img v-if="contact.avatar" class="rounded-full h-12 w-12" :src="contact.avatar">
                <div v-else class="rounded-full bg-secondary/10 text-secondary flex justify-center items-center h-12 w-12">{{ contact.first_name?.substring(0, 1) }}</div>
            </div>
            <div class="w-[75%]">
                <h3>{{ contact?.full_name }}</h3>
                <p class="text-slate-500 text-xs truncate">{{ contact.formatted_phone_number }}</p>
            </div>
            <div class="w-[10%]">
                <svg v-if="contact.is_favorite" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="#FFD700" d="M9.153 5.408C10.42 3.136 11.053 2 12 2c.947 0 1.58 1.136 2.847 3.408l.328.588c.36.646.54.969.82 1.182c.28.213.63.292 1.33.45l.636.144c2.46.557 3.689.835 3.982 1.776c.292.94-.546 1.921-2.223 3.882l-.434.507c-.476.557-.715.836-.822 1.18c-.107.345-.071.717.001 1.46l.066.677c.253 2.617.38 3.925-.386 4.506c-.766.582-1.918.051-4.22-1.009l-.597-.274c-.654-.302-.981-.452-1.328-.452c-.347 0-.674.15-1.328.452l-.596.274c-2.303 1.06-3.455 1.59-4.22 1.01c-.767-.582-.64-1.89-.387-4.507l.066-.676c.072-.744.108-1.116 0-1.46c-.106-.345-.345-.624-.821-1.18l-.434-.508c-1.677-1.96-2.515-2.941-2.223-3.882c.293-.941 1.523-1.22 3.983-1.776l.636-.144c.699-.158 1.048-.237 1.329-.45c.28-.213.46-.536.82-1.182z"/></svg>
            </div>
        </div>
        <div v-else-if="type === 'group'" @click="getRow(row.uuid)" class="flex space-x-2 hover:bg-gray-50 cursor-pointer px-4 py-3 border-b" :class="row.isChecked ? 'bg-gray-50' : ''" v-for="(row, key) in rows.data" :key="key">
            <div>
                <label @click.stop="toggleCheckbox(row.uuid)" for="myCheckbox" class="cursor-pointer">
                    <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center mt-1" :class="{ 'bg-secondary': row.isChecked }">
                        <svg v-if="row.isChecked" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </label>
            </div>
            <div class="w-[15%]">
                <div class="rounded-full bg-secondary/10 text-secondary flex justify-center items-center h-12 w-12 capitalize">{{ row.name.substring(0, 1) }}</div>
            </div>
            <div class="w-[85%] flex items-center">
                <h3>{{ row.name }}</h3>
            </div>
        </div>
    </div>
    <div class="px-4 pb-4">
        <Pagination class="mt-3" :pagination="rows.meta"/>
    </div>
    <ContactImportModal :type="type" v-model:modelValue="isOpenModal"/>
    <ExportModal :type="type" v-model:modelValue="isExportModalOpen"/>
</template>
  