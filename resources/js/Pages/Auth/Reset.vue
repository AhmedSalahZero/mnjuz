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
                    <h1 class="text-2xl text-center">{{ $t('Reset your password') }}</h1>
                    <div class="text-center text-sm text-slate-500">
                        {{ $t('Remembered password?') }} 
                        <Link href="login" class="text-sm text-primary-600 hover:underline dark:text-primary-500 border-b hover:border-none">{{ $t('Login') }}</Link>
                    </div>
                    <form @submit.prevent="submitForm()" class="mt-5">
                        <div v-if="props.flash?.status?.message" class="text-sm bg-[green] text-white mb-2 py-2 px-1 text-center rounded">
                            <span>{{ props.flash?.status?.message }}</span>
                        </div>
                        <FormInput v-model="form.password" :name="$t('Password')" :error="form.errors.password" :type="'password'" :class="'sm:col-span-6'"/>
                        <FormInput v-model="form.password_confirmation" :name="$t('Confirm password')" :error="form.errors.password_confirmation" :type="'password'" :class="'sm:col-span-6 mt-5'"/>
                        <div v-if="form.errors.recaptcha_response" class="form-error text-[#b91c1c] text-xs">{{ form.errors.recaptcha_response }}</div>
                        <div class="mt-6">
                            <button v-if="!isLoading" type="submit" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full">{{ $t('Reset password') }}</button>
                            <button v-else type="button" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full flex justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
    import FormInput from '@/Components/FormInput.vue';
import { useRtl } from '@/Composables/useRtl';
import { Link, useForm } from "@inertiajs/vue3";
import { defineProps, onMounted, onUnmounted, ref } from 'vue';
import { unMountRecaptcha, useRecaptcha } from '../../Composables/ReCaptcha';

    const { rtlClass, isRtl } = useRtl();

    const props = defineProps(['flash', 'config', 'companyConfig']);

    const isLoading = ref(false);

    const form = useForm({
        password: null,
        password_confirmation: null,
        recaptcha_response: null,
    });

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const submitForm = async () => {
        isLoading.value = true;
        
        if(getValueByKey('recaptcha_active') === '1'){
            const token = await getRecaptchaToken();
            form.recaptcha_response = token;
        }

        const currentUrl = window.location.href;

        form.post(currentUrl, {
            preserveScroll: true,
            onFinish: () => {
                isLoading.value = false;
            }
        });
    };

    const getRecaptchaToken = () => {
        return new Promise((resolve) => {
            // Execute reCAPTCHA and get the token
            grecaptcha.ready(() => {
                grecaptcha.execute(getValueByKey('recaptcha_site_key'), { action: 'submit' })
                .then((token) => {
                    resolve(token);
                });
            });
        });
    };

    onMounted(() => {
        if(getValueByKey('recaptcha_active') === '1'){
            useRecaptcha(getValueByKey('recaptcha_site_key'));
        }
    });

    onUnmounted(() => {
        unMountRecaptcha(getValueByKey('recaptcha_site_key'));
    });
</script>
