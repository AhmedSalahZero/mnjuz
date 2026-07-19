<script setup>
    import AppLayout from '../Layout/App.vue';
    import { Link } from '@inertiajs/vue3';

    defineProps({
        title: String,
        message: String,
        payment: {
            type: Object,
            default: null,
        },
    });
</script>

<template>
    <AppLayout>
        <div class="min-h-[70vh] flex items-center justify-center px-4">
            <div class="w-full max-w-lg bg-white rounded-2xl shadow-sm border border-slate-100 p-8 text-center">
                <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19L21 7l-1.41-1.41z"/>
                    </svg>
                </div>

                <h1 class="text-2xl font-semibold mb-2">{{ title }}</h1>
                <p class="text-gray-600 mb-6">{{ message }}</p>

                <div v-if="payment" class="rounded-xl bg-slate-50 p-4 text-sm text-left mb-6 space-y-2">
                    <div v-if="payment.amount" class="flex justify-between">
                        <span class="text-gray-500">{{ $t('Amount') }}</span>
                        <span>{{ payment.amount }} {{ payment.currency || 'SAR' }}</span>
                    </div>
                    <div v-if="payment.payment_method" class="flex justify-between">
                        <span class="text-gray-500">{{ $t('Payment method') }}</span>
                        <span>{{ payment.payment_method }}</span>
                    </div>
                    <div v-if="payment.invoice_id" class="flex justify-between">
                        <span class="text-gray-500">{{ $t('Invoice ID') }}</span>
                        <span>{{ payment.invoice_id }}</span>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <Link
                        href="/billing"
                        class="inline-flex justify-center rounded-md bg-primary px-5 py-2.5 text-sm text-white hover:opacity-90"
                    >
                        {{ $t('Go to billing') }}
                    </Link>
                    <Link
                        href="/subscription"
                        class="inline-flex justify-center rounded-md border border-slate-200 px-5 py-2.5 text-sm text-gray-700 hover:bg-slate-50"
                    >
                        {{ $t('View subscription') }}
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
