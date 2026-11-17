<template>
    <Modal :label="$t('Choose from the image library')" :isOpen="isOpen" :closeBtn="true" max-width="max-w-4xl" @close="close">
        <div dir="auto" class="mt-2 space-y-3 text-start">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[12rem] flex-1">
                    <Search class="pointer-events-none absolute top-2.5 h-4 w-4 text-slate-400 start-3" aria-hidden="true" />
                    <input v-model="search" type="search" data-library-search :placeholder="$t('Search by image name…')"
                        class="w-full rounded-lg border border-slate-200 py-2 pe-3 ps-9 text-sm focus:outline-none focus:ring-1 focus:ring-slate-400" />
                </div>
                <button type="button" class="flex items-center gap-1 rounded-md bg-primary px-3 py-2 text-sm text-white disabled:opacity-50"
                    :disabled="uploading" @click="fileInput?.click()">
                    <Plus class="h-4 w-4" aria-hidden="true" /> {{ $t('Upload images') }}
                </button>
                <input ref="fileInput" type="file" accept="image/png,image/jpeg" multiple class="hidden" data-library-file @change="onFiles($event.target.files)" />
            </div>
            <p class="text-xs text-slate-500">{{ $t('JPG or PNG images up to 5 MB each.') }}</p>

            <div data-library-dropzone
                class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-5 text-center transition"
                :class="dragging ? 'border-primary bg-slate-50' : 'border-slate-200'"
                @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop">
                <Upload class="mb-1 h-6 w-6 text-slate-400" aria-hidden="true" />
                <div class="text-sm font-medium text-slate-700">{{ $t('Drag and drop images here') }}</div>
                <div class="text-xs text-slate-500">{{ $t('or click Upload images') }}</div>
                <div v-if="uploading" class="mt-2 w-48">
                    <div class="h-1.5 overflow-hidden rounded bg-slate-200"><div class="h-full bg-primary transition-all" :style="{ width: progress + '%' }"></div></div>
                </div>
            </div>
            <p v-if="error" class="text-xs text-red-600" data-library-error>{{ error }}</p>

            <div class="max-h-[22rem] overflow-y-auto">
                <div v-if="loading && !items.length" class="py-10 text-center text-sm text-slate-500">{{ $t('Loading...') }}</div>
                <div v-else-if="!items.length" class="py-10 text-center text-sm text-slate-500">
                    {{ search ? $t('No images match your search.') : $t('No images yet. Upload your first image.') }}
                </div>
                <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    <button v-for="item in items" :key="item.uuid" type="button" :data-library-item="item.uuid"
                        class="group relative overflow-hidden rounded-lg border text-start focus:outline-none focus:ring-2 focus:ring-primary"
                        :class="selected?.uuid === item.uuid ? 'border-primary ring-2 ring-primary' : 'border-slate-200'"
                        :aria-pressed="selected?.uuid === item.uuid"
                        @click="selected = item" @dblclick="selected = item; confirm()">
                        <div class="aspect-[4/3] bg-slate-100">
                            <img :src="item.path" :alt="item.name" loading="lazy" class="h-full w-full object-cover" />
                        </div>
                        <div class="truncate px-2 py-1.5 text-xs text-slate-600" dir="ltr">{{ item.name }}</div>
                        <span class="absolute top-2 flex h-5 w-5 items-center justify-center rounded border bg-white end-2"
                            :class="selected?.uuid === item.uuid ? 'border-primary bg-primary text-white' : 'border-slate-300'">
                            <Check v-if="selected?.uuid === item.uuid" class="h-3.5 w-3.5" aria-hidden="true" />
                        </span>
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                <span class="truncate text-xs text-slate-500" dir="auto">
                    {{ selected ? selected.name : $t('No image selected yet.') }}
                </span>
                <div class="flex gap-2">
                    <button type="button" class="rounded-md bg-slate-200 px-3 py-2 text-sm text-gray-700" @click="close">{{ $t('Cancel') }}</button>
                    <button type="button" data-library-confirm class="flex items-center gap-1 rounded-md bg-primary px-3 py-2 text-sm text-white disabled:opacity-50"
                        :disabled="!selected" @click="confirm">
                        <Check class="h-4 w-4" aria-hidden="true" /> {{ $t('Confirm selection') }}
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
/**
 * «اختيار من مكتبة الصور»: صور المنشأة كلّها (الملفات المستعملة سابقاً في
 * الحملات)، بحث بالاسم، ورفع بالسحب أو بالزرّ. الاختيار يُرسل رابط الصورة.
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import axios from 'axios'
import { Check, Plus, Search, Upload } from 'lucide-vue-next'
import Modal from '@/Components/Modal.vue'
import { useTrans } from '@/Composables/useTrans'

const props = defineProps({
    isOpen: { type: Boolean, default: false },
})

const emit = defineEmits(['pick', 'close'])
const trans = useTrans()

const MAX_BYTES = 5 * 1024 * 1024
const TYPES = ['image/jpeg', 'image/png']

const items = ref([])
const loading = ref(false)
const search = ref('')
const selected = ref(null)
const uploading = ref(false)
const progress = ref(0)
const error = ref('')
const dragging = ref(false)
const fileInput = ref(null)

let searchTimer = null
let requestId = 0

const load = async () => {
    const current = ++requestId
    loading.value = true

    try {
        const { data } = await axios.get('/campaigns/media-history', { params: { type: 'IMAGE', search: search.value || undefined } })

        // ردّ بحثٍ أقدم وصل بعد الأحدث لا يُظهر نتائجه.
        if (current === requestId) items.value = data?.data ?? []
    } catch {
        if (current === requestId) error.value = trans('Could not load the images. Try again.')
    } finally {
        if (current === requestId) loading.value = false
    }
}

watch(() => props.isOpen, (open) => {
    if (open) {
        selected.value = null
        error.value = ''
        load()
    }
}, { immediate: true })

watch(search, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(load, 300)
})

onBeforeUnmount(() => clearTimeout(searchTimer))

const uploadError = (exception) => {
    const status = exception?.response?.status

    if (status === 413) return trans('The image is too large. The limit is 5 MB.')

    return exception?.response?.data?.errors?.image?.[0] || exception?.response?.data?.message || trans('Upload failed. Try again.')
}

const upload = async (file) => {
    if (!TYPES.includes(file.type)) {
        error.value = trans('Upload a JPG or PNG image.')
        return
    }

    if (file.size > MAX_BYTES) {
        error.value = trans('The image is too large. The limit is 5 MB.')
        return
    }

    const body = new FormData()
    body.append('image', file)
    progress.value = 0

    try {
        const { data } = await axios.post('/templates/channel/email/images', body, {
            onUploadProgress: (event) => {
                progress.value = event.total ? Math.round((event.loaded / event.total) * 100) : 0
            },
        })

        const item = data?.data

        if (item) {
            items.value = [item, ...items.value.filter((existing) => existing.uuid !== item.uuid)]
            selected.value = item
        }
    } catch (exception) {
        error.value = uploadError(exception)
    }
}

const onFiles = async (files) => {
    const list = Array.from(files ?? [])

    if (!list.length) return

    error.value = ''
    uploading.value = true

    try {
        for (const file of list) {
            await upload(file)
        }
    } finally {
        uploading.value = false
        if (fileInput.value) fileInput.value.value = ''
    }
}

const onDrop = (event) => {
    dragging.value = false
    onFiles(event.dataTransfer?.files)
}

const close = () => emit('close')

const confirm = () => {
    if (!selected.value) return

    emit('pick', selected.value.path)
}
</script>
