<template>
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-800">{{ $t('Live preview') }}</h3>
                <p v-if="interactive" class="text-xs text-slate-500">{{ $t('Click any element in the preview to edit it.') }}</p>
            </div>
            <div class="inline-flex rounded-md border border-slate-200 p-0.5" role="radiogroup" :aria-label="$t('Preview size')">
                <button type="button" role="radio" :aria-checked="device === 'desktop'" data-device="desktop"
                    class="flex items-center gap-1 rounded px-3 py-1 text-xs"
                    :class="device === 'desktop' ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-50'"
                    @click="device = 'desktop'">
                    <Monitor class="h-3.5 w-3.5" aria-hidden="true" /> {{ $t('Desktop') }}
                </button>
                <button type="button" role="radio" :aria-checked="device === 'mobile'" data-device="mobile"
                    class="flex items-center gap-1 rounded px-3 py-1 text-xs"
                    :class="device === 'mobile' ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-50'"
                    @click="device = 'mobile'">
                    <Smartphone class="h-3.5 w-3.5" aria-hidden="true" /> {{ $t('Mobile') }}
                </button>
            </div>
        </div>

        <div class="p-4" :style="{ background: settings.page_bg }">
            <div class="mx-auto transition-all" data-preview-frame :style="{ maxWidth: device === 'mobile' ? '375px' : '600px' }">
                <div class="mb-3 rounded-md bg-white px-3 py-2 shadow-sm">
                    <div class="text-[11px] text-slate-500">{{ $t('Email subject') }}</div>
                    <div dir="auto" class="text-sm font-medium text-slate-800">{{ subjectPreview || '—' }}</div>
                </div>

                <div :dir="settings.direction" class="overflow-hidden rounded-lg shadow-sm"
                    :style="{ background: settings.content_bg, color: settings.text_color, fontFamily: fontStack(settings.font) }">
                    <div v-if="!blocks.length" class="px-6 py-12 text-center text-sm text-slate-400">
                        {{ $t('Add elements from the list to build your email.') }}
                    </div>

                    <PreviewBlock v-for="block in blocks" :key="block.id" :block="block"
                        :direction="settings.direction" :samples="samples" :mobile="device === 'mobile'"
                        :interactive="interactive" :selected="block.id === selectedId"
                        :empty-text="$t('Empty element — click to edit')"
                        @select="emit('select', block.id)" />

                    <!-- تذييل كل رسالة: يضيفه الخادم دائماً ولا يُحذف. -->
                    <div class="flex items-center justify-center gap-1 px-6 py-4 text-center text-xs"
                        style="border-top:1px solid #e5e7eb;color:#6b7280;" :title="$t('Added to every email automatically')">
                        <Lock v-if="interactive" class="h-3 w-3 shrink-0" aria-hidden="true" />
                        <span>{{ $t('You received this email from {name}.', { name: senderName }) }} <span class="underline">{{ $t('Unsubscribe') }}</span></span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
/**
 * المعاينة المباشرة لتصميم البريد: حاسوب/جوال، وعنوان البريد، والعناصر
 * بقيم نموذجية للمتغيّرات (واسم المنشأة الفعلي). في التحرير: النقر على
 * عنصر يختاره. وتُستعمل للعرض فقط في صفحتي الحملة.
 */
import { computed, ref } from 'vue'
import { Lock, Monitor, Smartphone } from 'lucide-vue-next'
import PreviewBlock from './PreviewBlock.vue'
import { defaultSettings, fontStack } from '@/Composables/emailDesign.js'
import { previewText, SAMPLE_VALUES } from '@/Composables/channelMessagePreview.js'

const props = defineProps({
    design: { type: Object, default: null },
    subject: { type: String, default: '' },
    organizationName: { type: String, default: '' },
    selectedId: { type: String, default: null },
    interactive: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const device = ref('desktop')

const settings = computed(() => ({ ...defaultSettings(), ...(props.design?.settings ?? {}) }))
const blocks = computed(() => props.design?.blocks ?? [])

const samples = computed(() => ({
    ...SAMPLE_VALUES,
    organization_name: props.organizationName || SAMPLE_VALUES.organization_name,
}))

const senderName = computed(() => samples.value.organization_name)
const subjectPreview = computed(() => previewText(props.subject, samples.value))
</script>
