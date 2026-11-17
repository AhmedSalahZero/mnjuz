<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-auto">
            <div class="flex justify-between gap-3 flex-wrap mt-8 md:mt-0 mb-4">
                <div>
                    <h2 class="text-xl mb-1">
                        {{ template ? $t('Edit template') : $t('Create {channel} template', { channel: channelLabel }) }}
                    </h2>
                    <p class="text-sm leading-6 text-gray-600">{{ $t('Ready to use — no approval needed') }}</p>
                </div>
                <div class="flex items-center">
                    <Link :href="`/templates/channel/${channel}`" class="rounded-md bg-black px-3 py-2 text-sm text-white">{{ $t('Back') }}</Link>
                </div>
            </div>

            <!-- البريد: الـ builder (عناصر، ترتيب، خصائص، إعدادات، معاينة). -->
            <form v-if="isEmail" @submit.prevent="save" class="space-y-4 text-sm">
                <div class="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm mb-1" for="template-name">{{ $t('Template name') }}</label>
                        <input id="template-name" v-model="form.name" type="text" maxlength="100" dir="auto"
                            class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                        <p v-if="form.errors.name" class="text-xs text-red-600 mt-1">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm mb-1" for="template-subject">{{ $t('Email subject') }}</label>
                        <input id="template-subject" v-model="form.subject" type="text" :maxlength="rules.subject_max || 200" dir="auto"
                            class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                        <p v-if="form.errors.subject" class="text-xs text-red-600 mt-1">{{ form.errors.subject }}</p>
                    </div>
                </div>

                <EmailBuilder v-model="form.design" :subject="form.subject" :placeholders="placeholders"
                    :organization-name="organizationName" :errors="form.errors" :open-presets="!template" :direction="direction" />

                <div class="flex justify-end gap-2">
                    <Link :href="`/templates/channel/${channel}`" class="rounded-md px-3 py-2 text-sm text-gray-600 bg-slate-200">{{ $t('Cancel') }}</Link>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-md px-3 py-2 text-sm text-white bg-primary disabled:opacity-50">
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>

            <div v-else class="md:flex gap-6">
                <form @submit.prevent="save" class="md:w-[55%] bg-white border border-slate-200 rounded-lg p-4 space-y-4 text-sm">
                    <div>
                        <label class="block text-sm mb-1">{{ $t('Template name') }}</label>
                        <input v-model="form.name" type="text" maxlength="100" dir="auto"
                            class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                        <p v-if="form.errors.name" class="text-xs text-red-600 mt-1">{{ form.errors.name }}</p>
                    </div>

                    <div v-if="isEmail">
                        <label class="block text-sm mb-1">{{ $t('Subject') }}</label>
                        <input v-model="form.subject" type="text" :maxlength="rules.subject_max || 200" dir="auto"
                            class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                        <p v-if="form.errors.subject" class="text-xs text-red-600 mt-1">{{ form.errors.subject }}</p>
                    </div>

                    <div>
                        <label class="block text-sm mb-1">{{ $t('Body') }}</label>
                        <textarea ref="bodyField" v-model="form.body" rows="8" dir="auto" :maxlength="textLimit || undefined"
                            class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400"></textarea>
                        <div class="flex justify-between gap-2">
                            <p v-if="form.errors.body" class="text-xs text-red-600 mt-1">{{ form.errors.body }}</p>
                            <p v-if="textLimit" class="text-xs text-slate-400 mt-1 ms-auto">
                                {{ $t('{count} / {max} characters', { count: (form.body || '').length, max: textLimit }) }}
                            </p>
                        </div>
                    </div>

                    <!-- المتغيّرات: تُدرج عند المؤشّر -->
                    <div>
                        <div class="text-xs text-slate-500 mb-1">{{ $t('Variables') }}</div>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="placeholder in shownPlaceholders" :key="placeholder.value" type="button"
                                class="text-xs px-2 py-1 rounded-md border border-slate-200 hover:bg-slate-50"
                                @click="insertPlaceholder(placeholder.value)">
                                {{ placeholder.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="acceptsMedia(rules)">
                        <label class="block text-xs text-slate-600 mb-1">{{ $t('Attachment (optional)') }}</label>
                        <div v-if="existingMedia && !form.remove_media && !form.media" class="flex items-center gap-2 mb-2 text-xs">
                            <span class="bg-slate-100 rounded px-2 py-1 truncate max-w-[16em]" dir="auto">📎 {{ existingMedia.name || existingMedia.url }}</span>
                            <button type="button" class="text-red-600 underline" @click="form.remove_media = true">{{ $t('Remove') }}</button>
                        </div>
                        <input type="file" :accept="mediaAccept(rules) || undefined" @change="onFile" class="text-xs" />
                        <p v-if="fileError" class="text-xs text-red-600 mt-1">{{ fileError }}</p>
                        <p v-if="form.errors.media" class="text-xs text-red-600 mt-1">{{ form.errors.media }}</p>
                        <p v-if="rules.caption_max" class="text-xs text-slate-400 mt-1">
                            {{ $t('With an attachment Telegram allows up to {max} characters.', { max: rules.caption_max }) }}
                        </p>
                    </div>

                    <p v-if="isEmail" class="text-xs text-slate-500">{{ $t('An unsubscribe link is added to every email automatically.') }}</p>

                    <div class="flex justify-end gap-2">
                        <Link :href="`/templates/channel/${channel}`" class="rounded-md px-3 py-2 text-sm text-gray-600 bg-slate-200">{{ $t('Cancel') }}</Link>
                        <button type="submit" :disabled="form.processing"
                            class="rounded-md px-3 py-2 text-sm text-white bg-primary disabled:opacity-50">
                            {{ $t('Save') }}
                        </button>
                    </div>
                </form>

                <div class="md:w-[45%] mt-6 md:mt-0 flex justify-center items-start py-8 chat-bg rounded-lg">
                    <ChannelMessagePreview :channel="channel" :label="channelLabel"
                        :subject="form.subject" :text="previewText(form.body, samples)" :media="previewMedia" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
/**
 * إنشاء قالب محفوظ أو تعديله لقناة بلا موافقة. الحدود من الخادم
 * (CampaignChannels::rules)، والمعاينة نصّ عادي بقيم نموذجية.
 */
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layout/App.vue'
import ChannelMessagePreview from '@/Components/ChannelMessagePreview.vue'
import EmailBuilder from '@/Components/EmailBuilder/EmailBuilder.vue'
import { insertAtCursor } from '@/Composables/insertAtCursor.js'
import { useRtl } from '@/Composables/useRtl.js'
import { acceptsMedia, fileProblem, mediaAccept, messageLimit } from '@/Composables/campaignChannels.js'
import { previewText, SAMPLE_VALUES } from '@/Composables/channelMessagePreview.js'
import { useTrans } from '@/Composables/useTrans'

const props = defineProps({
    channel: { type: String, required: true },
    channelLabel: { type: String, default: '' },
    rules: { type: Object, default: () => ({}) },
    placeholders: { type: Array, default: () => [] },
    template: { type: Object, default: null },
    organizationName: { type: String, default: '' },
})

const trans = useTrans()
const isEmail = computed(() => props.channel === 'email')
const existingMedia = computed(() => props.template?.media?.url ? props.template.media : null)

const form = useForm({
    name: props.template?.name ?? '',
    subject: props.template?.subject ?? '',
    body: props.template?.body ?? '',
    media: null,
    remove_media: false,
    // البريد: تصميم الـ builder (الخادم يولّد منه النسخة النصّية).
    design: props.template?.design ?? null,
})

// اتجاه قالب جديد من لغة الواجهة.
const { isRtl } = useRtl()
const direction = computed(() => (isRtl.value === false ? 'ltr' : 'rtl'))

const hasMedia = computed(() => Boolean(form.media) || (Boolean(existingMedia.value) && !form.remove_media))
const textLimit = computed(() => messageLimit(props.rules, hasMedia.value))
const samples = SAMPLE_VALUES
// ملف لم يُرفع بعد: اسمه فقط (لا رابط بعد).
const previewMedia = computed(() => (form.media
    ? { name: form.media.name }
    : (form.remove_media ? null : existingMedia.value)))

// المتغيّرات المرمّزة للروابط لا معنى لها هنا إلا في رابط؛ نُبقيها في الآخر.
const shownPlaceholders = computed(() => props.placeholders.filter((p) => !String(p.value).startsWith('{url:')).slice(0, 20))

const fileError = ref(null)
const bodyField = ref(null)

const onFile = (event) => {
    const file = event.target.files?.[0] || null
    const problem = fileProblem(props.rules, file)

    fileError.value = problem ? trans(problem.key).replace('{limit}', problem.params?.limit ?? '') : null
    form.media = problem ? null : file

    if (problem) event.target.value = ''
}

const insertPlaceholder = (token) => {
    form.body = insertAtCursor(form.body, token, bodyField.value).text
}

const save = () => {
    const url = props.template
        ? `/templates/channel/${props.channel}/${props.template.uuid}`
        : `/templates/channel/${props.channel}`

    // البريد بلا ملفات: JSON، والتصميم كما هو (مصفوفات متداخلة).
    if (isEmail.value) {
        form.transform((data) => ({ name: data.name, subject: data.subject, design: data.design }))
            .post(url, { preserveScroll: true })
        return
    }

    // تيليجرام: الطلب كما كان (لا design).
    form.transform(({ design, ...data }) => ({
        ...data,
        // البريد بلا مرفقات؛ والحقل الفارغ لا يُرسل ملفاً.
        media: isEmail.value ? null : data.media,
        remove_media: data.remove_media ? 1 : 0,
    })).post(url, { forceFormData: true, preserveScroll: true })
}
</script>
