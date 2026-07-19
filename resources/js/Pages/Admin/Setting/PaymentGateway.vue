<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Payment gateways') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure payment gateways for accepting subscription payments') }}</span>
            </p>
        </div>
        <!-- Table Component-->
        <PaymentGatewayTable :rows="props.rows" />
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./Layout/App.vue";
    import PaymentGatewayTable from '@/Components/Tables/PaymentGatewayTable.vue';

    const props = defineProps({ rows: Object });
</script>