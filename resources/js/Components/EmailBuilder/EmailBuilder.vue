<template>
    <div class="grid items-start gap-4 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="flex flex-wrap items-center gap-1 border-b border-slate-100 px-2" role="tablist">
                <button v-for="item in tabs" :key="item.key" type="button" role="tab" :data-tab="item.key"
                    :aria-selected="tab === item.key"
                    class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-3 text-sm"
                    :class="tab === item.key ? 'border-[#4c1d95] font-medium text-[#4c1d95]' : 'border-transparent text-slate-600 hover:text-slate-800'"
                    @click="tab = item.key">
                    <component :is="item.icon" class="h-4 w-4" aria-hidden="true" />
                    {{ $t(item.label) }}
                </button>
                <button type="button" data-open-presets
                    class="my-2 ms-auto flex items-center gap-1 rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
                    @click="showPresets = true">
                    <WandSparkles class="h-4 w-4" aria-hidden="true" /> {{ $t('Start from a ready template') }}
                </button>
            </div>

            <div class="p-4">
                <p v-if="generalError" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700" data-design-error>{{ generalError }}</p>

                <template v-if="tab === 'elements'">
                    <BlockPalette :full="full" @add="addBlock" />
                    <p v-if="full" class="mt-2 text-xs text-amber-700">{{ $t('An email can have at most {max} elements.', { max: MAX_BLOCKS }) }}</p>
                    <BlockList class="mt-6" :blocks="design.blocks" :selected-id="selectedId" :placeholders="placeholders"
                        :errors="errors" :full="full"
                        @select="edit" @added="selectedId = $event"
                        @move="move" @duplicate="duplicate" @remove="remove" @reorder="design.blocks = $event" />
                </template>

                <BlockProperties v-else-if="tab === 'properties'" :block="selectedBlock" :placeholders="placeholders"
                    :errors="selectedErrors" :first="selectedIndex === 0" :last="selectedIndex === design.blocks.length - 1" :full="full"
                    @update="updateSelected" @up="move(selectedIndex, -1)" @down="move(selectedIndex, 1)"
                    @duplicate="duplicate(selectedIndex)" @remove="remove(selectedIndex)" @pick-image="openLibrary" />

                <DesignSettings v-else :settings="design.settings" @update="design.settings = { ...design.settings, ...$event }" />
            </div>
        </section>

        <EmailPreview class="lg:sticky lg:top-4" :design="design" :subject="subject" :organization-name="organizationName"
            :selected-id="selectedId" interactive @select="edit" />

        <PresetGallery :is-open="showPresets" @choose="choosePreset" @close="showPresets = false" />
        <ImageLibraryModal :is-open="libraryField !== null" @pick="pickImage" @close="libraryField = null" />
        <AlertModal v-model="confirmReplace" :label="$t('Replace the current design?')"
            :description="$t('The elements you added will be replaced by the ready template.')"
            :confirm-button-text="$t('Replace')" @confirm="applyPendingPreset" />
    </div>
</template>

