<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Subscription settings') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure how your subscriptions are set') }}</span>
            </p>
        </div>
        <form @submit.prevent="submitForm()">
            <div class="space-y-12">
                <div class="pb-12">
                    <div class="pt-5">
                        <div class="flex gap-x-10 md:w-2/3 pb-6 border-b mb-4">
                            <div class="w-[80%]">
                                <span>{{ $t('Enable custom payment amounts') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <span>{{ $t('Allow users to make custom payments that can be applied to their subscription fees automatically.') }}</span>
                                </div>
                            </div>
                            <div class="w-[20%]">
                                <FormToggleSwitch v-model="form.enable_custom_payment" class="float-right"/>
                            </div>
                        </div>
                    </div>
                    <div class="grid gap-3 grid-cols-2 pb-6 md:w-2/3 border-b mb-4">
                        <FormInput v-model="form.trial_period" :name="$t('Trial period (in days)')" :error="form.errors.trial_period" :type="'number'" :class="'col-span-2'"/>
                        <span class="col-span-2 text-xs">{{ $t('Please note that if you put zero then the user will be prompted to first subscribe before using the app') }}</span>
                    </div>
                    <div class="mb-4">
                        <h2 class="text-base text-gray-900">{{ $t('Trial period limits') }}</h2>
                        <p class="text-sm leading-6 mb-5">{{ $t('Define the usage limits during the trial period') }}</p>
                    </div>
                    <div class="bg-orange-100 p-2 rounded-md shadow-sm md:w-2/3 mb-4 flex items-center gap-x-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <p class="text-sm leading-6">{{ $t('For unlimited usage, set -1 as the value') }}</p>
                    </div>
                    <div class="grid gap-3 grid-cols-2 pb-6 border-b md:w-2/3">
                        <FormInput v-model="form.trial_limits.messages" :name="$t('Message limit')" :error="form.errors?.['trial_limits.messages']" :type="'number'" :class="'col-span-1'"/>
                        <FormInput v-model="form.trial_limits.campaigns" :name="$t('Campaign limit')" :error="form.errors?.['trial_limits.campaigns']" :type="'number'" :class="'col-span-1'"/>
                        <FormInput v-model="form.trial_limits.contacts" :name="$t('Contacts limit')" :error="form.errors?.['trial_limits.contacts']" :type="'number'" :class="'col-span-1'"/>
                        <FormInput v-model="form.trial_limits.automated_replies" :name="$t('Automated Replies limit')" :error="form.errors?.['trial_limits.automated_replies']" :type="'number'" :class="'col-span-1'"/>
                        <FormInput v-model="form.trial_limits.users" :name="$t('User limit')" :error="form.errors?.['trial_limits.users']" :type="'number'" :class="'col-span-1'"/>
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
    import { useForm } from "@inertiajs/vue3";
    import { defineProps, ref } from 'vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';

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

    const limits = getValueByKey('trial_limits') ? JSON.parse(getValueByKey('trial_limits')) : {};

    const isLoading = ref(false);
    const form = useForm({
        enable_custom_payment: getValueByKey('enable_custom_payment') == 1 || getValueByKey('enable_custom_payment') == '' ? true : false,
        trial_period: getValueByKey('trial_period'),
        trial_limits: {
            users: limits?.users ?? '-1',
            messages: limits?.messages ?? '-1',
            contacts: limits?.contacts ?? '-1',
            campaigns: limits?.campaigns ?? '-1',
            automated_replies: limits?.automated_replies ?? '-1'
        }
    })

    const submitForm = async () => {
        form.put('/admin/settings?type=subscription', {
            preserveScroll: true,
        });
    };
</script>