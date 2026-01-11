<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Billing info') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure the information to be seen in your invoices') }}</span>
            </p>
        </div>
        <form @submit.prevent="submitForm()">
            <div class="space-y-12">
                <div class="pb-12">
                    <div class="grid gap-3 grid-cols-2 pb-6 border-b md:w-2/3">
                        <FormInput v-model="form.billing_name" :name="$t('Vendor name')" :error="form.errors.billing_name" :type="'text'" :class="'col-span-2'"/>
                        <FormInput v-model="form.invoice_prefix" :name="$t('Invoice prefix')" :error="form.errors.invoice_prefix" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.billing_tax_id" :name="$t('Tax number')" :error="form.errors.billing_tax_id" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.billing_phone_1" :name="$t('Phone')" :error="form.errors.billing_phone_1" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.billing_phone_2" :name="$t('Phone 2')" :error="form.errors.billing_phone_2" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.billing_address" :name="$t('Address')" :error="form.errors.billing_address" :type="'text'" :class="'col-span-2'"/>
                        <FormInput v-model="form.billing_city" :name="$t('City')" :error="form.errors.billing_city" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.billing_state" :name="$t('State')" :error="form.errors.billing_state" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.billing_postal_code" :name="$t('Zip code')" :error="form.errors.billing_postal_code" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.billing_country" :name="$t('Country')" :error="form.errors.billing_country" :type="'text'" :class="'col-span-1'"/>
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

    const isLoading = ref(false);
    const form = useForm({
        billing_name: getValueByKey('billing_name'),
        invoice_prefix: getValueByKey('invoice_prefix'),
        billing_tax_id: getValueByKey('billing_tax_id'),
        billing_phone_1: getValueByKey('billing_phone_1'),
        billing_phone_2: getValueByKey('billing_phone_2'),
        billing_address: getValueByKey('billing_address'),
        billing_city: getValueByKey('billing_city'),
        billing_state: getValueByKey('billing_state'),
        billing_postal_code: getValueByKey('billing_postal_code'),
        billing_country: getValueByKey('billing_country'),
    })

    const submitForm = async () => {
        form.put('/admin/settings?type=billing', {
            preserveScroll: true,
        });
    };
</script>