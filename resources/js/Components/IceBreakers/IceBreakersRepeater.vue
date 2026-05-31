<template>
    <div class="space-y-3">
        <draggable
            tag="div"
            :list="localItems"
            class="space-y-3"
            handle=".ice-breaker-handle"
            item-key="uid"
            @end="syncToParent"
        >
            <template #item="{ element, index }">
                <div class="flex items-center gap-2 border border-slate-200 rounded-lg p-2 bg-white">
                    <button
                        type="button"
                        class="ice-breaker-handle shrink-0 cursor-grab text-slate-400 hover:text-slate-600 p-1"
                        :aria-label="$t('Drag to reorder')"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M9 19.23q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36" />
                        </svg>
                    </button>

                    <div class="flex-1 min-w-0">
                        <label class="block text-xs text-slate-500 mb-1">{{ $t('Ice breaker') }} {{ index + 1 }}</label>
                        <div class="relative">
                            <input
                                v-model="element.text"
                                type="text"
                                maxlength="80"
                                dir="auto"
                                class="w-full rounded-md border border-gray-300 py-2 ps-3 pe-14 text-sm focus:border-primary focus:ring-primary"
                                :placeholder="$t('Enter ice breaker text')"
                                @input="onTextInput(index)"
                            />
                            <span class="absolute inset-y-0 end-3 flex items-center text-xs text-slate-400 pointer-events-none">
                                {{ (element.text || '').length }}/80
                            </span>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 text-slate-400 hover:text-red-600 p-1 rounded-full"
                        :aria-label="$t('Remove')"
                        @click="removeAt(index)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
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
            {{ $t('Add ice breaker') }}
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
        default: 4,
    },
})

const emit = defineEmits(['update:modelValue'])

let uidCounter = 0

const withUid = (item) => ({
    id: item.id ?? null,
    text: item.text ?? '',
    sort_order: item.sort_order ?? 0,
    uid: item.uid ?? `ib-${++uidCounter}`,
})

const localItems = ref(props.modelValue.map(withUid))

const syncToParent = () => {
    emit(
        'update:modelValue',
        localItems.value.map((item, index) => ({
            id: item.id ?? null,
            text: item.text ?? '',
            sort_order: index,
        }))
    )
}

const onTextInput = (index) => {
    localItems.value[index].text = (localItems.value[index].text || '').slice(0, 80)
    syncToParent()
}

const addItem = () => {
    if (localItems.value.length >= props.max) {
        return
    }
    localItems.value.push(withUid({ id: null, text: '', sort_order: localItems.value.length }))
    syncToParent()
}

const removeAt = (index) => {
    localItems.value.splice(index, 1)
    syncToParent()
}
</script>
