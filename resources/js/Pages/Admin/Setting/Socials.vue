<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Social login settings') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure social accounts for user login') }}</span>
            </p>
        </div>
        <form class="" @submit.prevent="submitForm()">
            <div class="space-y-6">
                <div class="">
                    <div class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                        <FormInput v-model="form.google_login.client_id" :name="$t('Google client id')" :error="form.errors['google_login.client_id']" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.google_login.client_secret" :name="$t('Google client secret')" :error="form.errors['google_login.client_secret']" :type="'password'" :class="'col-span-1'"/>
                        <FormInput v-model="form.facebook_login.client_id" :name="$t('Facebook client id')" :error="form.errors['facebook_login.client_id']" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.facebook_login.client_secret" :name="$t('Facebook client secret')" :error="form.errors['facebook_login.client_secret']" :type="'password'" :class="'col-span-1'"/>
                        <div class="relative flex gap-x-3 col-span-2">
                            <div class="flex items-center">
                                <label @click="toggleGoogleLoginActive()" for="myCheckbox" class="cursor-pointer">
                                    <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center" :class="form.allow_google_login ? 'bg-[#000]' : ''">
                                        <svg v-if="form.allow_google_login" class="w-4 h-4" :class="form.allow_google_login ? 'text-white' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                </label>
                                <span @click="toggleGoogleLoginActive()" class="ml-2 text-[14px] cursor-pointer">{{ $t('Allow google login') }}</span>
                            </div>
                        </div>
                        <div class="relative flex gap-x-3 col-span-2">
                            <div class="flex items-center">
                                <label @click="toggleFacebookLoginActive()" for="myCheckbox" class="cursor-pointer">
                                    <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center" :class="form.allow_facebook_login ? 'bg-[#000]' : ''">
                                        <svg v-if="form.allow_facebook_login" class="w-4 h-4" :class="form.allow_facebook_login ? 'text-white' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                </label>
                                <span @click="toggleFacebookLoginActive()" class="ml-2 text-[14px] cursor-pointer">{{ $t('Allow facebook login') }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-x-6 md:w-2/3">
                        <button
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                            :disabled="isLoading"
                        >
                            <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./Layout/App.vue";
    import { ref } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';

    const props = defineProps({
        config: {
            type: Object,
            required: true
        }
    });

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const getSocialSettings = (key, value) => {
        const config = JSON.parse(getValueByKey(key));
        
        if (config !== null && typeof config === 'object' && !Array.isArray(config)) {
            return config[value] ?? null;
        }

        return null;
    }

    const isLoading = ref(false);
    const form = useForm({
        allow_facebook_login: getValueByKey('allow_facebook_login') === '1' ? true : false,
        allow_google_login: getValueByKey('allow_google_login') === '1' ? true : false,
        facebook_login: {
            client_id: getSocialSettings('facebook_login', 'client_id') ?? null,
            client_secret: getSocialSettings('facebook_login', 'client_secret') ?? null,
        },
        google_login: {
            client_id: getSocialSettings('google_login', 'client_id') ?? null,
            client_secret: getSocialSettings('google_login', 'client_secret') ?? null,
        },
    })

    const toggleFacebookLoginActive = () => {
        form.allow_facebook_login = !form.allow_facebook_login;
    };

    const toggleGoogleLoginActive = () => {
        form.allow_google_login = !form.allow_google_login;
    };

    const submitForm = async () => {
        form.put('/admin/settings?type=socials', {
            preserveScroll: true,
        });
    };
</script>