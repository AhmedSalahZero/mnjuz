<template>
    <AppLayout>
        <div class="flex justify-between">
            <div>
                <h2 class="text-xl mb-1">{{ $t('Template') }}: {{ props.template.name }}</h2>
                <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span class="ml-1 mt-1">{{ $t('Configure email templates') }}</span>
                </p>
            </div>
            <div class="flex items-center space-x-2">
                <button @click="submitForm()" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Save') }}</button>
                <Link href="/admin/settings/email-templates" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
            </div>
        </div>
        <form @submit.prevent="submitForm()">
            <FormInput v-model="form.subject" :name="''" :error="form.errors.subject" :type="'text'" :class="'sm:col-span-6 mb-4'"/>
            <QuillEditor v-model:content="form.body" toolbar="essential" contentType="html" theme="snow" />
        </form>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "../Layout/App.vue";
    import { Link, useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import { QuillEditor } from '@vueup/vue-quill'
    import '@vueup/vue-quill/dist/vue-quill.snow.css';

    const props = defineProps({ template: Object });

    const form = useForm({
        _method: 'put',
        subject: props.template.subject,
        body: props.template.body,
    })

    const submitForm = async () => {
        const url = window.location.pathname;

        form.put(url, {
            preserveScroll: true,
        });
    };
</script>