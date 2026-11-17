<template>
    <div class="space-y-4">
        <ColorField :model-value="settings.page_bg" :label="$t('Page background')" @update:model-value="set('page_bg', $event)" />
        <ColorField :model-value="settings.content_bg" :label="$t('Email background')" @update:model-value="set('content_bg', $event)" />
        <ColorField :model-value="settings.text_color" :label="$t('Text color')" @update:model-value="set('text_color', $event)" />

        <div>
            <label class="mb-1 block text-xs text-slate-600" for="email-font">{{ $t('Font') }}</label>
            <select id="email-font" :value="settings.font"
                class="w-full rounded-lg border border-slate-200 py-2 pe-8 ps-3 text-sm focus:outline-none focus:ring-1 focus:ring-slate-400"
                @change="set('font', $event.target.value)">
                <option v-for="font in FONTS" :key="font.value" :value="font.value" :style="{ fontFamily: font.stack }">{{ font.label }}</option>
            </select>
        </div>

        <AlignField :model-value="settings.direction" :label="$t('Text direction')"
            :options="[{ value: 'rtl', label: 'Right to left' }, { value: 'ltr', label: 'Left to right' }]"
            @update:model-value="set('direction', $event)" />

        <p class="text-xs text-slate-500">{{ $t('An unsubscribe link is added to every email automatically.') }}</p>
    </div>
</template>

<script setup>
/** «إعدادات البريد»: ألوان الصفحة والمحتوى والنص، والخط، والاتجاه. */
import { FONTS } from '@/Composables/emailDesign.js'
import AlignField from './Fields/AlignField.vue'
import ColorField from './Fields/ColorField.vue'

defineProps({
    settings: { type: Object, required: true },
})

const emit = defineEmits(['update'])
const set = (name, value) => emit('update', { [name]: value })
</script>
