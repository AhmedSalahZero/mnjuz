<script setup>
    import { ref } from 'vue';
    import debounce from 'lodash/debounce';
    import AlertModal from '@/Components/AlertModal.vue';
    import { router, useForm } from '@inertiajs/vue3';
    import { useAlertModal } from '@/Composables/useAlertModal';
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
    import FormTextArea from '@/Components/FormTextArea.vue';

    const props = defineProps({
        rows: {
            type: Array,
            required: true,
        },
        filters: {
            type: Object
        },
        language: {
            type: Object
        }
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const selectedString = ref(null);

    const emit = defineEmits(['edit', 'delete']);

    const form = useForm({'test': null});
    
    function edit(id) {
        emit('edit', id);
    }

    function deleteItem(id) {
        emit('delete', id);
    }

    const deleteAction = (key) => {
        form.delete('/admin/languages/' + key);
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

    const translation = ref(null);

    const form2 = useForm({
        'translation': null
    });

    const openTextArea = (key, translation) => {
        selectedString.value = key;
        form2.translation = translation;
    }

    const submitForm = async () => {
        form2.post('/admin/translations/'+ props.language.code +'/' + selectedString.value, {
            preserveScroll: true,
            onFinish: () => {
                selectedString.value = null;
            }
        })
    };
</script>
<template>
    <div class="md:bg-white flex items-center border border-primary md:border-none md:shadow-sm h-12 md:h-10 w-full md:w-80 rounded-[0.5rem] mb-6 text-xl md:text-sm">
        <span class="pl-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
        </span>
        <input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full" :placeholder="$t('Search translation string')">
        <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
        </button>
        <span v-if="isSearching" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
        </span>
    </div>

    <div class="grid grid-cols-2 bg-gray-50 px-2 md:border-b-0 border-[#d1d5db] text-sm rounded-tr-[8px] rounded-tl-[8px]">
        <div class="px-2 py-3 max-w-[25em] truncate">{{ $t('Key') }}</div>
        <div class="px-2 py-3 truncate">{{ $t('Translation') }}</div>
    </div>
    <div v-for="(item, index) in props.rows" :key="index">
        <div class="grid grid-cols-2 bg-white px-2 hover:bg-slate-50 md:border-b-0 md:border-t border-[#d1d5db] text-sm border-b cursor-pointer">
            <div class="p-2 flex items-center">
                <div class="max-w-[24em] truncate">{{ item.Key }}</div>
            </div>
            <div class="p-2 truncate flex items-center w-full">
                <div class="float-left truncate" :class="selectedString != item.Key ? 'max-w-[25em]': 'w-full'">
                    <FormTextArea v-if="selectedString === item.Key" v-model="form2.translation" :name="''" :showLabel="false" :class="'w-full'"/>
                    <span v-else @click="openTextArea(item.Key, item.Translation)">{{ item.Translation }}</span>
                </div>
                
                <div v-if="selectedString === item.Key" class="ml-auto hover:bg-gray-200 p-2 rounded-full" @click="submitForm()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                </div>
                <div v-else class="ml-auto hover:bg-gray-200 p-2 rounded-full" @click="openTextArea(item.Key, item.Translation)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M9.533 11.15A1.823 1.823 0 0 0 9 12.438V15h2.578c.483 0 .947-.192 1.289-.534l7.6-7.604a1.822 1.822 0 0 0 0-2.577l-.751-.751a1.822 1.822 0 0 0-2.578 0z"/><path d="M21 12c0 4.243 0 6.364-1.318 7.682C18.364 21 16.242 21 12 21c-4.243 0-6.364 0-7.682-1.318C3 18.364 3 16.242 3 12c0-4.243 0-6.364 1.318-7.682C5.636 3 7.758 3 12 3"/></g></svg>
                </div>
            </div>
        </div>
    </div>
</template>
  