<script setup>
    import axios from "axios";
    import { useForm } from '@inertiajs/vue3';
    import { onMounted } from 'vue';
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';

    const props = defineProps({
        modelValue: Boolean,
    });

    const form = useForm({
        api_key: null,
        webhook_secret: null,
        mode: 'sandbox',
        country_code: 'SAU',
        currency: 'SAR',
        language: 'ar',
        status: null,
    });

    const statusOptions = [
        { value: '1', label: 'Active' },
        { value: '0', label: 'Inactive' },
    ];

    const modeOptions = [
        { value: 'sandbox', label: 'Sandbox (Test)' },
        { value: 'production', label: 'Production (Live)' },
    ];

    const languageOptions = [
        { value: 'ar', label: 'Arabic' },
        { value: 'en', label: 'English' },
    ];

    const submitForm = async () => {
        form.put('/admin/payment-gateways/myfatoorah', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    function getRow() {
        axios.get('/admin/payment-gateways/myfatoorah').then((response) => {
            const { data } = response.data;
            const metadata = typeof data.metadata === 'string'
                ? JSON.parse(data.metadata || '{}')
                : (data.metadata || {});

            form.api_key = metadata.api_key || null;
            form.webhook_secret = metadata.webhook_secret || null;
            form.mode = metadata.mode || 'sandbox';
            form.country_code = metadata.country_code || 'SAU';
            form.currency = metadata.currency || 'SAR';
            form.language = metadata.language || 'ar';
            form.status = data.is_active ? '1' : '0';
        }).catch(() => {});
    }

    const emit = defineEmits(['update:modelValue']);

    function onClose() {
        emit('update:modelValue', false);
    }

    onMounted(getRow);
</script>

<template>
    <Modal :label="$t('Edit MyFatoorah configuration')" :isOpen="modelValue">
        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <form @submit.prevent="submitForm()">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6 sm:col-span-4 pb-8 border-b">
                    <FormInput v-model="form.api_key" :name="$t('API key')" type="password" :error="form.errors.api_key" class="sm:col-span-6" />
                    <FormInput v-model="form.webhook_secret" :name="$t('Webhook secret')" type="password" :error="form.errors.webhook_secret" class="sm:col-span-6" />
                    <FormSelect v-model="form.mode" :name="$t('Environment mode')" :options="modeOptions" :error="form.errors.mode" class="sm:col-span-6" />
                    <FormInput v-model="form.country_code" :name="$t('Country code')" type="text" :error="form.errors.country_code" class="sm:col-span-3" />
                    <FormInput v-model="form.currency" :name="$t('Currency')" type="text" :error="form.errors.currency" class="sm:col-span-3" />
                    <FormSelect v-model="form.language" :name="$t('Checkout language')" :options="languageOptions" :error="form.errors.language" class="sm:col-span-6" />
                    <FormSelect v-model="form.status" :name="$t('Status')" :options="statusOptions" :error="form.errors.status" class="sm:col-span-6" />
                </div>

                <p class="text-xs text-gray-500 mb-2">
                    {{ $t('API key, environment mode, currency, and webhook secret are managed here — not from .env.') }}
                </p>
                <p class="text-xs text-gray-500 mb-4">
                    {{ $t('Webhook URL') }}: /webhook/myfatoorah
                </p>

                <div class="mt-4 flex">
                    <button type="button" @click.self="onClose" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button type="submit" class="inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>
