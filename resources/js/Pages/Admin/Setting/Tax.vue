<template>
    <AppLayout>
        <div class="flex justify-between">
            <div>
                <h2 class="text-xl mb-1">{{ $t('Tax rates') }}</h2>
                <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span class="ml-1 mt-1">{{ $t('Configure tax rates') }}</span>
                </p>
            </div>
            <div>
                <button @click="openModal()" type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add tax rate') }}</button>
            </div>
        </div>

        <!-- Tax Calculation Method-->
        <div class="bg-white rounded-md mb-4 py-4 px-4 flex grid grid-cols-2">
            <div>
                <h3>{{ $t('Tax calculation method') }}</h3>
                <span class="text-xs">{{ $t('Choose whether tax should be calculated inclusively or exclusively') }}</span>
            </div>
            <div>
                <FormSelect v-model="form1.is_tax_inclusive" :name="''" :type="'text'"  :options="taxCalculationOptions" :error="form1.errors.is_tax_inclusive" :class="'sm:col-span-3'"/>
                <button @click="submitForm1()" type="button" class="rounded-md bg-gray-600 mt-4 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Update') }}</button>
            </div>
        </div>

        <!-- Table Component-->
        <TaxTable :rows="props.rows" @edit="openModal" @delete="openAlert" />

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
    import AppLayout from "./Layout/App.vue";
    import axios from "axios";
    import { ref } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import TaxTable from '@/Components/Tables/TaxTable.vue';
    import FormModal from '@/Components/FormModalModified.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({ rows: Object, config: Object });
    const isOpenFormModal = ref(false);
    const label = ref('Add Tax Rate');
    const formUrl = ref('/admin/tax-rates');
    const formMethod = ref('post');

    const form = {
        name: null,
        type: null,
        percentage: null,
        status: null
    };

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const form1 = useForm({
        is_tax_inclusive: getValueByKey('is_tax_inclusive'),
    });

    const openModal = (key, formData = {}) => {
        label.value = trans('Add tax rate');
        formUrl.value = '/admin/tax-rates';
        formMethod.value = 'post';

        if (key != null) {
            label.value = trans('Edit tax rate');
            formUrl.value = '/admin/tax-rates/' + key;
            formMethod.value = 'put';
            getRow();
        } else {
            form.name = null;
            form.percentage = null;
            form.status = null;
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

    const taxCalculationOptions = ref([
        { value: '1', label: trans('Inclusive') },
        { value: '0', label: trans('Exclusive') }
    ]);

    const formInputs = [
        {
            inputType: 'FormInput',
            name: 'name',
            label: trans('name'),
            type: 'text',
            className: 'sm:col-span-6',
        },
        {
            inputType: 'FormInput',
            name: 'percentage',
            label: trans('Percentage'),
            type: 'number',
            className: 'sm:col-span-3',
        },
        {
            inputType: 'FormSelect',
            name: 'status',
            label: trans('status'),
            options: [
            { value: 'active', label: trans('Active') },
            { value: 'inactive', label: trans('Inactive') },
            ],
            className: 'sm:col-span-3',
        },
    ];

    const submitForm1 = async () => {
        form1.put('/admin/settings', {
            preserveScroll: true,
        })
    };
</script>