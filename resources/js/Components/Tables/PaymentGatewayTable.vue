<script setup>
    import { ref } from 'vue';  
    import AlertModal from '@/Components/AlertModal.vue';
    import { useForm } from "@inertiajs/vue3";
    import { useAlertModal } from '@/Composables/useAlertModal';
    import FlutterwaveFormModal from '@/Components/PaymentConfigModals/FlutterwaveModal.vue';
    import PayPalFormModal from '@/Components/PaymentConfigModals/PayPalModal.vue';
    import PaystackFormModal from '@/Components/PaymentConfigModals/PaystackModal.vue';
    import StripeFormModal from '@/Components/PaymentConfigModals/StripeModal.vue';
    import Table from '@/Components/Table.vue';
    import TableBody from '@/Components/TableBody.vue';
    import TableBodyRow from '@/Components/TableBodyRow.vue';
    import TableBodyRowItem from '@/Components/TableBodyRowItem.vue';

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const emit = defineEmits(['edit']);

    const isOpenFormModal = ref(false);
    const paymentMethod = ref();

    const view = (type) => {
        paymentMethod.value = type;
        isOpenFormModal.value = true;
    }

    const isLastRow = (index) => {
      return index === props.rows.data.length - 1;
    }
</script>
<template>
    <Table :rows="rows">
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="[index === 0? 'border-t-[0px]' : '', !isLastRow(index) ? 'border-b' : '']">
                <TableBodyRowItem :position="'first'" class="py-2">
                    {{ item.name }}
                    <span class="bg-gray-200 text-[11px] p-1 rounded-md">{{ item.is_active == '1' ? $t('Active') : $t('Inactive') }}</span>
                </TableBodyRowItem>
                <TableBodyRowItem :position="'last'">
                    <div class="flex items-center h-100 py-2">
                        <button type="button" class="inline-flex justify-center rounded-md border border-transparent bg-primary px-2 py-1 text-[12px] text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2" @click="view(item.name)">{{ $t('Edit') }}</button>
                    </div>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>

    <FlutterwaveFormModal v-if="paymentMethod === 'Flutterwave'" v-model="isOpenFormModal" @closeModal="isOpenFormModal = false"/>
    <PayPalFormModal v-if="paymentMethod === 'Paypal'" v-model="isOpenFormModal" @closeModal="isOpenFormModal = false"/>
    <PaystackFormModal v-if="paymentMethod === 'Paystack'" v-model="isOpenFormModal" @closeModal="isOpenFormModal = false"/>
    <StripeFormModal v-if="paymentMethod === 'Stripe'" v-model="isOpenFormModal" @closeModal="isOpenFormModal = false"/>
</template>
  