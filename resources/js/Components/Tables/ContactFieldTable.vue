<script setup>
    import AlertModal from '@/Components/AlertModal.vue';
    import { useForm } from "@inertiajs/vue3";
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
    import Pagination from '@/Components/Pagination.vue';
    import draggable from 'vuedraggable';
    import axios from 'axios';

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const emit = defineEmits(['edit', 'delete']);

    const form = useForm({'test': null});
    
    function edit(id) {
        emit('edit', id);
    }

    function deleteItem(id) {
        emit('delete', id);
    }

    const deleteAction = (key) => {
        form.delete('/contact-fields/' + key);
    }

    const isLastRow = (index) => {
        return index === props.rows.data.length - 1;
    }

    const list = props.rows.data;

    const capitalizeFirstLetter = (string) => {
        return string.charAt(0).toUpperCase() + string.slice(1);
    };

    // Handle drag end event to save new order
    const onDragEnd = async () => {
        // Get the new order of UUIDs
        const orderedIds = props.rows.data.map(item => item.uuid);
        
        try {
            const response = await axios.post('/contact-fields/update-positions', {
                orderedIds: orderedIds
            });
            
            // Show success message
            if (response.data.success) {
                // Success - order updated
            }
        } catch (error) {
            console.error('Error updating contact field order:', error);
            // Optionally show error message to user
        }
    };
</script>
<template>
    <div>
        <div class="bg-slate-100 md:bg-slate-50 rounded-[0.5rem]">
            <table class="w-full">
                <TableHeader>
                    <TableHeaderRow>
                        <TableHeaderRowItem :position="'first'" class="pl-2 py-2 pb-2 ">{{ $t('Input field name') }}</TableHeaderRowItem>
                        <TableHeaderRowItem class="pl-2 py-2 pb-2">{{ $t('Input type') }}</TableHeaderRowItem>
                        <TableHeaderRowItem class="pl-2 py-2 pb-2">{{ $t('Is required') }}</TableHeaderRowItem>
                        <TableHeaderRowItem :position="'last'" class="pl-2 py-2 pb-2"></TableHeaderRowItem>
                    </TableHeaderRow>
                </TableHeader>
                <draggable 
                    tag="tbody" 
                    :list="rows.data" 
                    handle=".handle" 
                    item-key="uuid"
                    @update="onDragEnd"
                >
                    <template #item="{ element, index }">
                        <tr class="hover:bg-slate-50 md:border-b-0 md:border-t border-[#d1d5db] text-sm">
                            <td class="pl-4 py-4">
                                <div class="flex">
                                    <div class="handle cursor-pointer mr-4">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M9 19.23q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36"/></svg>
                                    </div>
                                    <div>{{ element.name }}</div>
                                </div>
                            </td>
                            <td class="pl-2 py-2 pb-2 capitalize">
                                <span class="mr-2">{{ $t(capitalizeFirstLetter(element.type)) }}</span>
                                <span v-if="element.type === 'input'" class="bg-slate-200 px-2 py-1 rounded-lg text-xs capitalize">{{ $t(capitalizeFirstLetter(element.value)) }}</span>
                            </td>
                            <td class="pl-2 py-2 pb-2 capitalize">{{ element.required === 0 ? $t('No') : $t('Yes') }}</td>
                            <td class="pr-4 float-right">
                                <Dropdown :align="'right'" class="mt-2">
                                <button
                                    class="inline-flex w-full justify-center rounded-md text-sm font-medium text-black hover:bg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75"
                                >
                                    <span class="hover:bg-[#F6F7F9] hover:rounded-full w-[fit-content] p-2">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="20"
                                        height="20"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                        fill="currentColor"
                                        d="M12 16a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2Z"
                                        />
                                    </svg>
                                    </span>
                                </button>
                                <template #items>
                                    <DropdownItemGroup>
                                        <DropdownItem as="button" @click="edit(element.uuid)">{{ $t('Edit') }}</DropdownItem>
                                        <DropdownItem as="button" @click="openAlert(element.uuid)">{{ $t('Delete') }}</DropdownItem>
                                    </DropdownItemGroup>
                                </template>
                                </Dropdown>
                            </td>
                        </tr>
                    </template>
                </draggable>
            </table>
        </div>
        <Pagination class="mt-3" :pagination="rows.meta"/>
    </div>

    <!-- Alert Modal Component-->
    <AlertModal 
        v-model="isOpenAlert" 
        @confirm="() => confirmAlert(deleteAction)"
        :label = "$t('Delete row')" 
        :description = "$t('Are you sure you want to delete this row? This action can not be undone')"
    />
</template>
  