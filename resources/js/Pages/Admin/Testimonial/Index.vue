<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Reviews') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('View, add, edit or delete reviews') }}</span>
                    </p>
                </div>
                <div>
                    <button @click="openModal()" type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add review') }}</button>
                </div>
            </div>

            <TestimonialTable :rows="props.rows" :filters="props.filters" @edit="openModal" @delete="openAlert" />

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
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import axios from "axios";
    import { ref } from 'vue';
    import TestimonialTable from '@/Components/Tables/TestimonialTable.vue';
    import FormModal from '@/Components/FormModalModified.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({ title: String, rows: Object, filters: Object });
    const isOpenFormModal = ref(false);
    const label = ref(trans('Add review'));
    const formUrl = ref('/admin/testimonials');
    const formMethod = ref('post');

    const form = {
        name: null,
        position: null,
        rating: null,
        status: null,
        review: null,
    };

    const openModal = (key, formData = {}) => {
        label.value = trans('Add review');
        formUrl.value = '/admin/testimonials';
        formMethod.value = 'post';

        if (key != null) {
            label.value = trans('Edit review');
            formUrl.value = '/admin/testimonials/' + key;
            formMethod.value = 'put';
            getRow();
        } else {
            form.name = null;
            form.position = null;
            form.review = null;
            form.rating = null;
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
            name: 'position',
            label: trans('Position'),
            type: 'text',
            className: 'sm:col-span-6',
        },
        {
            inputType: 'FormSelect',
            name: 'rating',
            label: trans('Rating'),
            options: [
                { value: 1, label: '1' },
                { value: 2, label: '2' },
                { value: 3, label: '3' },
                { value: 4, label: '4' },
                { value: 5, label: '5' },
            ],
            className: 'sm:col-span-3',
        },
        {
            inputType: 'FormSelect',
            name: 'status',
            label: trans('status'),
            options: [
                { value: 0, label: 'Hide' },
                { value: 1, label: 'Display' },
            ],
            className: 'sm:col-span-3',
        },
        {
            inputType: 'FormTextArea',
            name: 'review',
            label: trans('Review'),
            type: 'text',
            className: 'sm:col-span-6',
            textAreaRows: 4,
        },
    ];
</script>