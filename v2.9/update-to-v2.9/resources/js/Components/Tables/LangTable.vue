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

    const props = defineProps(['rows', 'defaultLanguage']);

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
        form.delete('/admin/languages/' + key);
    }

    const isLastRow = (index) => {
        return index === props.rows.data.length - 1;
    }

    const capitalizeFirstLetter = (str) => {
        return str.charAt(0).toUpperCase() + str.slice(1);
    };
</script>
<template>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                <TableHeaderRowItem :position="'first'">{{ $t('Name') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Code') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Status') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Is RTL') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="!isLastRow(index) ? 'border-b' : ''">
                <TableBodyRowItem :position="'first'">{{ item.name }}</TableBodyRowItem>
                <TableBodyRowItem>{{ item.code }}</TableBodyRowItem>
                <TableBodyRowItem>{{ $t(capitalizeFirstLetter(item.status)) }}</TableBodyRowItem>
                <TableBodyRowItem>{{ item.is_rtl == 1 ? $t('Yes') : $t('No')}}</TableBodyRowItem>
                <TableBodyRowItem>
                    <span v-if="props.defaultLanguage == item.code" class="bg-slate-50 p-1 rounded-md text-[12px]">Default Language</span>
                </TableBodyRowItem>
                <TableBodyRowItem :position="'last'">
                    <Dropdown :align="'right'" class="mt-2">
                    <button class="inline-flex w-full justify-center rounded-md text-sm font-medium text-black hover:bg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75">
                        <span class="hover:bg-[#F6F7F9] hover:rounded-full w-[fit-content] p-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 16a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2Z"/></svg>
                        </span>
                    </button>
                    <template #items>
                        <DropdownItemGroup>
                            <DropdownItem v-if="props.defaultLanguage != item.code" :href="'/admin/languages/' + item.code + '/default'">{{ $t('Set as default') }}</DropdownItem>
                            <DropdownItem :href="'/admin/languages/' + item.code + '/translations'">{{ $t('Translate') }}</DropdownItem>
                            <DropdownItem as="button" @click="edit(item.id)">{{ $t('Edit') }}</DropdownItem>
                            <DropdownItem as="button" @click="openAlert(item.id)" v-if="item.code != 'en'">{{ $t('Delete') }}</DropdownItem>
                        </DropdownItemGroup>
                    </template>
                    </Dropdown>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>

    <!-- Alert Modal Component-->
    <AlertModal 
        v-model="isOpenAlert" 
        @confirm="() => confirmAlert(deleteAction)"
        :label = "$t('Delete row')" 
        :description = "$t('Are you sure you want to delete this row? This action can not be undone')"
    />
</template>
  