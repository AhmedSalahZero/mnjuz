<template>
    <Modal :label="'Export'" :closeBtn="false" :isOpen="modelValue" @modal-close="$emit('update:modelValue', false)">
        <div class="mt-5">
            <p class="text-sm text-gray-600 mb-4">{{ $t('Choose the format you want to export your data in:') }}</p>
            
            <div class="space-y-3">
                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                    <input 
                        type="radio" 
                        name="exportFormat" 
                        value="xlsx" 
                        v-model="selectedFormat"
                        class="mr-3"
                    >
                    <div class="flex items-center">
                        <div>
                            <div class="font-medium text-gray-900">{{ $t('Excel (.xlsx)') }}</div>
                            <div class="text-sm text-gray-500">{{ $t('Best for formatting, formulas, and complex data') }}</div>
                        </div>
                    </div>
                </label>
                
                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                    <input 
                        type="radio" 
                        name="exportFormat" 
                        value="csv" 
                        v-model="selectedFormat"
                        class="mr-3"
                    >
                    <div class="flex items-center">
                        <div>
                            <div class="font-medium text-gray-900">{{ $t('CSV (.csv)') }}</div>
                            <div class="text-sm text-gray-500">{{ $t('Universal format, smaller file size, faster processing') }}</div>
                        </div>
                    </div>
                </label>
            </div>
            
            <div class="mt-4 flex">
                <button 
                    type="button" 
                    @click="$emit('update:modelValue', false)"
                    class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4"
                >
                    {{ $t('Cancel') }}
                </button>
                <button 
                    @click="exportData"
                    :disabled="!selectedFormat"
                    :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': !selectedFormat }]"
                >
                    {{ $t('Export') }}
                </button>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    modelValue: Boolean,
    type: {
        type: String,
        default: 'contact'
    }
});

const emit = defineEmits(['update:modelValue']);

const selectedFormat = ref('xlsx');

const exportData = () => {
    if (!selectedFormat.value) return;
    
    const url = props.type === 'contact' 
        ? `/contacts/export?format=${selectedFormat.value}`
        : `/contact-groups/export?format=${selectedFormat.value}`;
    
    window.open(url, '_blank');
    emit('update:modelValue', false);
};
</script> 