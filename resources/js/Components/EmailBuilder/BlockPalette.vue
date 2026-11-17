<template>
    <div>
        <p class="mb-3 text-xs text-slate-500">{{ $t('Drag an element into the email or click to add it.') }}</p>
        <draggable :list="palette" item-key="type" tag="div" class="grid grid-cols-3 gap-2 sm:grid-cols-5"
            :group="{ name: 'email-blocks', pull: 'clone', put: false }" :sort="false" :clone="cloneBlock">
            <template #item="{ element }">
                <button type="button" :data-palette="element.type" :disabled="full"
                    class="flex flex-col items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-2 py-3 text-xs text-slate-700 hover:border-slate-300 hover:bg-white disabled:cursor-not-allowed disabled:opacity-50"
                    @click="emit('add', element.type)">
                    <component :is="BLOCK_ICONS[element.type]" class="h-5 w-5" aria-hidden="true" />
                    <span>{{ $t(BLOCK_LABELS[element.type]) }}</span>
                </button>
            </template>
        </draggable>
    </div>
</template>

<script setup>
/**
 * «العناصر»: النقر يضيف العنصر بعد المحدد، والسحب ينسخه إلى «ترتيب البريد»
 * في الموضع الذي يُفلت فيه.
 */
import draggable from 'vuedraggable'
import { BLOCK_LABELS, BLOCK_TYPES, createBlock } from '@/Composables/emailDesign.js'
import { useTrans } from '@/Composables/useTrans'
import { BLOCK_ICONS } from './blockIcons.js'

defineProps({
    full: { type: Boolean, default: false },
})

const emit = defineEmits(['add'])
const trans = useTrans()

const palette = BLOCK_TYPES.map((type) => ({ type }))
const cloneBlock = (item) => createBlock(item.type, trans)
</script>
