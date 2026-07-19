<script setup>
import { ref, watchEffect } from 'vue';
import { router } from '@inertiajs/vue3';
import FormModal from '@/Components/FormModal.vue';
import { useTrans } from '@/Composables/useTrans';

const trans = useTrans();

const props = defineProps(['category']);
const category = ref(props.category);

watchEffect(() => {
    category.value = props.category;
});

const isOpenFormModal = ref(false);
const form = ref({
    name: category.value?.name ?? '',
    background_color: category.value?.background_color ?? '#22c55e',
    text_color: category.value?.text_color ?? '#ffffff',
});

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
        name: 'background_color',
        label: trans('Background color'),
        type: 'color',
        className: 'sm:col-span-3',
    },
    {
        inputType: 'FormInput',
        name: 'text_color',
        label: trans('Text color'),
        type: 'color',
        className: 'sm:col-span-3',
    },
];

const deleteRow = async () => {
    router.visit('/contact-categories', {
        method: 'delete',
        data: { 'uuids': [category.value?.uuid] },
        preserveState: true,
    });
};

const openModal = () => {
    isOpenFormModal.value = true;
    form.value.name = category.value?.name ?? '';
    form.value.background_color = category.value?.background_color ?? '#22c55e';
    form.value.text_color = category.value?.text_color ?? '#ffffff';
};
</script>
<template>
    <div>
        <div class="pt-20">
            <div class="flex justify-center space-x-8 items-center pb-6 pr-20 border-gray-300 border-b">
                <div>
                    <div class="rounded-full p-1 bg-white">
                        <div
                            :style="{ backgroundColor: category?.background_color ?? '#22c55e', color: category?.text_color ?? '#ffffff' }"
                            class="rounded-full text-3xl flex justify-center items-center h-24 w-24 capitalize"
                        >{{ category?.name?.substring(0, 1) }}</div>
                    </div>
                </div>
                <div>
                    <h1 class="text-3xl">{{ category?.name }}</h1>
                    <div class="flex space-x-3 mt-4">
                        <button class="bg-gray-200 py-2 px-4 h-9 rounded-md flex items-center" @click="openModal">
                            <span class="text-[14px]">{{ $t('Edit') }}</span>
                        </button>
                        <button @click="deleteRow()" class="bg-gray-200 py-2 px-4 h-9 rounded-md flex items-center">
                            <span class="text-[14px]">{{ $t('Delete') }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="pr-20 border-gray-300 border-b py-4">
                <div class="grid grid-cols-2 space-x-8 text-[14px]">
                    <div class="text-right text-slate-500 pb-2">
                        <span>{{ $t('Category name') }}</span>
                    </div>
                    <div>
                        <span>{{ category?.name }}</span>
                    </div>
                    <div class="text-right text-slate-500 pb-2">
                        <span>{{ $t('Total contacts') }}</span>
                    </div>
                    <div>
                        <span class="p-1 bg-gray-50 text-xs rounded-lg text-gray-600">{{ category?.contact_count }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <FormModal
        v-model="isOpenFormModal"
        :label="$t('Edit category')"
        :url="'/contact-categories/' + category?.uuid"
        :form="form"
        :formInputs="formInputs"
    />
</template>
