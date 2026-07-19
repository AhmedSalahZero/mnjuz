<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000]">
            <h1 class="text-xl mb-4">{{ $t('Developer Tools') }}</h1>
            <p class="text-sm text-gray-600 mb-6">{{ $t('API documentation and integration resources') }}</p>
            <a :href="apiDocsUrl" target="_blank" rel="noopener noreferrer"
                class="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm text-white shadow-sm hover:bg-indigo-500">
                {{ $t('Open API documentation') }}
            </a>
        </div>
    </AppLayout>
</template>
<script setup>
import AppLayout from '../Layout/App.vue';

defineProps({
    title: String,
    apiDocsUrl: String,
});
</script>
