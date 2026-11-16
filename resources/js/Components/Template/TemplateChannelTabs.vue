<script setup>
/**
 * تبويبات صفحة القوالب: واتساب (قوالب Meta بموافقتها، الصفحات القائمة كما
 * هي)، وتيليجرام والبريد (قوالب محفوظة بلا موافقة)، وSMS قريباً.
 */
import { Link } from '@inertiajs/vue3'
import ChannelIcon from '@/Components/ChannelIcon.vue'

defineProps({
    active: { type: String, required: true },
})

const tabs = [
    { channel: 'whatsapp', label: 'WhatsApp', href: '/templates' },
    { channel: 'telegram', label: 'Telegram', href: '/templates/channel/telegram' },
    { channel: 'email', label: 'Email', href: '/templates/channel/email' },
    { channel: 'sms', label: 'SMS', href: null },
]
</script>

<template>
    <div class="flex gap-1.5 overflow-x-auto pb-2 mb-4 normal-case">
        <template v-for="tab in tabs" :key="tab.channel">
            <Link v-if="tab.href" :href="tab.href"
                class="shrink-0 flex items-center gap-2 px-3 py-2 rounded-lg border text-sm transition-colors"
                :class="active === tab.channel
                    ? 'bg-slate-800 text-white border-slate-800'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'">
                <ChannelIcon :channel="tab.channel" :size="18" :filled="active === tab.channel" />
                <span>{{ $t(tab.label) }}</span>
            </Link>
            <span v-else
                class="shrink-0 flex items-center gap-2 px-3 py-2 rounded-lg border text-sm bg-slate-50 text-slate-400 border-slate-200 cursor-not-allowed">
                <ChannelIcon :channel="tab.channel" :size="18" />
                <span>{{ $t(tab.label) }}</span>
                <span class="text-[11px]">· {{ $t('Coming soon') }}</span>
            </span>
        </template>
    </div>
</template>
