<template>
    <div v-if="block" class="space-y-4" :data-properties-for="block.id">
        <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                <component :is="BLOCK_ICONS[block.type]" class="h-4 w-4" aria-hidden="true" />
                {{ $t(BLOCK_LABELS[block.type]) }}
            </div>
            <BlockActions :first="first" :last="last" :can-duplicate="!full"
                @up="emit('up')" @down="emit('down')" @duplicate="emit('duplicate')" @remove="emit('remove')" />
        </div>

        <!-- النصوص -->
        <PlaceholderTextarea v-if="has('text')" :model-value="p.text" :label="$t('Text')" :placeholders="placeholders"
            :rows="textRows" :maxlength="textMax" :error="errors.text" @update:model-value="set('text', $event)" />

        <PlaceholderTextarea v-if="has('label')" :model-value="p.label" :label="$t('Button text')" :placeholders="placeholders"
            :multiline="false" :maxlength="60" :error="errors.label" @update:model-value="set('label', $event)" />

        <!-- الصور -->
        <ImageField v-if="has('src')" :model-value="p.src" :label="$t('Image')" :error="errors.src"
            @update:model-value="set('src', $event)" @pick="emit('pick-image', 'src')" />
        <ImageField v-if="has('logo')" :model-value="p.logo" :label="$t('Logo (optional)')" :error="errors.logo"
            @update:model-value="set('logo', $event)" @pick="emit('pick-image', 'logo')" />

        <div v-if="has('alt')">
            <label class="mb-1 block text-xs text-slate-600">{{ $t('Image description') }}</label>
            <input type="text" :value="p.alt" maxlength="200" dir="auto"
                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-slate-400"
                @input="set('alt', $event.target.value)" />
            <p class="mt-1 text-[11px] text-slate-500">{{ $t('Shown when the email app blocks images.') }}</p>
        </div>

        <!-- الروابط -->
        <PlaceholderTextarea v-if="has('url')" :model-value="p.url" :label="$t('Link')" :placeholders="placeholders" url-tokens
            :multiline="false" dir="ltr" placeholder="https://" :error="errors.url" @update:model-value="set('url', $event)" />
        <PlaceholderTextarea v-if="has('link')" :model-value="p.link" :label="$t('Link (optional)')" :placeholders="placeholders" url-tokens
            :multiline="false" dir="ltr" placeholder="https://" :error="errors.link" @update:model-value="set('link', $event)" />

        <!-- روابط التواصل -->
        <div v-if="has('links')">
            <div class="mb-1 text-xs text-slate-600">{{ $t('Links') }}</div>
            <div class="space-y-2">
                <div v-for="(link, index) in p.links" :key="index" class="flex items-center gap-2" :data-social-row="index">
                    <select :value="link.network" :aria-label="$t('Network')"
                        class="rounded-lg border border-slate-200 py-2 pe-8 ps-2 text-xs focus:outline-none focus:ring-1 focus:ring-slate-400"
                        @change="setLink(index, { network: $event.target.value })">
                        <option v-for="network in SOCIAL_NETWORKS" :key="network" :value="network">{{ $t(NETWORK_LABELS[network]) }}</option>
                    </select>
                    <input type="url" :value="link.url" dir="ltr" placeholder="https://" :aria-label="$t('Link')"
                        class="min-w-0 flex-1 rounded-lg border border-slate-200 px-2 py-2 text-xs focus:outline-none focus:ring-1 focus:ring-slate-400"
                        @input="setLink(index, { url: $event.target.value })" />
                    <button type="button" class="rounded p-1 text-red-600 hover:bg-red-50" :aria-label="$t('Delete')" @click="removeLink(index)">
                        <Trash2 class="h-4 w-4" />
                    </button>
                </div>
            </div>
            <button v-if="(p.links || []).length < 10" type="button" data-add-link
                class="mt-2 rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50" @click="addLink">
                + {{ $t('Add link') }}
            </button>
            <p v-if="errors.links" class="mt-1 text-xs text-red-600">{{ errors.links }}</p>
        </div>

        <!-- الشكل -->
        <AlignField v-if="has('image_position')" :model-value="p.image_position" :label="$t('Image position')"
            :options="[{ value: 'start', label: 'Image first' }, { value: 'end', label: 'Image last' }]"
            @update:model-value="set('image_position', $event)" />

        <RangeField v-if="has('font_size')" :model-value="p.font_size" :label="$t('Font size')"
            :min="fontRange[0]" :max="fontRange[1]" :error="errors.font_size" @update:model-value="set('font_size', $event)" />
        <RangeField v-if="has('width')" :model-value="p.width" :label="$t('Width')" :min="20" :max="100" unit="%"
            :error="errors.width" @update:model-value="set('width', $event)" />
        <RangeField v-if="has('height')" :model-value="p.height" :label="$t('Height')" :min="8" :max="96"
            :error="errors.height" @update:model-value="set('height', $event)" />

        <ColorField v-if="has('bg_color')" :model-value="p.bg_color" :label="$t('Background color')" :error="errors.bg_color"
            @update:model-value="set('bg_color', $event)" />
        <ColorField v-if="has('color')" :model-value="p.color" :label="colorLabel" :error="errors.color"
            @update:model-value="set('color', $event)" />

        <AlignField v-if="has('align')" :model-value="p.align" :label="$t('Alignment')" @update:model-value="set('align', $event)" />
    </div>

    <div v-else class="py-10 text-center text-sm text-slate-500">
        {{ $t('Choose an element from the email order or the preview to edit it.') }}
    </div>
