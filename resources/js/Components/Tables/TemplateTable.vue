<script setup>
    import axios from 'axios';
    import AlertModal from '@/Components/AlertModal.vue';
    import { Link } from "@inertiajs/vue3";
    import { useAlertModal } from '@/Composables/useAlertModal';
    import { toast } from 'vue3-toastify';
    import 'vue3-toastify/dist/index.css';
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
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const deleteAction = async (key) => {
        try {
            const response = await axios.delete(`/templates/${key}`);

            if (response.status === 200 && response.data.success) {
                const idx = props.rows.data.findIndex((i) => i.uuid === key);
                props.rows.data.splice(idx, 1);
            }

            toast(response.data.message, {
                autoClose: 3000,
            });
        } catch (error) {
            //console.log(error);
        }
    };
    
    const emit = defineEmits(['delete']);

    function deleteItem(id) {
        emit('delete', id);
    }

    const isLastRow = (index) => {
        return index === props.rows.data.length - 1;
    }

    const capitalizeFirstLetter = (string) => {
        return string.charAt(0).toUpperCase() + string.slice(1).toLowerCase();
    }

    const findBodyText = (item) => {
        const metadataArray = JSON.parse(item);
        const bodyArray = metadataArray.components.find(element => element.type === "BODY");

        if (bodyArray) {
            return bodyArray.text;
        }

        return 'N/A';
    }
</script>
<template>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                <TableHeaderRowItem :position="'first'">{{ $t('Name') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Category') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Preview') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Status') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Last updated') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="!isLastRow(index) ? 'border-b' : ''">
                <TableBodyRowItem :position="'first'">{{ item.name }}</TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">{{ capitalizeFirstLetter(item.category) }}</TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <div class="py-1 px-2 bg-gray-50 rounded-[5px] border border-dashed w-[20em] truncate text-xs">
                        {{ findBodyText(item.metadata) }}
                    </div>
                </TableBodyRowItem>
                <TableBodyRowItem>
                    <span v-if="item.status == 'APPROVED'" class="py-1 rounded-[5px] text-xs px-3 bg-[#ebfdf4] text-[#38733f]">{{ $t(capitalizeFirstLetter(item.status)) }}</span>
                    <span v-else-if="item.status == 'REJECTED'" class="py-1 rounded-[5px] text-xs px-3 bg-[#fae6e6] text-[darkred]">{{ $t(capitalizeFirstLetter(item.status)) }}</span>
                    <span v-else class="py-1 rounded-[5px] text-xs px-3 bg-[#ddebf7] text-slate-700">{{ $t(capitalizeFirstLetter(item.status)) }}</span>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">{{ item.updated_at }}</TableBodyRowItem>
                <TableBodyRowItem :position="'last'">
                    <Dropdown :align="'right'" class="mt-2">
                        <button class="inline-flex w-full justify-center rounded-md text-sm font-medium text-black hover:bg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75">
                            <span class="hover:bg-[#F6F7F9] hover:rounded-full w-[fit-content] p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M12 16a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2Z"/>
                                </svg>
                            </span>
                        </button>
                        <template #items>
                            <DropdownItemGroup>
                                <DropdownItem v-if="item.status == 'APPROVED' || item.status == 'REJECTED' || item.status == 'PAUSED'" :href="'/templates/' + item.uuid">{{ $t('edit') }}</DropdownItem>
                                <DropdownItem as="button" @click="openAlert(item.uuid)">{{ $t('Delete') }}</DropdownItem>
                            </DropdownItemGroup>
                        </template>
                    </Dropdown>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>
    <div v-if="rows.data.length == 0" class="bg-white rounded-xl">
        <div class="p-4 py-8">
            <div class="flex justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 32 32"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M12 15h8m-8 4h8m8 5V11c0-1.105-.892-2-1.997-2H17c-2 0-2-3-5-3H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h20a2 2 0 0 0 2-2Z"></path></svg>
            </div>
            <h3 class="text-center text-lg font-medium mb-4">{{ $t('You don\'t have any templates') }}</h3>
            <div class="flex justify-center">
                <Link href="/templates/create" class="rounded-md px-3 py-2 text-sm hover:shadow-md text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary" :disabled="isLoading">
                    <span v-if="!isLoading">{{ $t('Create template') }}</span>
                </Link>
            </div>
        </div>
    </div>

    <!-- Alert Modal Component-->
    <AlertModal 
        v-model="isOpenAlert" 
        @confirm="() => confirmAlert(deleteAction)"
        :label = "$t('Delete row')" 
        :description = "$t('Are you sure you want to delete this row? This action can not be undone')"
    />
</template>
  