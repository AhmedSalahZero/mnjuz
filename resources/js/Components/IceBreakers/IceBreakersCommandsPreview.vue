<template>
    <div class="flex justify-center">
        <div class="w-[280px] rounded-[2rem] border-[6px] border-slate-800 bg-slate-800 shadow-xl overflow-hidden">
            <div class="bg-[#075E54] text-white px-3 py-3 flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-slate-300 shrink-0" />
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium truncate">{{ displayName }}</div>
                    <div class="text-[10px] opacity-80 truncate">{{ displayPhone }}</div>
                </div>
            </div>

            <div class="h-[360px] bg-[#ECE5DD] px-3 py-4 flex flex-col">
                <div v-if="visibleItems.length === 0" class="flex-1 flex items-center justify-center text-xs text-slate-500 text-center px-4">
                    {{ $t('Commands will appear in the chat menu') }}
                </div>

                <div v-else class="space-y-2 mt-auto">
                    <div
                        v-for="(item, index) in visibleItems"
                        :key="index"
                        class="bg-white rounded-lg px-3 py-2.5 shadow-sm border border-slate-100"
                    >
                        <div class="text-sm font-medium text-[#075E54]" dir="ltr">/{{ item.command_name }}</div>
                        <div class="text-xs text-slate-600 mt-0.5 truncate" dir="auto">{{ item.command_description }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-[#F0F0F0] px-2 py-2 flex items-center gap-2">
                <div class="flex-1 bg-white rounded-full px-3 py-2 text-xs text-slate-400">{{ $t('Type a message') }}</div>
                <div class="w-8 h-8 rounded-full bg-[#075E54] flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="white" d="M2.01 21L23 12L2.01 3L2 10l15 2l-15 2z"/></svg>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    displayName: {
        type: String,
        default: 'Business',
    },
    displayPhone: {
        type: String,
        default: '',
    },
})

const visibleItems = computed(() =>
    props.items.filter(
        (item) => item.command_name?.trim() && item.command_description?.trim()
    )
)
</script>