<script setup>
/**
 * builder قالب البريد: «العناصر» و«ترتيب البريد»، «خصائص العنصر»،
 * «إعدادات البريد»، والمعاينة المباشرة، والقوالب الجاهزة، ومكتبة الصور.
 *
 * يملك نسخته من التصميم ويُرسلها كاملة مع كل تغيير (v-model)؛ المكوّنات
 * الفرعية تُرسل التغيير ولا تعدّل ما يصلها.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { Palette, Plus, SlidersHorizontal, WandSparkles } from 'lucide-vue-next'
import AlertModal from '@/Components/AlertModal.vue'
import {
    blockErrors, createBlock, defaultSettings, duplicateBlock, hasContent, insertBlockAfter, MAX_BLOCKS, moveBlock, newBlockId,
} from '@/Composables/emailDesign.js'
import { presetDesign } from '@/Composables/emailPresets.js'
import { useTrans } from '@/Composables/useTrans'
import BlockList from './BlockList.vue'
import BlockPalette from './BlockPalette.vue'
import BlockProperties from './BlockProperties.vue'
import DesignSettings from './DesignSettings.vue'
import EmailPreview from './EmailPreview.vue'
import ImageLibraryModal from './ImageLibraryModal.vue'
import PresetGallery from './PresetGallery.vue'

const props = defineProps({
    modelValue: { type: Object, default: null },
    subject: { type: String, default: '' },
    placeholders: { type: Array, default: () => [] },
    organizationName: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
    // قالب جديد: يُفتح معرض القوالب الجاهزة أوّلاً.
    openPresets: { type: Boolean, default: false },
    direction: { type: String, default: 'rtl' },
})

const emit = defineEmits(['update:modelValue'])
const trans = useTrans()

const clone = (value) => JSON.parse(JSON.stringify(value))

const initial = (value) => ({
    version: 1,
    settings: { ...defaultSettings(props.direction), ...(value?.settings ?? {}) },
    blocks: (value?.blocks ?? []).map((block) => ({
        id: block.id || newBlockId(),
        type: block.type,
        props: clone(block.props ?? {}),
    })),
})

const design = ref(initial(props.modelValue))
watch(design, (value) => emit('update:modelValue', clone(value)), { deep: true })

const tabs = [
    { key: 'elements', label: 'Elements', icon: Plus },
    { key: 'properties', label: 'Element properties', icon: SlidersHorizontal },
    { key: 'settings', label: 'Email settings', icon: Palette },
]

const tab = ref('elements')
const selectedId = ref(null)
const showPresets = ref(false)
const libraryField = ref(null)
const confirmReplace = ref(false)
const pendingPreset = ref(null)

const full = computed(() => design.value.blocks.length >= MAX_BLOCKS)
const selectedIndex = computed(() => design.value.blocks.findIndex((block) => block.id === selectedId.value))
const selectedBlock = computed(() => design.value.blocks[selectedIndex.value] ?? null)
const selectedErrors = computed(() => (selectedIndex.value === -1 ? {} : blockErrors(props.errors, selectedIndex.value)))
const generalError = computed(() => props.errors?.['design.blocks'] || props.errors?.design || '')

onMounted(() => {
    if (props.openPresets && !design.value.blocks.length) {
        showPresets.value = true
    }
})

const edit = (id) => {
    selectedId.value = id
    tab.value = 'properties'
}

const addBlock = (type) => {
    if (full.value) return

    const block = createBlock(type, trans)
    design.value.blocks = insertBlockAfter(design.value.blocks, selectedId.value, block)
    selectedId.value = block.id
}

const updateSelected = (patch) => {
    const block = selectedBlock.value

    if (block) block.props = { ...block.props, ...patch }
}

const move = (index, delta) => {
    design.value.blocks = moveBlock(design.value.blocks, index, delta)
}

const duplicate = (index) => {
    const source = design.value.blocks[index]

    if (!source || full.value) return

    const copy = duplicateBlock(source)
    const blocks = [...design.value.blocks]
    blocks.splice(index + 1, 0, copy)
    design.value.blocks = blocks
    selectedId.value = copy.id
}

const remove = (index) => {
    const blocks = [...design.value.blocks]
    const [removed] = blocks.splice(index, 1)
    design.value.blocks = blocks

    if (removed?.id === selectedId.value) {
        selectedId.value = blocks[Math.min(index, blocks.length - 1)]?.id ?? null
    }
}

const applyPreset = (key) => {
    const preset = presetDesign(key, trans, design.value.settings.direction)

    if (!preset) return

    design.value = { ...preset, settings: { ...design.value.settings } }
    selectedId.value = null
    tab.value = 'elements'
}

// قالب جاهز فوق تصميم فيه محتوى: تأكيد قبل الاستبدال.
const choosePreset = (key) => {
    showPresets.value = false

    if (hasContent(design.value)) {
        pendingPreset.value = key
        confirmReplace.value = true
        return
    }

    applyPreset(key)
}

const applyPendingPreset = () => {
    confirmReplace.value = false

    if (pendingPreset.value) applyPreset(pendingPreset.value)

    pendingPreset.value = null
}

const openLibrary = (field) => {
    libraryField.value = field
}

const pickImage = (url) => {
    if (libraryField.value) updateSelected({ [libraryField.value]: url })

    libraryField.value = null
}
</script>
