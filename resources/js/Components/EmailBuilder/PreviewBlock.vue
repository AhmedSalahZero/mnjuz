<template>
    <div v-if="visible" :data-block-id="block.id" :data-block-type="block.type"
        :class="interactive ? ['relative cursor-pointer outline-offset-[-2px]', selected ? 'outline outline-2 outline-[#4c1d95]' : 'hover:outline hover:outline-1 hover:outline-dashed hover:outline-slate-400'] : []"
        :role="interactive ? 'button' : undefined" :tabindex="interactive ? 0 : undefined"
        :aria-label="interactive ? $t(label) : undefined"
        @click="interactive && emit('select')" @keydown.enter.prevent="interactive && emit('select')">

        <div v-if="block.type === 'header'" :style="{ background: props_.bg_color, padding: '20px 24px', textAlign: align }">
            <img v-if="props_.logo" :src="props_.logo" alt="" style="display:inline-block;height:48px;width:auto;max-width:200px;">
            <br v-if="props_.logo && text">
            <span v-if="text" :style="{ fontSize: px(props_.font_size), lineHeight: 1.4, fontWeight: 'bold', color: props_.color, whiteSpace: 'pre-line' }">{{ text }}</span>
        </div>

        <div v-else-if="block.type === 'heading'" :style="{ padding: '16px 24px 8px', textAlign: align }">
            <h1 :style="{ margin: 0, fontSize: px(props_.font_size), lineHeight: 1.35, fontWeight: 'bold', color: props_.color, whiteSpace: 'pre-line' }">{{ text || emptyHint }}</h1>
        </div>

        <div v-else-if="block.type === 'text' || block.type === 'footer'"
            :style="{ padding: block.type === 'footer' ? '12px 24px' : '8px 24px', textAlign: align, fontSize: px(props_.font_size), lineHeight: block.type === 'footer' ? 1.6 : 1.7, color: props_.color, whiteSpace: 'pre-line' }">{{ text || emptyHint }}</div>

        <div v-else-if="block.type === 'image'" :style="{ padding: '8px 24px', textAlign: align }">
            <img v-if="props_.src" :src="props_.src" :alt="props_.alt" :style="{ display: 'inline-block', width: props_.width + '%', maxWidth: '100%', height: 'auto', borderRadius: '6px' }">
            <ImagePlaceholder v-else />
        </div>

        <div v-else-if="block.type === 'button'" :style="{ padding: '12px 24px', textAlign: align }">
            <span :style="{ display: 'inline-block', padding: '12px 28px', borderRadius: '8px', background: props_.bg_color, color: props_.color, fontSize: '16px', fontWeight: 'bold' }">{{ label_ || emptyHint }}</span>
        </div>

        <div v-else-if="block.type === 'image_text'" style="padding:8px 24px;">
            <div :style="{ display: 'flex', flexDirection: mobile ? 'column' : (props_.image_position === 'end' ? 'row-reverse' : 'row'), gap: '12px', alignItems: mobile ? 'stretch' : 'center' }">
                <div :style="{ width: mobile ? '100%' : '40%', flexShrink: 0 }">
                    <img v-if="props_.src" :src="props_.src" :alt="props_.alt" style="display:block;width:100%;height:auto;border-radius:6px;">
                    <ImagePlaceholder v-else-if="interactive" />
                </div>
                <div :style="{ flex: 1, textAlign: startAlign, fontSize: px(props_.font_size), lineHeight: 1.7, color: props_.color, whiteSpace: 'pre-line' }">{{ text || emptyHint }}</div>
            </div>
        </div>

        <div v-else-if="block.type === 'divider'" style="padding:8px 24px;">
            <div :style="{ borderTop: `1px solid ${props_.color}` }"></div>
        </div>

        <div v-else-if="block.type === 'spacer'" :style="{ height: px(props_.height) }"></div>

        <div v-else-if="block.type === 'social'" :style="{ padding: '12px 24px', textAlign: align }">
            <span v-for="(link, index) in socialLinks" :key="index"
                :style="{ display: 'inline-block', margin: '4px', padding: '6px 14px', borderRadius: '999px', background: props_.color, color: '#ffffff', fontSize: '13px' }">
                {{ $t(networkLabel(link.network)) }}
            </span>
            <span v-if="!socialLinks.length" class="text-xs text-slate-400">{{ $t('Add your social links') }}</span>
        </div>
    </div>
</template>

<script setup>
/**
 * عنصر واحد في المعاينة بأنماط الرسالة نفسها (الأحجام والألوان والمحاذاة
 * كما يكتبها الخادم). في وضع التحرير: قابل للنقر، والفارغ يظهر بتلميح كي
 * يُختار؛ وفي العرض فقط: الفارغ لا يظهر، كما في البريد المُرسل.
 */
import { computed, defineComponent, h } from 'vue'
import { Image as ImageIcon } from 'lucide-vue-next'
import { alignToCss, BLOCK_LABELS, NETWORK_LABELS } from '@/Composables/emailDesign.js'
import { previewText } from '@/Composables/channelMessagePreview.js'

const props = defineProps({
    block: { type: Object, required: true },
    direction: { type: String, default: 'rtl' },
    samples: { type: Object, default: () => ({}) },
    selected: { type: Boolean, default: false },
    interactive: { type: Boolean, default: false },
    mobile: { type: Boolean, default: false },
    emptyText: { type: String, default: '' },
})

const emit = defineEmits(['select'])

const props_ = computed(() => props.block.props ?? {})
const label = computed(() => BLOCK_LABELS[props.block.type] ?? props.block.type)
const align = computed(() => alignToCss(props_.value.align ?? 'start', props.direction))
const startAlign = computed(() => alignToCss('start', props.direction))
const px = (value) => `${Number(value) || 0}px`

const text = computed(() => previewText(props_.value.text ?? '', props.samples).trim())
const label_ = computed(() => previewText(props_.value.label ?? '', props.samples).trim())
const socialLinks = computed(() => (props_.value.links ?? []).filter((link) => String(link.url ?? '').trim() !== ''))
const networkLabel = (network) => NETWORK_LABELS[network] ?? network

// تلميح العنصر الفارغ في التحرير فقط.
const emptyHint = computed(() => (props.interactive ? props.emptyText : ''))

// ما لا يظهر في البريد المُرسل لا يظهر في العرض فقط.
const visible = computed(() => {
    if (props.interactive) return true

    switch (props.block.type) {
        case 'header': return Boolean(text.value || props_.value.logo)
        case 'heading':
        case 'text':
        case 'footer': return Boolean(text.value)
        case 'image': return Boolean(props_.value.src)
        case 'button': return Boolean(label_.value)
        case 'image_text': return Boolean(text.value || props_.value.src)
        case 'social': return socialLinks.value.length > 0
        default: return true
    }
})

const ImagePlaceholder = defineComponent({
    name: 'ImagePlaceholder',
    setup: () => () => h('div', {
        class: 'flex h-32 w-full items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 text-slate-400',
    }, [h(ImageIcon, { class: 'h-8 w-8', 'aria-hidden': 'true' })]),
})
</script>
