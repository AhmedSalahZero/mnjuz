<script setup>
    import AppLayout from '../Layout/App.vue';
    import { Link } from '@inertiajs/vue3';

    defineProps({
        title: String,
        message: String,
        paymentId: {
            type: String,
            default: null,
        },
    });
</script>

<template>
    <AppLayout>
        <div class="min-h-[70vh] flex items-center justify-center px-4">
            <div class="w-full max-w-lg bg-white rounded-2xl shadow-sm border border-slate-100 p-8 text-center">
                <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10s10-4.47 10-10S17.53 2 12 2m5 13.59L15.59 17L12 13.41L8.41 17L7 15.59L10.59 12L7 8.41L8.41 7L12 10.59L15.59 7L17 8.41L13.41 12z"/>
                    </svg>
                </div>

                <h1 class="text-2xl font-semibold mb-2">{{ title }}</h1>
                <p class="text-gray-600 mb-6">{{ message }}</p>

                <p v-if="paymentId" class="text-xs text-gray-400 mb-6">
                    {{ $t('Reference') }}: {{ paymentId }}
                </p>

                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <Link
                        href="/billing"
                        class="inline-flex justify-center rounded-md bg-primary px-5 py-2.5 text-sm text-white hover:opacity-90"
                    >
                        {{ $t('Back to billing') }}
                    </Link>
                    <Link
                        href="/subscription"
                        class="inline-flex justify-center rounded-md border border-slate-200 px-5 py-2.5 text-sm text-gray-700 hover:bg-slate-50"
                    >
                        {{ $t('Try again') }}
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
