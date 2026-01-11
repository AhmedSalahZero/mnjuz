<template>
    <AppLayout>
        <div class="flex justify-between">
            <div>
                <h2 class="text-xl mb-1">{{ $t('Languages & translations') }}</h2>
                <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span class="ml-1 mt-1">{{ $t('Add, edit & translate languages') }}</span>
                </p>
            </div>
            <div>
                <button @click="openModal()" type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add language') }}</button>
            </div>
        </div>

        <!-- Table Component-->
        <LangTable :rows="props.rows" :defaultLanguage="props.default_language" @edit="openModal" @delete="openAlert" />

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
    import AppLayout from "./../Layout/App.vue";
    import axios from "axios";
    import { ref } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import LangTable from '@/Components/Tables/LangTable.vue';
    import FormModal from '@/Components/FormModalModified.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps(['rows', 'config', 'default_language']);
    const isOpenFormModal = ref(false);
    const label = ref('Add Tax Rate');
    const formUrl = ref('/admin/tax-rates');
    const formMethod = ref('post');

    const form = {
        name: null,
        code: null,
        status: null,
        is_rtl: false,
    };

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const openModal = (key, formData = {}) => {
        label.value = trans('Add language');
        formUrl.value = '/admin/languages';
        formMethod.value = 'post';

        if (key != null) {
            label.value = trans('Edit language');
            formUrl.value = '/admin/languages/' + key;
            formMethod.value = 'put';
            getRow();
        } else {
            form.name = null;
            form.code = null;
            form.status = null;
            form.is_rtl = false;
            isOpenFormModal.value = true;
        }
    }

    function getRow() {
        axios.get(formUrl.value).then((response) => {
            const { data } = response;
            for (const key in data.item) {
                if (form.hasOwnProperty(key)) {
                    form[key] = data.item[key];
                }
            }
            isOpenFormModal.value = true;
        })
        .catch((error) => {
            // console.error('Error:', error);
        });
    }

    const formInputs = [
        {
            inputType: 'FormInput',
            name: 'name',
            label: trans('Name'),
            type: 'text',
            className: 'sm:col-span-6',
        },
        {
            inputType: 'FormInput',
            name: 'code',
            label: trans('Code'),
            type: 'text',
            className: 'sm:col-span-3',
        },
        {
            inputType: 'FormSelect',
            name: 'status',
            label: trans('Status'),
            options: [
                { value: 'active', label: trans('Active') },
                { value: 'inactive', label: trans('Inactive') },
            ],
            className: 'sm:col-span-3',
        },
        {
            inputType: 'FormSelect',
            name: 'is_rtl',
            label: trans('Right to Left'),
            options: [
                { value: false, label: trans('False') },
                { value: true, label: trans('True') },
            ],
            className: 'sm:col-span-6',
        }
    ];
</script>