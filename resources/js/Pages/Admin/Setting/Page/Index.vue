<template>
    <AppLayout>
        <div class="flex justify-between">
            <div>
                <h2 class="text-xl mb-1">{{ $t('Frontend Pages') }}</h2>
                <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span class="ml-1 mt-1">{{ $t('Configure content in frontend pages') }}</span>
                </p>
            </div>
            <div>
                <button @click="openModal()" type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add page') }}</button>
            </div>
        </div>
        <PageTable :rows="props.rows" :filters="props.filters"/>

        <!-- Form Modal Component-->
        <FormModal 
            v-model="isOpenFormModal" 
            :label="label" 
            :url="formUrl" 
            :form="form" 
            :formInputs="formInputs"
            :formMethod="formMethod"
            @closeModal="isOpenFormModal = false"
        />
    </AppLayout>
</template>
<script setup>
    import AppLayout from "../Layout/App.vue";
    import PageTable from '@/Components/Tables/PageTable.vue';
    import { ref } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import FormModal from '@/Components/FormModalModified.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({ rows: Object, filters: Object });
    const isOpenFormModal = ref(false);
    const label = ref('Add Page');
    const formUrl = ref('/admin/settings/pages');
    const formMethod = ref('post');

    const form = useForm({
        name: null
    });

    const formInputs = [
        {
            inputType: 'FormInput',
            name: 'name',
            label: trans('name'),
            type: 'text',
            className: 'sm:col-span-6',
        }
    ];

    const openModal = (key, formData = {}) => {
        form.name = null;
        isOpenFormModal.value = true;
    }
</script>