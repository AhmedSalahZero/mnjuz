<script setup>
    import { Link, usePage } from "@inertiajs/vue3";
    import { computed } from "vue";
    import 'vue3-toastify/dist/index.css';
    import Table from '@/Components/Table.vue';
    import TableHeader from '@/Components/TableHeader.vue';
    import TableHeaderRow from '@/Components/TableHeaderRow.vue';
    import TableHeaderRowItem from '@/Components/TableHeaderRowItem.vue';
    import TableBody from '@/Components/TableBody.vue';
    import TableBodyRow from '@/Components/TableBodyRow.vue';
    import TableBodyRowItem from '@/Components/TableBodyRowItem.vue';

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
    });

    const user = computed(() => usePage().props.auth.user);

    const ticketUrl = (uuid) => {
        return usePage().props.auth.user.role != 'user' ? '/admin/support/' + uuid : '/support/' + uuid;
    }
</script>
<template>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                <TableHeaderRowItem :position="'first'">{{ $t('Ref') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Subject') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Priority') }}</TableHeaderRowItem>
                <TableHeaderRowItem v-if="user.role != 'user'">{{ $t('User') }}</TableHeaderRowItem>
                <TableHeaderRowItem v-if="user.role != 'user'">{{ $t('Assigned to') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Status') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Last updated') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index">
                <TableBodyRowItem :position="'first'" class="capitalize">
                    <Link :href="ticketUrl(item.uuid)">
                        {{ item.reference }}
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <Link :href="ticketUrl(item.uuid)">
                        <div class="py-1 px-2 bg-gray-50 rounded-[5px] border border-dashed w-[20em] truncate text-xs capitalize">
                            {{ item.subject }}
                        </div>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <Link :href="ticketUrl(item.uuid)">
                        <span class="text-left bg-slate-100 py-2 px-3 rounded-xl text-[11px]">{{ item.priority ?? $t('Not set') }}</span>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem v-if="user.role != 'user'" class="hidden sm:table-cell">
                    <Link :href="ticketUrl(item.uuid)">
                        <span class="text-left">{{ item.user.first_name + ' ' + item.user.last_name }}</span>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem v-if="user.role != 'user'" class="hidden sm:table-cell">
                    <Link :href="ticketUrl(item.uuid)">
                        <span class="text-left">{{ item.agent ? item.agent?.first_name + ' ' + item.agent?.last_name : $t('Not set') }}</span>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem class="capitalize">
                    <Link :href="ticketUrl(item.uuid)">
                        <span class="py-1 rounded-[5px] text-xs px-3 bg-[#ddebf7] text-slate-700">{{ $t(item.status) }}</span>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <Link :href="ticketUrl(item.uuid)">
                        {{ item.updated_at }}
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem :position="'last'">
                    <div class="flex items-center mt-3">
                        <Link :href="ticketUrl(item.uuid)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M9.97 7.47a.75.75 0 0 1 1.06 0l4 4a.75.75 0 0 1 0 1.06l-4 4a.75.75 0 1 1-1.06-1.06L13.44 12L9.97 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
                        </Link>
                    </div>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>
</template>
  