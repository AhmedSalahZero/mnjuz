<template>
    <div :class="rtlClass">
        <div class="flex h-screen justify-center">
            <div class="flex justify-center">
                <div class="w-[20em] mt-40">
                    <div class="flex justify-center mb-5">
                        <Link href="/">
                            <img class="max-w-[180px]" v-if="props.companyConfig.logo" :src="'/media/' + props.companyConfig.logo" :alt="props.companyConfig.company_name">
                            <h4 v-else class="text-2xl mb-2">{{ props.companyConfig.company_name }}</h4>
                        </Link>
                    </div>
                    <h1 class="text-2xl text-center">{{ $t('Verify your email') }}</h1>
                    <div class="py-2 text-center bg-slate-50 rounded-md my-4 px-4">{{ $t('We\'ve sent you a verification link to your email, click on it to continue') }}</div>
                    <div class="text-center text-sm text-slate-500 flex items-center justify-center">
                    <span v-if="isSending" class="flex items-center gap-x-1">
                        Processing <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><g fill="none" stroke="black" stroke-linecap="round" stroke-width="2"><path stroke-dasharray="60" stroke-dashoffset="60" stroke-opacity="0.3" d="M12 3C16.9706 3 21 7.02944 21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3Z"><animate fill="freeze" attributeName="stroke-dashoffset" dur="1.3s" values="60;0"/></path><path stroke-dasharray="15" stroke-dashoffset="15" d="M12 3C16.9706 3 21 7.02944 21 12"><animate fill="freeze" attributeName="stroke-dashoffset" dur="0.3s" values="15;0"/><animateTransform attributeName="transform" dur="1.5s" repeatCount="indefinite" type="rotate" values="0 12 12;360 12 12"/></path></g></svg>
                    </span>
                    <button v-else @click="resendEmail" 
                        :disabled="isSending"
                        class="text-sm text-primary-600 dark:text-primary-500 ml-1">
                        {{ $t('Email not received?') }} 
                        <span class="border-b hover:border-none hover:underline">
                            {{ $t('Resend email') }}
                        </span>
                    </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
    import { useRtl } from '@/Composables/useRtl';
import { Link, router, usePage } from "@inertiajs/vue3";
import { defineProps, ref } from 'vue';
import { toast } from 'vue3-toastify';
import 'vue3-toastify/dist/index.css';

    const { rtlClass, isRtl } = useRtl();

    const props = defineProps(['flash', 'config', 'companyConfig']);
    const isSending = ref(false);

    const resendEmail = () => {
        isSending.value = true;
        router.visit('/email/verification-notification', {
            method: 'post',
            data: {},
            onFinish: () => {
                isSending.value = false;
                showToast(usePage().props.flash.status.message);
            },
        });
    };

    const showToast = (message) => {
        toast(message, {
            autoClose: 3000,
        });
    };
</script>
