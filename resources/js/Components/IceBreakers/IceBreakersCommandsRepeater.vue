<template>
    <div class="space-y-3">
        <draggable
            tag="div"
            :list="localItems"
            class="space-y-3"
            handle=".command-handle"
            item-key="uid"
            @end="syncToParent"
        >
            <template #item="{ element, index }">
                <div class="border border-slate-200 rounded-lg p-3 bg-white space-y-3">
                    <div class="flex items-start gap-2">
                        <button
                            type="button"
                            class="command-handle shrink-0 cursor-grab text-slate-400 hover:text-slate-600 p-1 mt-6"
                            :aria-label="$t('Drag to reorder')"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M9 19.23q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36" />
                            </svg>
                        </button>

                        <div class="flex-1 space-y-3 min-w-0">
                            <div>
                                <label class="block text-xs text-slate-500 mb-1">{{ $t('Command') }} {{ index + 1 }}</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 start-3 flex items-center text-sm text-slate-400">/</span>
                                    <input
                                        v-model="element.command_name"
                                        type="text"
                                    maxlength="32"
                                    dir="ltr"
                                    class="w-full rounded-md border border-gray-300 py-2 ps-7 pe-14 text-sm focus:border-primary focus:ring-primary"
                                    :placeholder="$t('Command name')"
                                    @input="onNameInput(index)"
                                />
                                    <span class="absolute inset-y-0 end-3 flex items-center text-xs text-slate-400 pointer-events-none">
                                        {{ (element.command_name || '').length }}/32
                                    </span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs text-slate-500 mb-1">{{ $t('Description') }}</label>
                                <div class="relative">
                                    <input
                                        v-model="element.command_description"
                                        type="text"
                                        maxlength="256"
                                        dir="auto"
                                        class="w-full rounded-md border border-gray-300 py-2 ps-3 pe-16 text-sm focus:border-primary focus:ring-primary"
                                        :placeholder="$t('Command description')"
                                        @input="onDescriptionInput(index)"
                                    />
                                    <span class="absolute inset-y-0 end-3 flex items-center text-xs text-slate-400 pointer-events-none">
                                        {{ (element.command_description || '').length }}/256
                                    </span>
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="shrink-0 text-slate-400 hover:text-red-600 p-1 rounded-full mt-6"
                            :aria-label="$t('Remove')"
                            @click="removeAt(index)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </template>
        </draggable>

        <button
            type="button"
            class="flex items-center gap-1 text-sm text-primary hover:opacity-80 disabled:opacity-40 disabled:cursor-not-allowed"
            :disabled="localItems.length >= max"
            @click="addItem"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 5v14M5 12h14" />
            </svg>
            {{ $t('Add command') }}
        </button>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import draggable from 'vuedraggable'

const props = defineProps({
    modelValue: {
        type: Array,
        required: true,
    },
    max: {
        type: Number,
        default: 30,
    },
})

const emit = defineEmits(['update:modelValue'])

let uidCounter = 0

const withUid = (item) => ({
    id: item.id ?? null,
    command_name: item.command_name ?? '',
    command_description: item.command_description ?? '',
    sort_order: item.sort_order ?? 0,
    uid: item.uid ?? `cmd-${++uidCounter}`,
})

const localItems = ref(props.modelValue.map(withUid))

const syncToParent = () => {
    emit(
        'update:modelValue',
        localItems.value.map((item, index) => ({
            id: item.id ?? null,
            command_name: item.command_name ?? '',
            command_description: item.command_description ?? '',
            sort_order: index,
        }))
    )
}

const sanitizeName = (value) => value.replace(/[^a-zA-Z0-9_]/g, '').slice(0, 32)

const onNameInput = (index) => {
    localItems.value[index].command_name = sanitizeName(localItems.value[index].command_name || '')
    syncToParent()
}

const onDescriptionInput = (index) => {
    localItems.value[index].command_description = (localItems.value[index].command_description || '').slice(0, 256)
    syncToParent()
}

const addItem = () => {
    if (localItems.value.length >= props.max) {
        return
    }
    localItems.value.push(withUid({
        id: null,
        command_name: '',
        command_description: '',
        sort_order: localItems.value.length,
    }))
    syncToParent()
}

const removeAt = (index) => {
    localItems.value.splice(index, 1)
    syncToParent()
}
</script>
