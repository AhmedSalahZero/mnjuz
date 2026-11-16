<script setup>
/**
 * معاينة رسالة حملة أو قالب محفوظ لقناة غير واتساب (تيليجرام، البريد).
 *
 * نصّ عادي فقط — لا v-html: النصّ يكتبه التاجر وقد يأتي من حقول جهات اتصال
 * مستوردة. أسطره تبقى كما هي (whitespace-pre-line).
 */
import { computed } from 'vue'
import ChannelIcon from '@/Components/ChannelIcon.vue'

const props = defineProps({
    channel: { type: String, required: true },
    subject: { type: String, default: '' },
    text: { type: String, default: '' },
    media: { type: Object, default: null },
    label: { type: String, default: '' },
})

const isEmail = computed(() => props.channel === 'email')
const mediaName = computed(() => props.media?.name || props.media?.url || '')
const isImage = computed(() => (props.media?.type || '').toLowerCase() === 'image' && props.media?.url)
</script>

<template>
    <div class="max-w-[25em] rounded-lg bg-white border border-slate-200 shadow-sm overflow-hidden text-sm">
        <div class="flex items-center gap-2 px-3 py-2 border-b border-slate-100 bg-slate-50">
            <ChannelIcon :channel="channel" :size="16" filled />
            <span class="text-xs text-slate-500">{{ label }}</span>
        </div>

        <div v-if="isEmail && subject" class="px-3 pt-3 font-medium text-slate-800" dir="auto">
            {{ subject }}
        </div>

        <img v-if="isImage" :src="media.url" alt="" class="w-full max-h-48 object-cover" />
        <div v-else-if="mediaName" class="mx-3 mt-3 text-xs text-slate-600 bg-slate-100 rounded px-2 py-1 truncate" dir="auto">
            📎 {{ mediaName }}
        </div>

        <p class="px-3 py-3 whitespace-pre-line break-words text-slate-700" dir="auto">{{ text || '—' }}</p>

        <div v-if="isEmail" class="px-3 pb-3 text-[11px] text-slate-400">
            {{ $t('An unsubscribe link is added to every email automatically.') }}
        </div>
    </div>
</template>
