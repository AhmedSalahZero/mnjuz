<template>
    <div class="flex shrink-0 items-center gap-0.5">
        <button type="button" data-action="up" class="rounded p-1 text-slate-600 hover:bg-slate-100 disabled:opacity-30"
            :disabled="first" :title="$t('Move up')" :aria-label="$t('Move up')" @click="emit('up')">
            <ChevronUp class="h-4 w-4" />
        </button>
        <button type="button" data-action="down" class="rounded p-1 text-slate-600 hover:bg-slate-100 disabled:opacity-30"
            :disabled="last" :title="$t('Move down')" :aria-label="$t('Move down')" @click="emit('down')">
            <ChevronDown class="h-4 w-4" />
        </button>
        <button type="button" data-action="duplicate" class="rounded p-1 text-slate-600 hover:bg-slate-100 disabled:opacity-30"
            :disabled="!canDuplicate" :title="$t('Duplicate')" :aria-label="$t('Duplicate')" @click="emit('duplicate')">
            <Copy class="h-4 w-4" />
        </button>
        <button type="button" data-action="remove" class="rounded p-1 text-red-600 hover:bg-red-50"
            :title="$t('Delete')" :aria-label="$t('Delete')" @click="emit('remove')">
            <Trash2 class="h-4 w-4" />
        </button>
    </div>
</template>

<script setup>
import { ChevronDown, ChevronUp, Copy, Trash2 } from 'lucide-vue-next'

defineProps({
    first: { type: Boolean, default: false },
    last: { type: Boolean, default: false },
    canDuplicate: { type: Boolean, default: true },
})

const emit = defineEmits(['up', 'down', 'duplicate', 'remove'])
</script>
