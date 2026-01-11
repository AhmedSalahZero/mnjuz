<template>
    <AppLayout>
        <div class="p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="flex justify-between">
                <div>
                    <h1 v-if="props.faq === null" class="text-xl mb-1">{{ $t('Create FAQ') }}</h1>
                    <h1 v-else class="text-xl mb-1">{{ $t('Update FAQ') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span v-if="props.faq === null" class="ml-1 mt-1">{{ $t('Create FAQ') }}</span>
                        <span v-else class="ml-1 mt-1">{{ $t('Update FAQ') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/faqs" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <form @submit.prevent="submitForm()" class="bg-white border py-5 px-5 rounded-[0.5rem]">
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Question') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormTextArea v-model="form.question" :name="''" :error="form.errors.question" :type="'text'" :textAreaRows="4" :class="'sm:col-span-6'"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Answer') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormTextArea v-model="form.answer" :name="''" :error="form.errors.answer" :type="'text'" :textAreaRows="4" :class="'sm:col-span-6'"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Status') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-1">
                            <FormSelect v-model="form.status" :options="statusOptions" :error="form.errors.status" :name="''" :class="'sm:col-span-3'" :placeholder="$t('Select status')"/>
                        </div>
                    </div>
                </div>
                <div class="py-6">
                    <button type="submit" class="float-right flex items-center space-x-4 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-slate-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { ref } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import FormTextArea from '@/Components/FormTextArea.vue';
    import FormSelect from '@/Components/FormSelect.vue';

    const props = defineProps({ title: String, faq: Object });

    const getDetail = (value, key) => {
        if(value){
            const item = JSON.parse(value);
            return item?.[key] ?? null;
        } else {
            return null;
        }
    }

    const form = useForm({
        question: props.faq?.question,
        answer: props.faq?.answer,
        status: props.faq?.status,
    })

    const statusOptions = ref([
        { value: 0, label: 'Hide' },
        { value: 1, label: 'Display' }
    ]);

    const submitForm = async () => {
        const url = props.faq ? window.location.pathname : '/admin/faqs';

        form[props.faq ? 'put' : 'post'](url, {
            preserveScroll: true,
        });
    };
</script>