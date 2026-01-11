<template>
    <AppLayout>
        <div class="flex justify-between">
            <div>
                <h2 class="text-xl mb-1">{{ $t('Frontend pages') }} | {{ $t('Edit') }}</h2>
                <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                    <span class="ml-1 mt-1">{{ $t('Configure pages') }}</span>
                </p>
            </div>
            <div class="flex items-center space-x-2">
                <Link href="/admin/settings/pages" class="hover:bg-white rounded-full p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="black" d="M19 11H7.14l3.63-4.36a1 1 0 1 0-1.54-1.28l-5 6a1.19 1.19 0 0 0-.09.15c0 .05 0 .08-.07.13A1 1 0 0 0 4 12a1 1 0 0 0 .07.36c0 .05 0 .08.07.13a1.19 1.19 0 0 0 .09.15l5 6A1 1 0 0 0 10 19a1 1 0 0 0 .64-.23a1 1 0 0 0 .13-1.41L7.14 13H19a1 1 0 0 0 0-2"/></svg>
                </Link>
                <button @click="deletePage()" class="hover:bg-white rounded-full p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="red" d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6zM8 9h8v10H8zm7.5-5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                </button>
                <button @click="submitForm()" class="rounded-md bg-indigo-600 px-3 py-1 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Save') }}</button>
            </div>
        </div>
        <form @submit.prevent="submitForm()">
            <FormInput v-model="form.name" :name="''" :error="form.errors.name" :type="'text'" :class="'sm:col-span-6'"/>
            <div class="text-sm mb-4 mt-3 rounded-md bg-gray-200 px-1 w-fit">Slug: /{{ formattedName }}</div>
            <div class="bg-white">
                <QuillEditor v-model:content="form.content" toolbar="essential" contentType="html" theme="snow" />
            </div>
        </form>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "../Layout/App.vue";
    import { ref, computed } from 'vue'
    import { Link, useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import { QuillEditor } from '@vueup/vue-quill'
    import '@vueup/vue-quill/dist/vue-quill.snow.css';

    const props = defineProps({ page: Object });

    const form = useForm({
        _method: 'put',
        name: props.page.name,
        content: props.page.content,
    })

    const submitForm = async () => {
        const url = window.location.pathname;

        form.put(url, {
            preserveScroll: true,
        });
    };

    const deletePage = async () => {
        const url = window.location.pathname;

        form.delete(url, {
            onBefore: () => confirm('Are you sure you want to delete this page?'),
            preserveScroll: true,
        });
    };

    const formattedName = computed(() => {
        return form.name.trim().toLowerCase().replace(/\s+/g, '-')
    })
</script>