</template>

<script setup>
/**
 * «خصائص العنصر» للعنصر المحدد. كل تغيير يُرسل جزءه (patch) فيطبّقه
 * الـ builder — لا تعديل على الـ props هنا.
 */
import { computed } from 'vue'
import { Trash2 } from 'lucide-vue-next'
import { BLOCK_LABELS, NETWORK_LABELS, SOCIAL_NETWORKS } from '@/Composables/emailDesign.js'
import BlockActions from './BlockActions.vue'
import { BLOCK_ICONS } from './blockIcons.js'
import AlignField from './Fields/AlignField.vue'
import ColorField from './Fields/ColorField.vue'
import ImageField from './Fields/ImageField.vue'
import PlaceholderTextarea from './Fields/PlaceholderTextarea.vue'
import RangeField from './Fields/RangeField.vue'
import { useTrans } from '@/Composables/useTrans'

const props = defineProps({
    block: { type: Object, default: null },
    placeholders: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    first: { type: Boolean, default: false },
    last: { type: Boolean, default: false },
    full: { type: Boolean, default: false },
})

const emit = defineEmits(['update', 'up', 'down', 'duplicate', 'remove', 'pick-image'])
const trans = useTrans()

const p = computed(() => props.block?.props ?? {})
const has = (name) => Object.prototype.hasOwnProperty.call(p.value, name)
const set = (name, value) => emit('update', { [name]: value })

// حدود كل نوع كما في الخادم (EmailDesign::SCHEMA).
const FONT_RANGES = { header: [14, 40], heading: [16, 48], text: [12, 28], image_text: [12, 28], footer: [11, 18] }
const TEXT_MAX = { header: 200, heading: 200, text: 3000, image_text: 3000, footer: 1000 }
const TEXT_ROWS = { header: 2, heading: 2, text: 6, image_text: 5, footer: 3 }

const fontRange = computed(() => FONT_RANGES[props.block?.type] ?? [12, 28])
const textMax = computed(() => TEXT_MAX[props.block?.type] ?? 3000)
const textRows = computed(() => TEXT_ROWS[props.block?.type] ?? 4)

const colorLabel = computed(() => {
    if (props.block?.type === 'divider') return trans('Line color')
    if (props.block?.type === 'social') return trans('Button color')

    return trans('Text color')
})

const setLink = (index, patch) => {
    const links = (p.value.links ?? []).map((link, i) => (i === index ? { ...link, ...patch } : link))
    set('links', links)
}

const addLink = () => set('links', [...(p.value.links ?? []), { network: 'instagram', url: '' }])
const removeLink = (index) => set('links', (p.value.links ?? []).filter((_, i) => i !== index))
</script>
