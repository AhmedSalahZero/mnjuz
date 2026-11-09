<script setup>
/**
 * محرّر بطاقات الـ carousel في صفحة إنشاء القالب.
 *
 * يعدّل المسودّة في مكانها عبر دوال carouselTemplateDraft.js المُختبرة:
 * إضافة البطاقات وحذفها، والصيغة، وقائمة أزرار واحدة لكل البطاقات (Meta
 * تشترط نفس الأزرار بنفس الترتيب).
 */
import { computed, onBeforeUnmount } from 'vue'
import FormInput from '@/Components/FormInput.vue'
import BodyTextArea from '@/Components/Template/BodyTextArea.vue'
import {
    CAROUSEL_CARD_BODY_MAX,
    CAROUSEL_ERROR_TEXT,
    CAROUSEL_MAX_BUTTONS,
    CAROUSEL_MAX_CARDS,
    CAROUSEL_MIN_CARDS,
    addCard,
    cardFileError,
    removeCard,
    setButtonTypes,
    setFormat,
} from '@/Composables/carouselTemplateDraft.js'
import { countVariables } from '@/Composables/carouselTemplate.js'

const props = defineProps({
    draft: { type: Object, required: true },
    errors: { type: Array, default: () => [] },
    maxPostBytes: { type: Number, default: null },
    maxUploadBytes: { type: Number, default: null },
})

// مفتاح ثابت لكل بطاقة: BodyTextArea يحفظ نصّه داخلياً، والمفتاح بالموضع
// كان سيُبقي نصّ البطاقة المحذوفة في التي بعدها.
const keys = new WeakMap()
let sequence = 0
const keyOf = (card) => {
    if (!keys.has(card)) keys.set(card, ++sequence)
    return keys.get(card)
}

const BUTTON_LABELS = {
    QUICK_REPLY: 'Quick reply',
    URL: 'Visit website',
    PHONE_NUMBER: 'Call phone number',
}

const availableButtonTypes = computed(() =>
    Object.keys(BUTTON_LABELS).filter(() => props.draft.buttonTypes.length < CAROUSEL_MAX_BUTTONS))

const accept = computed(() => (props.draft.format === 'VIDEO' ? 'video/mp4' : 'image/png,image/jpeg'))

const totalBytes = computed(() => props.draft.cards.reduce((sum, card) => sum + (card.file?.size ?? 0), 0))

