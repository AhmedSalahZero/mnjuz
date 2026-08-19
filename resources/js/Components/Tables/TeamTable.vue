<script setup>
    import { ref, computed } from 'vue';
    import debounce from 'lodash/debounce';
    import { router, useForm, usePage } from "@inertiajs/vue3";
    import AlertModal from '@/Components/AlertModal.vue';
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

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
        filters: {
            type: Object
        },
        showTrashed: {
            type: Boolean,
            default: false,
        },
    });

    const user = computed(() => usePage().props.auth.user);
    const canManageTeam = computed(() => {
        const r = user.value?.organization_team_role ?? user.value?.teams?.[0]?.role;
        return ['owner', 'manager'].includes(r);
    });
    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const form = useForm({'test': null});

    const deleteAction = (key) => {
        form.delete('/team/' + key);
    }

    // الاستعادة تُعيد العضوية وحساب المستخدم معاً — العضوية وحدها لا تُمكّنه
    // من الدخول، إذ يُردّ بـ«حسابك غير مرتبط بأي منشأة».
    const restoreAction = (uuid) => {
        form.post('/team/' + uuid + '/restore', { preserveScroll: true });
    }

    // المبدّل يُبقي البحث الحالي كما هو.
    const toggleTrashed = () => {
        router.visit('/team', {
            method: 'get',
            data: { ...params.value, trashed: props.showTrashed ? undefined : 1 },
        });
    }

    const isLastRow = (index) => {
      return index === props.rows.data.length - 1;
    }

    const params = ref({
        search: props.filters?.search,
    });
    
    const isSearching = ref(false);
    const emit = defineEmits(['edit', 'delete']);

    function deleteItem(id) {
        emit('delete', id);
    }

    const clearSearch = () => {
        params.value.search = null;
        runSearch();
    }

    const search = debounce(() => {
        isSearching.value = true;
        runSearch();
    }, 1000);

    const runSearch = () => {
        router.visit('/team', {
            method: 'get',
            data: params.value,
        })
    }

    function edit(id, role, email) {
        emit('edit', { id: id, role: role, email: email });
    }
</script>
<template>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="md:bg-white flex items-center border border-primary md:border-none md:shadow-sm h-12 md:h-10 w-full md:w-80 rounded-[0.5rem] text-xl md:text-sm">
        <span class="pl-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
        </span>
        <input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full" :placeholder="$t('Search team')">
        <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
        </button>
        <span v-if="isSearching" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
        </span>
        </div>

        <!-- مبدّل المحذوفين: للمالك والمدير وحدهما، فالاستعادة مقصورة عليهما -->
        <button v-if="canManageTeam" type="button" @click="toggleTrashed"
            class="inline-flex items-center gap-2 whitespace-nowrap rounded-md border px-3 py-2 text-sm"
            :class="showTrashed ? 'border-primary bg-primary text-white' : 'border-slate-300 hover:bg-slate-50'">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
            </svg>
            {{ showTrashed ? $t('Show active members') : $t('Show deleted members') }}
        </button>
    </div>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                <TableHeaderRowItem :position="'first'">{{ $t('Name') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Email') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Role') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Status') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ showTrashed ? $t('Deleted at') : $t('Last updated') }}</TableHeaderRowItem>
                <TableHeaderRowItem v-if="canManageTeam" :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="!isLastRow(index) ? 'border-b' : ''">
                <TableBodyRowItem :position="'first'" class="capitalize">{{ item.user.first_name + ' ' + item.user.last_name }}</TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">{{ item.user.email }}</TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">{{ $t(item.role) }}</TableBodyRowItem>
                <TableBodyRowItem class="capitalize hidden sm:table-cell">
                    <!-- status عمود مستقلّ عن deleted_at ويبقى active بعد الحذف،
                         فعرضه «مفعل» في شاشة المحذوفين مضلّل. -->
                    <span v-if="showTrashed" class="py-1 rounded-[5px] text-xs px-3 bg-slate-100 text-slate-600">{{ $t('Deleted') }}</span>
                    <span v-else class="py-1 rounded-[5px] text-xs px-3 bg-[#ddebf7] text-slate-700">{{ $t(item.status) }}</span>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <span v-if="showTrashed">
                        {{ item.deleted_at || '—' }}
                        <span v-if="item.user_deleted"
                            class="ml-1 rounded-[5px] bg-red-50 px-2 py-0.5 text-xs text-red-700"
                            :title="$t('The account itself was deleted. Restoring brings back both the account and the membership.')">
                            {{ $t('Account deleted') }}
                        </span>
                        <span v-else
                            class="ml-1 rounded-[5px] bg-amber-50 px-2 py-0.5 text-xs text-amber-700"
                            :title="$t('Only the team membership was removed. The account itself is still active.')">
                            {{ $t('Removed from team') }}
                        </span>
                    </span>
                    <span v-else>{{ item.updated_at }}</span>
                </TableBodyRowItem>
                <TableBodyRowItem v-if="canManageTeam" :position="'last'">
                    <Dropdown v-if="item.role != 'owner'" :align="'right'" class="mt-2">
                        <button class="inline-flex w-full justify-center rounded-md text-sm font-medium text-black hover:bg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75">
                            <span class="hover:bg-[#F6F7F9] hover:rounded-full w-[fit-content] p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M12 16a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2Z"/>
                                </svg>
                            </span>
                        </button>
                        <template #items>
                            <DropdownItemGroup>
                                <template v-if="showTrashed">
                                    <DropdownItem as="button" @click="restoreAction(item.uuid)">{{ $t('Restore user') }}</DropdownItem>
                                </template>
                                <template v-else>
                                    <DropdownItem as="button" @click="edit(item.uuid, item.role, item.user.email)">{{ $t('Edit') }}</DropdownItem>
                                    <DropdownItem as="button" @click="openAlert(item.uuid)">{{ $t('Remove user') }}</DropdownItem>
                                </template>
                            </DropdownItemGroup>
                        </template>
                    </Dropdown>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>

    <!-- Alert Modal Component-->
    <!-- رسالة خاصّة بالفريق: المفتاح العام يقول «لا يمكن التراجع» وهو صحيح في
         الجداول الأخرى، أمّا هنا فالحذف ناعم والاستعادة متاحة من مبدّل المحذوفين. -->
    <AlertModal 
        v-model="isOpenAlert" 
        @confirm="() => confirmAlert(deleteAction)"
        :label = "$t('Remove user')" 
        :description = "$t('Are you sure you want to remove this member? They will lose access to this business immediately. You can restore them later from “Show deleted members”.')"
    />
</template>
  