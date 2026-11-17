<template>
    <div>
        <div class="mb-2 flex items-center gap-2">
            <h3 class="text-sm font-semibold text-slate-800">{{ $t('Email order') }}</h3>
        </div>
        <p class="mb-3 text-xs text-slate-500">{{ $t('Drag an element by its handle to move it, or drag a new element from above and drop it where you want.') }}</p>

        <draggable :model-value="blocks" item-key="id" tag="div" handle=".email-block-handle"
            class="min-h-[64px] space-y-2 rounded-lg" :class="{ 'border border-dashed border-slate-300 p-3': !blocks.length }"
            :group="{ name: 'email-blocks', pull: false, put: !full }"
            @update:model-value="emit('reorder', $event)" @change="onChange">
            <template #item="{ element, index }">
                <div :data-block-row="element.id"
                    class="flex items-center gap-2 rounded-lg border bg-white px-2 py-2"
                    :class="element.id === selectedId ? 'border-[#4c1d95] ring-1 ring-[#4c1d95]' : (hasError(index) ? 'border-red-400' : 'border-slate-200')">
                    <span class="email-block-handle cursor-move px-1 text-slate-400" :title="$t('Drag to reorder')" aria-hidden="true">
                        <GripVertical class="h-4 w-4" />
                    </span>
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-start" @click="emit('select', element.id)">
                        <component :is="BLOCK_ICONS[element.type]" class="h-4 w-4 shrink-0 text-slate-600" aria-hidden="true" />
                        <span class="min-w-0">
                            <span class="block text-sm text-slate-800">{{ $t(BLOCK_LABELS[element.type]) }}</span>
                            <span class="block truncate text-xs text-slate-500" dir="auto">{{ blockSummary(element, placeholders, trans) }}</span>
                        </span>
                    </button>
                    <BlockActions :first="index === 0" :last="index === blocks.length - 1" :can-duplicate="!full"
                        @up="emit('move', index, -1)" @down="emit('move', index, 1)"
                        @duplicate="emit('duplicate', index)" @remove="emit('remove', index)" />
                </div>
            </template>
            <template #footer>
                <p v-if="!blocks.length" class="py-2 text-center text-xs text-slate-400">{{ $t('No elements yet. Add one from the list above.') }}</p>
            </template>
        </draggable>
    </div>
</template>

<script setup>
/** «ترتيب البريد»: سحب بالمقبض، وأعلى/أسفل/تكرار/حذف، والنقر يفتح الخصائص. */
import draggable from 'vuedraggable'
import { GripVertical } from 'lucide-vue-next'
import { BLOCK_LABELS, blockErrors, blockSummary } from '@/Composables/emailDesign.js'
import { useTrans } from '@/Composables/useTrans'
import BlockActions from './BlockActions.vue'
import { BLOCK_ICONS } from './blockIcons.js'

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    selectedId: { type: String, default: null },
    placeholders: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    full: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'move', 'duplicate', 'remove', 'reorder', 'added'])
const trans = useTrans()

const hasError = (index) => Object.keys(blockErrors(props.errors, index)).length > 0

// عنصرٌ أُفلت من «العناصر»: يُختار لتعديله.
const onChange = (event) => {
    if (event.added?.element?.id) emit('added', event.added.element.id)
}
</script>
