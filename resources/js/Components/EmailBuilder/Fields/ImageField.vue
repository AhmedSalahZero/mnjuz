<template>
    <div>
        <div class="mb-1 text-xs text-slate-600">{{ label }}</div>
        <div class="flex items-center gap-3">
            <div class="flex h-16 w-24 items-center justify-center overflow-hidden rounded-md border border-dashed border-slate-300 bg-slate-50">
                <img v-if="modelValue" :src="modelValue" alt="" class="h-full w-full object-cover" />
                <ImageIcon v-else class="h-6 w-6 text-slate-400" aria-hidden="true" />
            </div>
            <div class="flex flex-col items-start gap-1">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
                    @click="emit('pick')">
                    {{ modelValue ? $t('Change image') : $t('Choose from the image library') }}
                </button>
                <button v-if="modelValue" type="button" class="text-xs text-red-600 underline"
                    @click="emit('update:modelValue', '')">
                    {{ $t('Remove') }}
                </button>
            </div>
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>

<script setup>
import { Image as ImageIcon } from 'lucide-vue-next'

defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    error: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'pick'])
</script>
