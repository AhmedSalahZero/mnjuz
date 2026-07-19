<template>
    <Modal :label="$t('Transfer Subscribers')" :isOpen="isOpen" @close="closeModal">
        <div class="mt-5">
            <div v-if="loading" class="flex justify-center items-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            </div>
            
            <div v-else-if="subscriberData.has_subscribers">
                <div class="mb-4 p-2 bg-yellow-50 border border-yellow-200 rounded-md">
                    <div class="flex">
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">
                                {{ $t('Subscribers Found') }}
                            </h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>{{ $t('This plan has') }} <strong>{{ subscriberData.subscriber_count }}</strong> {{ $t('subscribers') }}. {{ $t('Please select a plan to transfer them to before deleting this plan') }}.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">{{ $t('Current Plan') }}:</h4>
                    <div class="p-3 bg-gray-50 rounded-md">
                        <div class="font-medium">{{ subscriberData.plan.name }}</div>
                        <div class="text-sm text-gray-600">{{ getCurrencySymbol() }}{{ subscriberData.plan.price }} / {{ subscriberData.plan.period }}</div>
                    </div>
                </div>

                <form @submit.prevent="submitTransfer">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            {{ $t('Transfer subscribers to') }}:
                        </label>
                        <FormSelect 
                            v-model="form.transfer_to_plan" 
                            :name="$t('Select Plan')" 
                            :error="form.errors.transfer_to_plan" 
                            :options="availablePlans" 
                            :class="'w-full'"
                            :dropdownUp="true"
                            required
                        />
                    </div>


                    <div class="flex justify-end space-x-3">
                        <button 
                            type="button" 
                            @click="closeModal" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button 
                            type="submit" 
                            :disabled="form.processing || !form.transfer_to_plan"
                            class="px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span v-if="form.processing">{{ $t('Transferring...') }}</span>
                            <span v-else>{{ $t('Transfer & Delete Plan') }}</span>
                        </button>
                    </div>
                </form>
            </div>
            
            <div v-else>
                <div class="text-center py-8">
                    <div class="text-green-600 mb-2">
                        <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $t('No Subscribers') }}</h3>
                    <p class="text-gray-600 mb-4">{{ $t('This plan has no subscribers and can be safely deleted') }}.</p>
                    <div class="flex justify-center space-x-3">
                        <button 
                            type="button" 
                            @click="closeModal" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button 
                            @click="deletePlan" 
                            :disabled="form.processing"
                            class="px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                        >
                            {{ $t('Delete Plan') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import FormSelect from '@/Components/FormSelect.vue';

const props = defineProps({
    isOpen: {
        type: Boolean,
        default: false
    },
    planUuid: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['close', 'deleted']);

const loading = ref(false);
const subscriberData = ref({ has_subscribers: false });

const form = useForm({
    transfer_to_plan: null
});

const availablePlans = ref([]);

const getCurrencySymbol = () => {
    const page = usePage();
    const currencyConfig = page.props.config?.find(item => item.key === 'currency');
    return currencyConfig?.value || '$';
};

const checkSubscribers = async () => {
    if (!props.planUuid) return;
    
    loading.value = true;
    try {
        const response = await fetch(`/admin/plans/${props.planUuid}/check-subscribers`);
        const data = await response.json();
        
        subscriberData.value = data;
        
        if (data.has_subscribers && data.available_plans) {
            availablePlans.value = data.available_plans.map(plan => ({
                value: plan.uuid,
                label: `${plan.name} - ${getCurrencySymbol()}${plan.price}/${plan.period}`
            }));
        }
    } catch (error) {
        console.error('Error checking subscribers:', error);
    } finally {
        loading.value = false;
    }
};

const submitTransfer = () => {
    if (!form.transfer_to_plan) return;
    
    form.post(`/admin/plans/${props.planUuid}/destroy-with-transfer`, {
        onSuccess: () => {
            emit('deleted');
            closeModal();
        }
    });
};

const deletePlan = () => {
    form.delete(`/admin/plans/${props.planUuid}`, {
        onSuccess: () => {
            emit('deleted');
            closeModal();
        }
    });
};

const closeModal = () => {
    emit('close');
    form.reset();
    subscriberData.value = { has_subscribers: false };
    availablePlans.value = [];
};

// Watch for planUuid changes and check subscribers
watch(() => props.planUuid, (newUuid) => {
    if (newUuid && props.isOpen) {
        checkSubscribers();
    }
});

// Watch for modal open state
watch(() => props.isOpen, (isOpen) => {
    if (isOpen && props.planUuid) {
        checkSubscribers();
    }
});
</script>
