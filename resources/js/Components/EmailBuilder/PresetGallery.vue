<template>
    <Modal :label="$t('Start from a ready template')" :isOpen="isOpen" :closeBtn="true" max-width="max-w-4xl" @close="emit('close')">
        <div dir="auto" class="text-start">
            <p class="mb-4 mt-2 text-sm text-slate-500">{{ $t('Choose a template, then edit it as you like.') }}</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button v-for="preset in presets" :key="preset.key" type="button" :data-preset="preset.key"
                    class="rounded-lg border border-slate-200 p-4 text-start transition hover:border-slate-300 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-primary"
                    @click="emit('choose', preset.key)">
                    <div class="mb-1 flex items-center gap-2">
                        <span v-if="preset.color" class="inline-block h-3 w-3 rounded-sm" :style="{ background: preset.color }" aria-hidden="true"></span>
                        <span class="font-medium text-slate-800">{{ $t(preset.name) }}</span>
                    </div>
                    <p class="text-xs text-slate-500">{{ $t(preset.description) }}</p>
                </button>
            </div>
        </div>
    </Modal>
</template>

<script setup>
/** «ابدأ من قالب جاهز»: بطاقة لكل قالب؛ الاختيار يُرسل مفتاحه. */
import Modal from '@/Components/Modal.vue'
import { presetCards } from '@/Composables/emailPresets.js'

defineProps({
    isOpen: { type: Boolean, default: false },
})

const emit = defineEmits(['choose', 'close'])
const presets = presetCards()
</script>
