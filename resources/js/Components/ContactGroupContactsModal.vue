<script setup>
    import axios from 'axios';
    import { computed, onBeforeUnmount, ref, watch } from 'vue';
    import { Link } from '@inertiajs/vue3';
    import Modal from '@/Components/Modal.vue';

    // جهات اتصال المجموعة صفحة صفحة: أكبر مجموعة على الإنتاج فيها 18 ألف جهة.
    const props = defineProps({
        isOpen: { type: Boolean, default: false },
        groupUuid: { type: String, required: true },
        label: { type: String, default: '' },
    });

    const emit = defineEmits(['close']);

    const SEARCH_DELAY_MS = 300;

    const contacts = ref([]);
    const page = ref(1);
    const lastPage = ref(1);
    const total = ref(0);
    const search = ref('');
    const loading = ref(false);
    const failed = ref(false);
    // «إعادة المحاولة» تطلب الصفحة التي فشلت، لا آخر صفحة نجحت.
    const attemptedPage = ref(1);

    // كل طلب يأخذ رقماً، ولا يُعرض إلا ردّ آخر طلب: ردّ بحث قديم قد يصل بعد الأحدث.
    let latestRequest = 0;
    let searchTimer = null;

    const hasSearch = computed(() => search.value.trim() !== '');

    const load = async (targetPage = 1) => {
        const requestId = ++latestRequest;
        attemptedPage.value = targetPage;
        loading.value = true;
        failed.value = false;

        try {
            const params = { page: targetPage };
            if (hasSearch.value) {
                params.search = search.value.trim();
            }

            const { data } = await axios.get(`/contact-groups/${props.groupUuid}/contacts`, { params });
            if (requestId !== latestRequest) return;

            contacts.value = data.data;
            page.value = data.current_page;
            lastPage.value = data.last_page;
            total.value = data.total;
        } catch (error) {
            if (requestId !== latestRequest) return;
            failed.value = true;
        } finally {
            if (requestId === latestRequest) {
                loading.value = false;
            }
        }
    };

    const clearSearchTimer = () => {
        if (searchTimer !== null) {
            clearTimeout(searchTimer);
            searchTimer = null;
        }
    };

    // يُهمل أي ردّ أو بحث معلّق، فلا يُكتب في نافذة أُغلقت.
    const cancelPending = () => {
        clearSearchTimer();
        latestRequest++;
        loading.value = false;
    };

    const reset = () => {
        cancelPending();
        contacts.value = [];
        page.value = 1;
        lastPage.value = 1;
        total.value = 0;
        search.value = '';
        failed.value = false;
    };

    const onSearchInput = () => {
        clearSearchTimer();
        searchTimer = setTimeout(() => {
            searchTimer = null;
            load(1);
        }, SEARCH_DELAY_MS);
    };

    const goToPage = (target) => {
        if (target < 1 || target > lastPage.value || loading.value) return;
        load(target);
    };

    const close = () => emit('close');

    // عند الإغلاق لا تُفرَّغ القائمة، كي لا تظهر «فارغة» أثناء حركة الإغلاق.
    watch(() => [props.isOpen, props.groupUuid], ([isOpen]) => {
        if (!isOpen) {
            cancelPending();
            return;
        }
        reset();
        load(1);
    }, { immediate: true });

    onBeforeUnmount(clearSearchTimer);
</script>
<template>
    <Modal :isOpen="isOpen" :label="label || $t('View contacts')" :closeBtn="true" maxWidth="max-w-lg" @close="close">
        <div class="pt-4 text-start" data-test="group-contacts">
            <input
                v-model="search"
                @input="onSearchInput"
                type="search"
                :placeholder="$t('Search name or phone or email')"
                :aria-label="$t('Search name or phone or email')"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none"
                data-test="group-contacts-search"
            >

            <div class="mt-3 min-h-[12rem]">
                <div v-if="failed" class="py-10 text-center text-sm text-slate-500" data-test="group-contacts-error">
                    <p>{{ $t('Something went wrong') }}</p>
                    <button type="button" @click="load(attemptedPage)" class="mt-3 rounded-md bg-gray-200 px-4 py-2 text-sm" data-test="group-contacts-retry">
                        {{ $t('Retry') }}
                    </button>
                </div>

                <div v-else-if="loading && contacts.length === 0" class="py-10 text-center text-sm text-slate-500" data-test="group-contacts-loading">
                    {{ $t('Loading...') }}
                </div>

                <div v-else-if="contacts.length === 0" class="py-10 text-center text-sm text-slate-500" data-test="group-contacts-empty">
                    {{ hasSearch ? $t('No matching contacts') : $t('No contacts in this group') }}
                </div>

                <ul v-else class="divide-y divide-gray-100" :class="{ 'opacity-60': loading }" data-test="group-contacts-list">
                    <li v-for="contact in contacts" :key="contact.uuid">
                        <Link :href="'/contacts/' + contact.uuid" class="block rounded-md px-2 py-2 hover:bg-slate-50" data-test="group-contacts-item">
                            <span class="flex flex-wrap items-center gap-1 text-sm text-gray-900">
                                <span>{{ contact.full_name || '—' }}</span>
                                <span v-if="contact.channel !== 'whatsapp'" class="rounded bg-slate-100 px-1.5 text-[11px] text-slate-600" data-test="group-contacts-channel">{{ $t(contact.channel_label) }}</span>
                                <span v-if="contact.opted_out" class="rounded bg-amber-50 px-1.5 text-[11px] text-amber-700" data-test="group-contacts-opted-out">{{ $t('Opted out of marketing') }}</span>
                            </span>
                            <span class="block text-xs text-slate-500"><span dir="ltr">{{ contact.phone || contact.email || '—' }}</span></span>
                        </Link>
                    </li>
                </ul>
            </div>

            <div v-if="!failed && total > 0" class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3 text-sm">
                <button type="button" @click="goToPage(page - 1)" :disabled="page <= 1 || loading"
                    class="rounded-md bg-gray-200 px-3 py-1 disabled:cursor-not-allowed disabled:opacity-50" data-test="group-contacts-previous">
                    {{ $t('Previous') }}
                </button>
                <span class="text-slate-500" data-test="group-contacts-summary">{{ page }} / {{ lastPage }} · {{ total }}</span>
                <button type="button" @click="goToPage(page + 1)" :disabled="page >= lastPage || loading"
                    class="rounded-md bg-gray-200 px-3 py-1 disabled:cursor-not-allowed disabled:opacity-50" data-test="group-contacts-next">
                    {{ $t('Next') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
