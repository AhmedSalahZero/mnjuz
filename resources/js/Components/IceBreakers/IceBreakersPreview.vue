<template>
    <div class="flex justify-center">
        <div class="w-[280px] rounded-[2rem] border-[6px] border-slate-800 bg-slate-800 shadow-xl overflow-hidden">
            <div class="bg-[#075E54] text-white px-3 py-3 flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-slate-300 shrink-0" />
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium truncate">{{ displayName }}</div>
                    <div class="text-[10px] opacity-80 truncate">{{ displayPhone }}</div>
                </div>
                <div class="flex gap-3 opacity-80 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M17 10.5V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3.5l4 4v-11z"/></svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.01-.24c1.12.37 2.33.57 3.58.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.46.57 3.58a1 1 0 0 1-.25 1.01z"/></svg>
                </div>
            </div>

            <div
                class="h-[360px] bg-[#ECE5DD] bg-cover px-2 py-3 flex flex-col justify-end"
                style="background-image: url('data:image/svg+xml,%3Csvg width=%2260%22 height=%2260%22 viewBox=%220 0 60 60%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cg fill=%22none%22 fill-rule=%22evenodd%22%3E%3Cg fill=%22%23d4cdc4%22 fill-opacity=%220.4%22%3E%3Cpath d=%22M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"
            >
                <div v-if="visibleItems.length === 0" class="text-center text-xs text-slate-500 pb-4">
                    {{ $t('Ice breakers will appear here') }}
                </div>

                <div v-else class="space-y-1.5 pb-1">
                    <div
                        v-for="(item, index) in visibleItems"
                        :key="index"
                        class="flex items-center gap-2 bg-white rounded-lg px-3 py-2.5 shadow-sm border border-slate-100"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" class="text-[#25D366] shrink-0">
                            <path fill="currentColor" d="M8.59 16.59L13.17 12L8.59 7.41L10 6l6 6l-6 6z"/>
                        </svg>
                        <span class="text-sm text-slate-800 truncate" dir="auto">{{ item.text }}</span>
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
    props.items.filter((item) => item.text && item.text.trim().length > 0)
)
</script>
