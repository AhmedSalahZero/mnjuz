<script setup>
/**
 * بطاقات الـ carousel في نموذج الإرسال (الحملة والمحادثة).
 *
 * لكل بطاقة: وسائطها — نسختنا المحفوظة مع القالب أو ملف يُرفع الآن — وقيم
 * متغيّرات نصّها وأزرارها. نصوص البطاقات وتسميات الأزرار من القالب نفسه،
 * فلا تُحرَّر هنا.
 *
 * الحالة من buildCarouselCardParameters وتُعدَّل في مكانها.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import {
    CAROUSEL_MEDIA_LIMITS,
    cardMediaSource,
    carouselUploadBytes,
} from '@/Composables/carouselTemplate.js'

const props = defineProps({
    state: { type: Object, required: true },
    definition: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    variableOptions: { type: Array, default: () => [] },
    dynamicOptions: { type: Array, default: () => [] },
})

const localErrors = ref({})
const isVideo = computed(() => props.state.format === 'VIDEO')
const accept = computed(() => (isVideo.value ? 'video/mp4' : 'image/png,image/jpeg'))
const maxPostBytes = computed(() => Number(usePage().props.max_post_bytes) || null)
const totalBytes = computed(() => carouselUploadBytes(props.state))

const formatBytes = (bytes) => `${((bytes || 0) / (1024 * 1024)).toFixed(1)} MB`

const revoke = (header) => {
    if (header?.preview) {
        URL.revokeObjectURL(header.preview)
        header.preview = null
    }
}

const useTemplateMedia = (card) => {
    revoke(card.header)
    card.header.selection = 'default'
    card.header.value = null
}

const pickFile = (card, index, event) => {
    const file = event.target.files?.[0] ?? null
    event.target.value = ''

    if (!file) return

    const limit = CAROUSEL_MEDIA_LIMITS[props.state.format] ?? CAROUSEL_MEDIA_LIMITS.IMAGE
    if (file.size > limit) {
        localErrors.value = { ...localErrors.value, [index]: true }
        return
    }

    localErrors.value = { ...localErrors.value, [index]: false }

    // createObjectURL لا FileReader: المعاينة فورية ولا يُضخَّم الطلب.
    revoke(card.header)
    card.header.selection = 'upload'
    card.header.value = file
    card.header.preview = URL.createObjectURL(file)
}

const errorFor = (key) => props.errors?.[key] ?? null

onBeforeUnmount(() => props.state.cards.forEach((card) => revoke(card.header)))
</script>

<template>
    <div class="bg-slate-100 p-3 mt-1 text-sm">
        <h2 class="pb-2 border-b">{{ $t('Carousel cards') }}</h2>
        <p
            v-if="errorFor('carousel')"
            class="text-[#b91c1c] text-xs mt-2">
            {{ errorFor('carousel') }}
        </p>

        <div
            v-for="(card, index) in state.cards"
            :key="index"
            class="mt-3 bg-slate-50 p-3 rounded">
            <div class="flex items-center justify-between">
                <span class="font-medium">{{ $t('Card {number}', { number: index + 1 }) }}</span>
                <span
                    v-if="definition.cards[index]?.body"
                    class="text-xs text-slate-500 truncate max-w-[60%] normal-case"
                    dir="auto">{{ definition.cards[index].body.text }}</span>
            </div>

            <div class="flex items-center gap-x-3 mt-2">
                <div class="w-20 h-14 rounded bg-[#ccd0d5] overflow-hidden flex items-center justify-center shrink-0">
                    <template v-if="cardMediaSource(card.header)">
                        <video
                            v-if="isVideo"
                            :key="cardMediaSource(card.header)"
                            :src="cardMediaSource(card.header)"
                            class="w-full h-full object-cover"
                            muted
                            preload="metadata"></video>
                        <img
                            v-else
                            :src="cardMediaSource(card.header)"
                            class="w-full h-full object-cover"
                            alt="" />
                    </template>
                </div>
                <div class="flex flex-col gap-y-1">
                    <button
                        v-if="card.header.hasDefault"
                        type="button"
                        class="text-start text-xs"
                        :class="card.header.selection === 'default' ? 'text-indigo-700 font-medium' : 'text-slate-600 hover:text-indigo-600'"
                        @click="useTemplateMedia(card)">
                        {{ card.header.selection === 'default' ? '✓ ' : '' }}{{ $t('Use the template media') }}
                    </button>
                    <label class="cursor-pointer text-xs text-indigo-600 hover:text-indigo-500">
                        <span>{{ card.header.selection === 'upload' && card.header.value ? '✓ ' + card.header.value.name : (isVideo ? $t('Upload a video') : $t('Upload an image')) }}</span>
                        <input
                            type="file"
                            class="sr-only"
                            :accept="accept"
                            @change="pickFile(card, index, $event)" />
                    </label>
                </div>
            </div>
            <p
                v-if="!card.header.hasDefault"
                class="text-xs text-slate-500 mt-1">
                {{ $t('This template came from WhatsApp Manager, so its card media must be uploaded here.') }}
            </p>
            <p
                v-if="localErrors[index]"
                class="text-[#b91c1c] text-xs mt-1">
                {{ isVideo ? $t('Card media must be an MP4 video of up to 16MB.') : $t('Card media must be a PNG or JPG image of up to 5MB.') }}
            </p>
            <p
                v-if="errorFor('carousel.cards.' + index + '.header.value')"
                class="text-[#b91c1c] text-xs mt-1">
                {{ errorFor('carousel.cards.' + index + '.header.value') }}
            </p>

            <div
                v-for="(parameter, k) in card.body.parameters"
                :key="'body-' + k"
                class="flex items-center space-x-4 mt-2">
                <div class="w-[15%] text-xs">{{ '{' + '{' + (k + 1) + '}' + '}' }}</div>
                <div class="w-full">
                    <FormSelect
                        v-model="parameter.selection"
                        :options="variableOptions"
                        :name="$t('Type')"
                        :class="'sm:col-span-6'" />
                </div>
                <div class="w-full">
                    <FormInput
                        v-if="parameter.selection === 'static'"
                        v-model="parameter.value"
                        :name="$t('Value')"
                        :required="true"
                        :error="errorFor('carousel.cards.' + index + '.body.parameters.' + k + '.value')"
                        :type="'text'"
                        :class="'sm:col-span-6'" />
                    <FormSelect
                        v-else
                        v-model="parameter.value"
                        :name="$t('Value')"
                        :required="true"
                        :error="errorFor('carousel.cards.' + index + '.body.parameters.' + k + '.value')"
                        :options="dynamicOptions"
                        :class="'sm:col-span-6'" />
                </div>
            </div>

            <template
                v-for="(button, j) in card.buttons"
                :key="'button-' + j">
                <div
                    v-if="button.parameters.length > 0"
                    class="mt-2">
                    <span class="text-xs text-slate-600">{{ $t('Label') }}: {{ button.text }}</span>
                    <FormInput
                        v-model="button.parameters[0].value"
                        :name="button.type === 'URL' ? $t('Link value') : $t('Reply payload (optional)')"
                        :required="button.type === 'URL'"
                        :error="errorFor('carousel.cards.' + index + '.buttons.' + j + '.parameters.0.value')"
                        :type="'text'"
                        :class="'sm:col-span-6'" />
                </div>
            </template>
        </div>

        <p
            v-if="maxPostBytes && totalBytes > maxPostBytes"
            class="text-[#b91c1c] text-xs mt-2">
            {{ $t('The files together are larger than one upload allows. Use smaller files.') }}
            ({{ formatBytes(totalBytes) }} / {{ formatBytes(maxPostBytes) }})
        </p>
    </div>
</template>
