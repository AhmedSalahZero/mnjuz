<script setup>
/**
 * فلتر قائمة المحادثات حسب المنصّة.
 *
 * لا يظهر إلّا حين تكون المنشأة مربوطة بأكثر من قناة: فلتر بخيار واحد
 * يشغل مساحة ولا يفعل شيئاً، وشاشة المحادثات ضيّقة أصلاً على الجوال.
 *
 * التصميم شرائط أفقية قابلة للتمرير لا قائمة منسدلة: القنوات قليلة
 * (خمس على الأكثر)، والضغطة الواحدة أسرع من فتح قائمة واختيار.
 */
import { computed } from 'vue'
import ChannelIcon from '@/Components/ChannelIcon.vue'

const props = defineProps({
    /** القنوات المربوطة فعلاً في هذه المنشأة. */
    channels: { type: Array, default: () => [] },
    /** القناة المختارة حالياً، أو null لكلّها. */
    modelValue: { type: String, default: null },
})

const emit = defineEmits(['update:modelValue'])

const visible = computed(() => (props.channels?.length ?? 0) > 1)

const label = (channel) => ({
    whatsapp: 'WhatsApp',
    telegram: 'Telegram',
    messenger: 'Messenger',
    instagram: 'Instagram',
    tiktok: 'TikTok',
    webchat: 'Web Chat',
}[channel] || channel)

const select = (channel) => {
    // الضغط على القناة المختارة يُلغي الاختيار — فلا يحتاج الموظّف زرّ
    // «الكلّ» ليعود، ولا يعلق في فلتر لا يعرف كيف يخرج منه.
    emit('update:modelValue', props.modelValue === channel ? null : channel)
}
</script>

<template>
    <div v-if="visible" class="flex items-center gap-1.5 overflow-x-auto px-4 py-2 border-b scrollbar-none">
        <button type="button"
            class="shrink-0 px-3 py-1 rounded-full text-xs font-medium transition-colors border"
            :class="modelValue === null
                ? 'bg-slate-800 text-white border-slate-800'
                : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
            @click="emit('update:modelValue', null)">
            {{ $t('All') }}
        </button>

        <button v-for="channel in channels" :key="channel" type="button"
            class="shrink-0 flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-colors border"
            :class="modelValue === channel
                ? 'bg-slate-800 text-white border-slate-800'
                : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
            :aria-pressed="modelValue === channel"
            @click="select(channel)">
            <ChannelIcon :channel="channel" :size="14" :filled="modelValue === channel" />
            <span>{{ label(channel) }}</span>
        </button>
    </div>
</template>

<style scoped>
/* شريط التمرير يُخفى: الشرائط تُمرَّر بالسحب على الجوال وبالعجلة على
   سطح المكتب، وشريطٌ ظاهر يقضم من ارتفاع صفّ ضيّق أصلاً. */
.scrollbar-none {
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.scrollbar-none::-webkit-scrollbar {
    display: none;
}
</style>
