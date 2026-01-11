<template>
    <AppLayout>
        <div class="p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="flex justify-between">
                <div>
                    <h1 v-if="props.plan === null" class="text-xl mb-1">{{ $t('Create plan') }}</h1>
                    <h1 v-else class="text-xl mb-1">{{ $t('Update plan') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span v-if="props.plan === null" class="ml-1 mt-1">{{ $t('Create plan') }}</span>
                        <span v-else class="ml-1 mt-1">{{ $t('Update plan') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/plans" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <form @submit.prevent="submitForm()" class="bg-white border py-5 px-5 rounded-[0.5rem]">
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Name') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.name" :name="$t('Name')" :error="form.errors.name" :type="'text'" :class="'sm:col-span-6'"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Status') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormSelect v-model="form.status" :options="statusOptions" :error="form.errors.status" :name="$t('Status')" :class="'sm:col-span-6'" :placeholder="$t('Select status')"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Pricing details') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.price" :name="$t('Price')" :error="form.errors.price" :type="'number'" :class="'sm:col-span-3'"/>
                            <FormSelect v-model="form.period" :options="periodOptions" :error="form.errors.period" :name="$t('Period')" :class="'sm:col-span-3'" :placeholder="$t('Select period')"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex py-5 border-b">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Plan limit') }}</h1>
                    </div>
                    <div class="sm:w-[60%]">
                        <div class="bg-orange-100 p-2 rounded-md shadow-sm sm:w-[80%] mb-4 flex items-center gap-x-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                            <p class="text-sm leading-6">{{ $t('For unlimited usage, set -1 as the value') }}</p>
                        </div>
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.campaign_limit" :name="$t('Campaign limit')" :error="form.errors.campaign_limit" :type="'number'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.message_limit" :name="$t('Message limit')" :error="form.errors.message_limit" :type="'number'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.contacts_limit" :name="$t('Contacts limit')" :error="form.errors.contacts_limit" :type="'number'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.canned_replies_limit" :name="$t('Canned replies limit')" :error="form.errors.canned_replies_limit" :type="'number'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.team_limit" :name="$t('User limit')" :error="form.errors.team_limit" :type="'number'" :class="'sm:col-span-3'"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex py-5 border-b">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Addons access') }}</h1>
                        <p class="text-xs text-slate-700">{{ $t('Select addons that are available in this plan') }}</p>
                    </div>
                    <div class="sm:w-[60%]">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <div v-for="addon in addons" class="sm:col-span-3">
                                <div class="text-sm mb-2">{{ addon }}</div>
                                <FormToggleSwitch v-model="form.addons[addon]"/>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="props.enable_ai_billing == 1 && form.addons['AI Assistant']" class="sm:flex py-5 border-b">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('AI Assistant Usage Limit') }}</h1>
                    </div>
                    <div class="sm:w-[60%]">
                        <div class="bg-orange-100 p-2 rounded-md shadow-sm sm:w-[80%] mb-4 flex items-center gap-x-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                            <p class="text-sm leading-6">{{ $t('For unlimited usage, set -1 as the value') }}</p>
                        </div>
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.ai_text_response_limit" :name="$t('AI Text Response Limit')" :error="form.errors.ai_text_response_limit" :type="'number'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.ai_audio_response_limit" :name="$t('AI Audio Response Limit')" :error="form.errors.ai_audio_response_limit" :type="'number'" :class="'sm:col-span-3'"/>
                        </div>
                    </div>
                </div>
                <div class="py-5 sm:flex">
                    <div class="w-[40%]">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Enable message reception after plan expiration') }}</h1>
                        <div class="text-xs text-slate-700 flex items-center">
                            <span>{{ $t('Toggle this setting to allow or block inbound messages when a user\'s subscription plan has ended.') }}</span>
                        </div>
                    </div>
                    <div class="sm:w-[20%]">
                        <FormToggleSwitch v-model="form.receive_messages_after_expiration" class="float-left"/>
                    </div>
                </div>
                <div class="py-6">
                    <button type="submit" class="float-right flex items-center space-x-4 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-slate-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { ref } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps(['title', 'plan', 'addons', 'enable_ai_billing']);

    const getDetail = (value, key) => {
        if(value){
            const item = JSON.parse(value);
            return item?.[key] ?? null;
        } else {
            return null;
        }
    }

    const getAddons = (value, key) => {
        if(value){
            const item = JSON.parse(value);

            return item?.[key] ? item[key] : props.addons.reduce((acc, addon) => {
                acc[addon] = false;
                return acc;
            }, {});
        }

        // Return the props.addons with all values set to false
        return props.addons.reduce((acc, addon) => {
            acc[addon] = false;
            return acc;
        }, {});
    };

    const form = useForm({
        name: props.plan?.name,
        price: props.plan?.price,
        period: props.plan?.period,
        status: props.plan?.status,
        campaign_limit: getDetail(props.plan?.metadata, 'campaign_limit') ?? '-1',
        message_limit: getDetail(props.plan?.metadata, 'message_limit') ?? '-1',
        contacts_limit: getDetail(props.plan?.metadata, 'contacts_limit') ?? '-1',
        canned_replies_limit: getDetail(props.plan?.metadata, 'canned_replies_limit') ?? '-1',
        team_limit: getDetail(props.plan?.metadata, 'team_limit') ?? '-1',
        ai_text_response_limit: getDetail(props.plan?.metadata, 'ai_text_response_limit') ?? '-1',
        ai_audio_response_limit: getDetail(props.plan?.metadata, 'ai_audio_response_limit') ?? '-1',
        receive_messages_after_expiration: getDetail(props.plan?.metadata, 'receive_messages_after_expiration') == 1 || getDetail(props.plan?.metadata, 'receive_messages_after_expiration') == null ? true : false,
        addons: getAddons(props.plan?.metadata, 'addons')
    })

    const statusOptions = ref([
        { value: 'active', label: trans('active') },
        { value: 'inactive', label: trans('inactive') }
    ]);

    const periodOptions = ref([
        { value: 'monthly', label: trans('Monthly') },
        { value: 'yearly', label: trans('Yearly') }
    ]);

    const submitForm = async () => {
        const url = props.plan ? window.location.pathname : '/admin/plans';

        form[props.plan ? 'put' : 'post'](url, {
            preserveScroll: true,
        });
    };
</script>