const formatBytes = (bytes) => {
    if (!bytes) return '0 MB'
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const revoke = (url) => {
    if (url) URL.revokeObjectURL(url)
}

const changeFormat = (format) => {
    setFormat(props.draft, format).forEach(revoke)
}

const addButtonType = (type) => {
    setButtonTypes(props.draft, [...props.draft.buttonTypes, type])
}

const removeButtonType = (index) => {
    setButtonTypes(props.draft, props.draft.buttonTypes.filter((_, i) => i !== index))
}

const add = () => addCard(props.draft)

const remove = (index) => {
    const removed = removeCard(props.draft, index)
    revoke(removed?.preview)
}

const pickFile = (card, event) => {
    const file = event.target.files?.[0] ?? null
    event.target.value = ''

    if (!file) return

    revoke(card.preview)
    card.file = file
    card.preview = URL.createObjectURL(file)
}

const fileError = (card) => (card.file ? cardFileError(card.file, props.draft.format, props.maxUploadBytes) : null)

const cardErrors = (index) => [...new Set(props.errors
    .filter((error) => error.card === index + 1)
    .map((error) => CAROUSEL_ERROR_TEXT[error.code] ?? error.code))]

onBeforeUnmount(() => props.draft.cards.forEach((card) => revoke(card.preview)))
</script>

<template>
    <div class="normal-case">
        <h2 class="text-slate-600">
            {{ $t('Carousel cards') }} <span class="text-xs">({{ $t('Required') }})</span>
        </h2>
        <span class="text-slate-600 text-xs">{{
            $t('Add {min} to {max} cards. Customers scroll through them sideways, and every card has the same buttons.', { min: CAROUSEL_MIN_CARDS, max: CAROUSEL_MAX_CARDS })
        }}</span>

        <div class="grid grid-cols-2 mt-3 bg-[#f9f9fa] rounded-lg">
            <button
                v-for="format in ['IMAGE', 'VIDEO']"
                :key="format"
                type="button"
                class="p-2 text-sm rounded-lg"
                :class="draft.format === format ? 'bg-white shadow text-black' : 'text-slate-600'"
                @click="changeFormat(format)">
                {{ format === 'IMAGE' ? $t('Image') : $t('Video') }}
            </button>
        </div>

        <label class="flex items-center gap-x-2 mt-4 text-sm text-slate-700 cursor-pointer">
            <input
                v-model="draft.hasCardBody"
                type="checkbox"
                class="rounded border-gray-300" />
            {{ $t('Add text to every card') }}
        </label>

        <div class="mt-4">
            <span class="text-sm text-slate-700">{{ $t('Card buttons') }}</span>
            <span class="text-xs text-slate-500">
                ({{ $t('Up to {count}, the same on every card', { count: CAROUSEL_MAX_BUTTONS }) }})</span>
            <div class="flex flex-wrap gap-2 mt-2">
                <span
                    v-for="(type, index) in draft.buttonTypes"
                    :key="type + index"
                    class="flex items-center gap-x-1 bg-slate-100 rounded-full px-3 py-1 text-sm">
                    {{ $t(BUTTON_LABELS[type]) }}
                    <button
                        type="button"
                        class="hover:bg-slate-200 rounded-full p-0.5"
                        :aria-label="$t('Remove')"
                        @click="removeButtonType(index)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"><path fill="currentColor" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12z" /></svg>
                    </button>
                </span>
                <button
                    v-for="type in availableButtonTypes"
                    :key="'add-' + type"
                    type="button"
                    class="text-sm text-slate-700 bg-white border border-dashed border-slate-300 hover:bg-slate-50 rounded-full px-3 py-1"
                    @click="addButtonType(type)">
                    + {{ $t(BUTTON_LABELS[type]) }}
                </button>
            </div>
        </div>

        <div
            v-for="(card, index) in draft.cards"
            :key="keyOf(card)"
            class="bg-[#f9f9fa] p-3 rounded-lg mt-4">
            <div class="flex items-center justify-between pb-2 border-b">
                <span class="text-sm font-medium">{{ $t('Card {number}', { number: index + 1 }) }}</span>
                <button
                    v-if="draft.cards.length > CAROUSEL_MIN_CARDS"
                    type="button"
                    class="text-xs text-red-600 hover:underline"
                    @click="remove(index)">
                    {{ $t('Remove card') }}
                </button>
            </div>

            <div class="flex items-center gap-x-3 mt-3">
                <div class="w-20 h-14 rounded bg-[#ccd0d5] overflow-hidden flex items-center justify-center shrink-0">
                    <video
                        v-if="card.preview && draft.format === 'VIDEO'"
                        :key="card.preview"
                        :src="card.preview"
                        class="w-full h-full object-cover"
                        muted></video>
                    <img
                        v-else-if="card.preview"
                        :src="card.preview"
                        class="w-full h-full object-cover"
                        alt="" />
                </div>
                <label class="cursor-pointer text-sm text-indigo-600 hover:text-indigo-500">
                    <span>{{ card.file ? $t('Replace') : (draft.format === 'VIDEO' ? $t('Choose a video') : $t('Choose an image')) }}</span>
                    <input
                        type="file"
                        class="sr-only"
                        :accept="accept"
                        @change="pickFile(card, $event)" />
                </label>
                <span
                    v-if="card.file"
                    class="text-xs text-slate-500 truncate">{{ card.file.name }} · {{ formatBytes(card.file.size) }}</span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
                {{ draft.format === 'VIDEO' ? $t('MP4 up to 16MB') : $t('PNG or JPG up to 5MB') }}
            </p>
            <p
                v-if="fileError(card)"
                class="text-xs text-red-600 mt-1">
                {{ draft.format === 'VIDEO' ? $t('Card media must be an MP4 video of up to 16MB.') : $t('Card media must be a PNG or JPG image of up to 5MB.') }}
            </p>

            <div
                v-if="draft.hasCardBody"
                class="mt-3">
                <span class="text-sm text-slate-700">{{ $t('Card text') }}</span>
                <BodyTextArea
                    v-model="card.body.text"
                    :max-length="CAROUSEL_CARD_BODY_MAX"
                    @updateExamples="(examples) => (card.body.example = examples)" />
            </div>

            <div
                v-for="(button, buttonIndex) in card.buttons"
                :key="buttonIndex"
                class="mt-3 border-t pt-2">
                <span class="text-xs text-slate-500">{{ $t(BUTTON_LABELS[button.type]) }}</span>
                <div class="flex flex-wrap md:flex-nowrap gap-1">
                    <FormInput
                        v-model="button.text"
                        :name="$t('Button text')"
                        :type="'text'"
                        :max-length="25"
                        :class="'w-full'"
                        :labelClass="'mb-0'" />
                    <FormInput
                        v-if="button.type === 'URL'"
                        v-model="button.url"
                        :name="$t('Website url')"
                        :type="'url'"
                        :placeholder="'https://'"
                        :class="'w-full'"
                        :labelClass="'mb-0'" />
                    <FormInput
                        v-if="button.type === 'PHONE_NUMBER'"
                        v-model="button.phone_number"
                        :name="$t('Phone number')"
                        :type="'text'"
                        :placeholder="'+966500000000'"
                        :class="'w-full'"
                        :labelClass="'mb-0'" />
                </div>
                <FormInput
                    v-if="button.type === 'URL' && countVariables(button.url) > 0"
                    v-model="button.example"
                    :name="$t('Sample value for the link variable')"
                    :type="'text'"
                    :class="'w-full mt-1'"
                    :labelClass="'mb-0'" />
            </div>

            <ul
                v-if="cardErrors(index).length"
                class="mt-2 text-xs text-red-600 list-disc ps-4">
                <li
                    v-for="message in cardErrors(index)"
                    :key="message">
                    {{ $t(message) }}
                </li>
            </ul>
        </div>

        <div class="flex items-center justify-between mt-4 mb-8">
            <button
                v-if="draft.cards.length < CAROUSEL_MAX_CARDS"
                type="button"
                class="text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4"
                @click="add">
                + {{ $t('Add card') }}
            </button>
            <span
                class="text-xs"
                :class="maxPostBytes && totalBytes > maxPostBytes ? 'text-red-600' : 'text-slate-500'">
                {{ $t('Total upload') }}: {{ formatBytes(totalBytes) }}<template v-if="maxPostBytes"> / {{ formatBytes(maxPostBytes) }}</template>
            </span>
        </div>
    </div>
</template>
