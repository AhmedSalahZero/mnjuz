<template>
    <div :class="rtlClass">
        <div class="flex h-screen justify-center">
            <div class="flex justify-center items-center">
                <div v-if="!hasLinkExpired" class="w-[25em]">
                    <div class="flex justify-center mb-5">
                        <Link href="/">
                            <img class="max-w-[180px]" v-if="props.companyConfig.logo" :src="'/media/' + props.companyConfig.logo" :alt="props.companyConfig.company_name">
                            <h4 v-else class="text-2xl mb-2">{{ props.companyConfig.company_name }}</h4>
                        </Link>
                    </div>
                    <h3 class="text-2xl text-center">
                        <span v-if="props.user">{{ $t('Hi') }} {{ props.user?.first_name }}</span>
                        <span v-else>{{ $t('Hi') }}</span>,
                        {{ $t('You have been invited to join') }} {{ props?.organization?.name }}
                    </h3>
                    
                    <div v-if="!props.user" class="text-center text-sm text-slate-500 mt-5">{{ $t('Get started by entering your details below') }}</div>
                    
                    <form v-if="!props.user" @submit.prevent="submitForm()" class="mt-5">
                        <div class="mt-5 grid grid-cols-2 space-x-4">
                            <div class="">
                                <FormInput v-model="form.first_name" :name="$t('First name')" :type="'text'" :error="form.errors.first_name" :class="'w-full'" :labelClass="'mb-0'"/>
                            </div>
                            <div class="">
                                <FormInput v-model="form.last_name" :name="$t('Last name')" :type="'text'" :error="form.errors.last_name" :class="'w-full'" :labelClass="'mb-0'"/>
                            </div>
                        </div>
                        <div class="mt-5">
                            <FormInput v-model="form.email" :name="$t('Email')" :type="'email'" :disabled="true" :error="form.errors.email" :class="'w-full'" :labelClass="'mb-0'"/>
                        </div>
                        <div class="mt-5 grid grid-cols-2 space-x-4">
                            <div class="">
                                <FormInput v-model="form.password" :name="$t('Password')" :type="'password'" :error="form.errors.password" :class="'w-full'" :labelClass="'mb-0'"/>
                            </div>
                            <div class="">
                                <FormInput v-model="form.password_confirmation" :name="$t('Confirm password')" :type="'password'" :error="form.errors.password_confirmation" :class="'w-full'"/>
                            </div>
                        </div>
                        <div v-if="form.errors.recaptcha_response" class="form-error text-[#b91c1c] text-xs">{{ form.errors.recaptcha_response }}</div>
                        <div class="mt-6">
                            <button v-if="!isLoading" type="submit" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full">{{ $t('Create account') }}</button>
                            <button v-else type="button" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full flex justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            </button>
                        </div>
                    </form>
                    <form v-else @submit.prevent="submitForm()">
                        <div class="mt-10">
                            <button v-if="!isLoading" type="submit" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full">{{ $t('Proceed to join') }}</button>
                            <button v-else type="button" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full flex justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            </button>
                        </div>
                    </form>
                </div>
                <div v-else class="w-[25em]">
                    <h1 class="text-2xl text-center">
                        {{ $t('This invite link has expired. Contact the account owner to send you another link') }}
                    </h1>
                    <div class="mt-10 text-center">
                        <Link href="/login" type="button" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full">{{ $t('Go to login') }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
    import FormInput from '@/Components/FormInput.vue';
import { useRtl } from '@/Composables/useRtl';
import { Link, useForm } from "@inertiajs/vue3";
import { computed, defineProps, onMounted, onUnmounted, ref } from 'vue';
import { unMountRecaptcha, useRecaptcha } from '../../Composables/ReCaptcha';

    const { rtlClass, isRtl } = useRtl();

    const props = defineProps(['flash', 'config', 'organization', 'companyConfig', 'invite', 'user']);
    const expireTime = ref(props.invite?.expire_at);
    const currentTime = ref(new Date().toISOString());
    const isLoading = ref(false);

    const form = useForm({
        first_name: props.user?.first_name,
        last_name: props.user?.last_name,
        email: props.invite?.email,
        password: null,
        password_confirmation: null,
        code: props.code,
        recaptcha_response: null,
    });

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    if (props.user) {
        delete form.password;
    }

    const hasLinkExpired = computed(() => {
        const dbTime = new Date(expireTime.value);
        const current = new Date(currentTime.value);
        return dbTime < current;
    });

    const submitForm = async () => {
        isLoading.value = true;

        if(getValueByKey('recaptcha_active') === '1'){
            const token = await getRecaptchaToken();
            form.recaptcha_response = token;
        }
        
        form.post('', {
            onSuccess: () => form.reset(),
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
