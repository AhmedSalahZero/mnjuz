<template>
    <Modal :label="$t('Transfer Users')" :isOpen="isOpen" @close="closeModal">
        <div class="mt-5">
            <div v-if="loading" class="flex justify-center items-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            </div>
            
            <div v-else-if="!isClosing && userData.has_users">
                <div class="mb-4 p-2 bg-yellow-50 border border-yellow-200 rounded-md">
                    <div class="flex">
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">
                                {{ $t('Users Found') }}
                            </h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>{{ $t('This role has') }} <strong>{{ userData.user_count }}</strong> {{ $t('users') }}. {{ $t('Please select a role to transfer them to before deleting this role') }}.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">{{ $t('Current Role') }}:</h4>
                    <div class="p-3 bg-gray-50 rounded-md">
                        <div class="font-medium">{{ userData.role.name }}</div>
                    </div>
                </div>

                <form @submit.prevent="submitTransfer">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            {{ $t('Transfer users to') }}:
                        </label>
                        <FormSelect 
                            v-model="form.new_role" 
                            :name="$t('Select Role')" 
                            :error="form.errors.new_role" 
                            :options="availableRoles" 
                            :class="'w-full'"
                            :dropdownUp="true"
                            required
                        />
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button 
                            type="button" 
                            @click.prevent="closeModal" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button 
                            type="submit" 
                            :disabled="form.processing || !form.new_role"
                            class="px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span v-if="form.processing">{{ $t('Transferring...') }}</span>
                            <span v-else>{{ $t('Transfer & Delete Role') }}</span>
                        </button>
                    </div>
                </form>
            </div>
            
            <div v-else-if="!isClosing">
                <div class="text-center py-8">
                    <div class="text-green-600 mb-2">
                        <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $t('No Users') }}</h3>
                    <p class="text-gray-600 mb-4">{{ $t('This role has no users and can be safely deleted') }}.</p>
                    <div class="flex justify-center space-x-3">
                        <button 
                            type="button" 
                            @click.prevent="closeModal" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button 
                            @click="deleteRole" 
                            :disabled="form.processing"
                            class="px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                        >
                            {{ $t('Delete Role') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import FormSelect from '@/Components/FormSelect.vue';

const props = defineProps({
    isOpen: {
        type: Boolean,
        default: false
    },
    roleUuid: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['close', 'deleted']);

const loading = ref(false);
const userData = ref({ has_users: false });
const isClosing = ref(false);

const form = useForm({
    new_role: null
});

const availableRoles = ref([]);

const checkUsers = async () => {
    if (!props.roleUuid) return;
    
    loading.value = true;
    try {
        const response = await fetch(`/admin/team/roles/${props.roleUuid}/check-users`);
        const data = await response.json();
        
        userData.value = data;
        
        if (data.has_users && data.available_roles) {
            availableRoles.value = data.available_roles.map(role => ({
                value: role.uuid,
                label: role.name
            }));
        }
    } catch (error) {
        console.error('Error checking users:', error);
    } finally {
        loading.value = false;
    }
};

const submitTransfer = () => {
    if (!form.new_role) return;
    
    form.post(`/admin/team/roles/${props.roleUuid}/destroy-with-transfer`, {
        onSuccess: () => {
            emit('deleted');
            closeModal();
        }
    });
};

const deleteRole = () => {
    form.delete(`/admin/team/roles/${props.roleUuid}`, {
        onSuccess: () => {
            emit('deleted');
            closeModal();
        }
    });
};

const closeModal = () => {
    // Set closing flag to prevent showing content
    isClosing.value = true;
    
    // Reset form first to prevent any submission
    form.reset();
    form.clearErrors();
    
    // Reset data
    userData.value = { has_users: false };
    availableRoles.value = [];
    
    // Emit close event
    emit('close');
};

// Watch for roleUuid changes and check users
watch(() => props.roleUuid, (newUuid) => {
    if (newUuid && props.isOpen) {
        checkUsers();
    }
});

// Watch for modal open state
watch(() => props.isOpen, (isOpen) => {
    if (isOpen && props.roleUuid) {
        isClosing.value = false;
        checkUsers();
    }
});
</script>
