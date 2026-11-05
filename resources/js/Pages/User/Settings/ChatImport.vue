<template>
  <SettingLayout :modules="props.modules">
    <div class="md:h-[90vh]">
      <div class="flex justify-center">
        <div class="w-full md:w-[60em] mb-20 space-y-4">

          <!-- الشرح والقالب -->
          <section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h4 class="text-[16px]">{{ $t('Import conversations') }}</h4>
                <p class="text-slate-500 mt-1 max-w-2xl">
                  {{ $t('Move your chat history from another platform. The whole file is checked first: if any row has a problem nothing is imported, and you get a report of every error to fix.') }}
                </p>
              </div>
              <a href="/settings/chat-imports/template"
                 class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-50">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                {{ $t('Download template') }}
              </a>
            </div>

            <div class="overflow-x-auto mt-4">
              <table class="w-full text-[13px]">
                <thead>
                  <tr class="bg-slate-50 text-slate-600">
                    <th class="text-start p-2 font-medium">{{ $t('Column') }}</th>
                    <th class="text-start p-2 font-medium">{{ $t('Required') }}</th>
                    <th class="text-start p-2 font-medium">{{ $t('Description') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="column in columns" :key="column.name" class="border-b border-slate-100 align-top">
                    <td class="p-2 font-mono" dir="ltr">{{ column.name }}</td>
                    <td class="p-2">{{ column.required ? $t('Yes') : $t('No') }}</td>
                    <td class="p-2 text-slate-600">{{ $t(column.description) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <ul class="mt-3 text-[13px] text-slate-500 list-disc ps-5 space-y-1">
              <li>{{ $t('CSV files saved as UTF-8 only, up to {size} MB and {rows} messages.', { size: props.limits.max_file_mb, rows: formatNumber(props.limits.max_rows) }) }}</li>
              <li>{{ $t('Media cannot be imported: placeholders like [image] stay as text in the conversation.') }}</li>
              <li>{{ $t('Uploading the same file again does not duplicate messages.') }}</li>
              <li>{{ $t('Imported conversations are added as closed tickets, so they do not fill your team inbox.') }}</li>
            </ul>
          </section>

          <!-- الرفع -->
          <section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
            <h4 class="text-[16px] mb-3">{{ $t('Upload a file') }}</h4>

            <label class="block mb-1 text-slate-600" for="default-country">{{ $t('Default country for numbers without a country code (optional)') }}</label>
            <select id="default-country" v-model="defaultCountry" :disabled="busy"
                    class="w-full md:w-96 rounded-md border border-slate-300 px-2 py-2 text-sm bg-white">
              <option value="">{{ $t('Detect automatically from the file') }}</option>
              <option v-for="country in props.countries" :key="country.code" :value="country.code">{{ country.name }}</option>
            </select>

            <div class="mt-4 flex justify-center px-6 pt-5 pb-6 border-2 border-dashed rounded-md transition-colors"
                 :class="dragging ? 'border-primary bg-slate-50' : 'border-gray-300'"
                 @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop">
              <div class="text-center">
                <svg class="mx-auto h-10 w-10 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 3v4a1 1 0 0 0 1 1h4M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2M12 11v6m-3-3l3-3l3 3"/></svg>
                <div class="flex justify-center text-gray-600 mt-2">
                  <label for="chat-import-file" class="cursor-pointer font-medium text-primary hover:underline">{{ $t('Click to upload a file') }}</label>
                  <span class="ps-1">{{ $t('Or drag and drop') }}</span>
                </div>
                <p class="text-xs text-gray-500 mt-1">{{ $t('CSV files only') }}</p>
                <input id="chat-import-file" ref="fileInput" type="file" class="sr-only" accept=".csv,.txt,text/csv" :disabled="busy" @change="onPick">
              </div>
            </div>

            <div v-if="selectedFile" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-md bg-slate-50 p-3">
              <div class="min-w-0">
                <div class="truncate font-medium" dir="auto">{{ selectedFile.name }}</div>
                <div class="text-xs text-slate-500">{{ formatBytes(selectedFile.size) }}</div>
              </div>
              <div class="flex gap-2">
                <button v-if="!uploading" type="button" :disabled="busy" @click="startUpload"
                        class="rounded-md bg-primary px-4 py-2 text-white disabled:opacity-50">{{ $t('Upload and check') }}</button>
                <button v-else type="button" @click="abortUpload"
                        class="rounded-md border border-slate-300 px-4 py-2 hover:bg-white">{{ $t('Cancel upload') }}</button>
              </div>
            </div>

            <p v-if="uploadError" class="mt-3 rounded-md bg-red-50 p-3 text-red-700" role="alert">{{ uploadError }}</p>
            <p v-if="hasActiveImport && !uploading" class="mt-3 text-[13px] text-slate-500">{{ $t('Another import is in progress. Wait for it to finish first.') }}</p>
          </section>

          <!-- الحالة الحاليّة -->
          <section v-if="current || uploading" class="bg-white border border-slate-200 rounded-lg p-4 text-sm" aria-live="polite">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="min-w-0">
                <h4 class="text-[16px] truncate" dir="auto">{{ current?.original_name ?? selectedFile?.name }}</h4>
                <div v-if="current?.created_at" class="text-xs text-slate-500">{{ formatDate(current.created_at) }}</div>
              </div>
              <span class="rounded-full px-3 py-1 text-xs font-medium" :class="badgeClass(view.status)">{{ $t(statusLabel(view.status)) }}</span>
            </div>

            <!-- الخطوات -->
            <ol v-if="view.step !== 'rollback'" class="mt-4 grid grid-cols-3 gap-2">
              <li v-for="step in STEPS" :key="step" class="flex items-center gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs"
                      :class="stepClass(stepState(step, view, !!current?.started_at))">
                  <template v-if="stepState(step, view, !!current?.started_at) === 'done'">✓</template>
                  <template v-else-if="stepState(step, view, !!current?.started_at) === 'failed'">✕</template>
                  <template v-else>{{ STEPS.indexOf(step) + 1 }}</template>
                </span>
                <span class="truncate" :class="stepState(step, view, !!current?.started_at) === 'pending' ? 'text-slate-400' : ''">{{ $t(stepLabels[step]) }}</span>
              </li>
            </ol>

            <div v-if="showBar" class="mt-4">
              <div class="flex justify-between text-xs text-slate-600 mb-1">
                <span>{{ barCaption }}</span>
                <span dir="ltr">{{ view.percent }}%</span>
              </div>
              <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="view.percent" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full transition-all duration-500" :class="view.step === 'rollback' ? 'bg-amber-500' : 'bg-primary'" :style="{ width: view.percent + '%' }"></div>
              </div>
            </div>

            <p v-if="current?.stalled" class="mt-4 rounded-md bg-amber-50 p-3 text-amber-800" role="status">
              {{ $t('No progress for a while. The import will continue automatically; if it stays like this, contact support.') }}
            </p>

            <p v-if="current?.error_message" class="mt-4 rounded-md p-3 whitespace-pre-line"
               :class="current.status === 'invalid' || current.status === 'failed' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800'" role="alert">
              {{ current.error_message }}
            </p>

            <!-- الأرقام -->
            <dl v-if="current && current.total_rows > 0" class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-2">
              <div v-for="stat in stats" :key="stat.label" class="rounded-md bg-slate-50 p-3">
                <dt class="text-xs text-slate-500">{{ $t(stat.label) }}</dt>
                <dd class="text-lg" dir="ltr">{{ formatNumber(stat.value) }}</dd>
              </div>
            </dl>

            <p v-if="current?.ignored_columns?.length" class="mt-3 text-xs text-slate-500">
              {{ $t('Ignored columns:') }} <span dir="auto">{{ current.ignored_columns.join('، ') }}</span>
            </p>

            <p v-if="usedCountry" class="mt-3 text-xs text-slate-500">
              {{ $t('Numbers without a country code were read as:') }} <span dir="auto">{{ usedCountry }}</span>
            </p>

            <!-- الأخطاء -->
            <div v-if="current?.errors?.length" class="mt-4">
              <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <h5 class="font-medium" :class="hasSkippedRows ? 'text-amber-800' : 'text-red-700'">
                  <template v-if="hasSkippedRows">{{ $t('{count} rows skipped because of invalid numbers', { count: formatNumber(current.skipped_rows) }) }}</template>
                  <template v-else>{{ $t('{count} errors found', { count: formatNumber(current.error_count) }) }}</template>
                  <span v-if="current.error_count > current.errors.length" class="font-normal text-slate-500">
                    — {{ $t('showing the first {count}', { count: current.errors.length }) }}
                  </span>
                </h5>
                <a v-if="current.has_errors_file" :href="`/settings/chat-imports/${current.uuid}/errors`"
                   class="inline-flex items-center gap-1 rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50">
                  {{ $t('Download full error report') }}
                </a>
              </div>
              <p v-if="hasSkippedRows" class="mb-2 text-xs text-slate-600">
                {{ $t('These rows were not imported. Fix the numbers and upload the file again to add them; messages already imported will not be duplicated.') }}
              </p>
              <div class="max-h-80 overflow-auto rounded-md border border-red-100">
                <table class="w-full text-[12px]">
                  <thead class="sticky top-0 bg-red-50 text-red-800">
                    <tr>
                      <th class="text-start p-2 font-medium">{{ $t('Row') }}</th>
                      <th class="text-start p-2 font-medium">{{ $t('Column') }}</th>
                      <th class="text-start p-2 font-medium">{{ $t('Value') }}</th>
                      <th class="text-start p-2 font-medium">{{ $t('Error') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(error, index) in current.errors" :key="index" class="border-t border-red-50 align-top">
                      <td class="p-2" dir="ltr">{{ error.row }}</td>
                      <td class="p-2 font-mono" dir="ltr">{{ error.column }}</td>
                      <td class="p-2 break-all" dir="auto">{{ error.value }}</td>
                      <td class="p-2">
                        {{ error.message }}
                        <div v-if="error.message_ar && error.message_ar !== error.message" class="mt-1" dir="rtl">{{ error.message_ar }}</div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- الأفعال -->
            <div v-if="current" class="mt-4 flex flex-wrap items-center gap-2">
              <button v-if="current.can_cancel" type="button" :disabled="acting" @click="act('cancel')"
                      class="rounded-md border border-slate-300 px-4 py-2 hover:bg-slate-50 disabled:opacity-50">{{ $t('Cancel import') }}</button>
              <button v-if="current.can_resume" type="button" :disabled="acting" @click="act('resume')"
                      class="rounded-md bg-primary px-4 py-2 text-white disabled:opacity-50">{{ $t('Resume import') }}</button>

              <template v-if="current.can_rollback">
                <button v-if="!confirmingRollback" type="button" :disabled="acting" @click="confirmingRollback = true"
                        class="rounded-md border border-red-300 px-4 py-2 text-red-700 hover:bg-red-50 disabled:opacity-50">{{ $t('Roll back import') }}</button>
                <div v-else class="flex flex-wrap items-center gap-2 rounded-md bg-red-50 p-2">
                  <span class="text-red-800">{{ $t('Delete the {count} messages added by this import?', { count: formatNumber(current.imported_messages - current.rolled_back_messages) }) }}</span>
                  <button type="button" :disabled="acting" @click="act('rollback')" class="rounded-md bg-red-600 px-3 py-1.5 text-white disabled:opacity-50">{{ $t('Yes, roll back') }}</button>
                  <button type="button" @click="confirmingRollback = false" class="rounded-md border border-slate-300 bg-white px-3 py-1.5">{{ $t('Keep it') }}</button>
                </div>
              </template>
            </div>
          </section>

          <!-- السجلّ -->
          <section v-if="history.length" class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
            <h4 class="text-[16px] mb-3">{{ $t('Previous imports') }}</h4>
            <div class="overflow-x-auto">
              <table class="w-full text-[13px]">
                <thead>
                  <tr class="bg-slate-50 text-slate-600">
                    <th class="text-start p-2 font-medium">{{ $t('File') }}</th>
                    <th class="text-start p-2 font-medium">{{ $t('Date') }}</th>
                    <th class="text-start p-2 font-medium">{{ $t('By') }}</th>
                    <th class="text-start p-2 font-medium">{{ $t('Messages imported') }}</th>
                    <th class="text-start p-2 font-medium">{{ $t('Status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in history" :key="item.uuid" @click="show(item)"
                      class="border-b border-slate-100 cursor-pointer hover:bg-slate-50"
                      :class="current?.uuid === item.uuid ? 'bg-slate-50' : ''">
                    <td class="p-2 max-w-[14rem] truncate" dir="auto">{{ item.original_name }}</td>
                    <td class="p-2 whitespace-nowrap">{{ formatDate(item.created_at) }}</td>
                    <td class="p-2" dir="auto">{{ item.user_name || '—' }}</td>
                    <td class="p-2" dir="ltr">{{ formatNumber(item.imported_messages - item.rolled_back_messages) }}</td>
                    <td class="p-2"><span class="rounded-full px-2 py-0.5 text-xs" :class="badgeClass(item.status)">{{ $t(statusLabel(item.status)) }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </div>
    </div>
  </SettingLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { toast } from 'vue3-toastify'
import SettingLayout from './Layout.vue'
import { useTrans } from '@/Composables/useTrans'
import { uploadInChunks } from '@/Composables/chunkedUpload.js'
import {
  POLL_MS,
  STEPS,
  formatBytes,
  isActive,
  looksLikeCsv,
  newUploadId,
  progressView,
  statusLabel,
  statusTone,
  stepState,
  transitionToast,
} from '@/Composables/chatImportProgress.js'

const props = defineProps({
  modules: { type: Array, default: () => [] },
  imports: { type: Array, default: () => [] },
  latest: { type: Object, default: null },
  countries: { type: Array, default: () => [] },
  limits: { type: Object, default: () => ({ max_file_mb: 200, max_rows: 1000000 }) },
})

const trans = useTrans()

const UPLOAD_URL = '/settings/chat-imports/upload/chunk'

const columns = [
  { name: 'phone', required: true, description: 'Customer number with country code, e.g. 966501234567.' },
  { name: 'name', required: false, description: 'Customer name. Used only for new contacts.' },
  { name: 'sent_at', required: true, description: 'Date and time, e.g. 2026-05-23 19:29:38 or 2026-05-23T19:29:38+03:00. Without a time zone your organization time zone is used.' },
  { name: 'direction', required: true, description: '"in" for messages from the customer, "out" for messages you sent.' },
  { name: 'text', required: true, description: 'Message text. Can span several lines.' },
]

const stepLabels = { upload: 'Upload', validate: 'Check', import: 'Import' }

const current = ref(props.latest)
const history = ref([...props.imports])
const selectedFile = ref(null)
const defaultCountry = ref(props.latest?.default_country ?? '')
const fileInput = ref(null)
const dragging = ref(false)
const uploading = ref(false)
const upload = ref(null)
const uploadError = ref(null)
const acting = ref(false)
const confirmingRollback = ref(false)

let uploadId = null
let abortController = null
let pollTimer = null

const hasActiveImport = computed(() => current.value && isActive(current.value.status))
const busy = computed(() => uploading.value || hasActiveImport.value)
const hasSkippedRows = computed(() => (current.value?.skipped_rows ?? 0) > 0)
const usedCountry = computed(() => {
  const code = current.value?.default_country
  return code ? (props.countries.find((country) => country.code === code)?.name ?? code) : null
})

const view = computed(() => progressView({
  upload: uploading.value ? upload.value : null,
  importState: uploading.value ? null : current.value,
}))

const showBar = computed(() => ['upload', 'validate', 'import', 'rollback'].includes(view.value.step)
  && (uploading.value || isActive(current.value?.status)))

const barCaption = computed(() => {
  const state = current.value
  switch (view.value.step) {
    case 'upload':
      return trans('Uploading {loaded} of {total}', { loaded: formatBytes(upload.value?.loaded), total: formatBytes(upload.value?.total) })
    case 'validate':
      return trans('Checking every row before importing anything…')
    case 'import':
      return state?.status === 'queued'
        ? trans('Waiting for the import to start…')
        : trans('{done} of {total} messages', { done: formatNumber(state?.processed_rows), total: formatNumber(state?.total_rows) })
    case 'rollback':
      return trans('Removed {done} of {total} messages', { done: formatNumber(state?.rolled_back_messages), total: formatNumber(state?.imported_messages) })
    default:
      return ''
  }
})

const stats = computed(() => {
  const state = current.value
  if (!state) return []

  if (state.status === 'invalid') {
    return [
      { label: 'Rows in file', value: state.total_rows },
      { label: 'Errors', value: state.error_count },
    ]
  }

  const skipped = state.skipped_rows > 0 ? [{ label: 'Rows skipped', value: state.skipped_rows }] : []

  return [
    { label: 'Rows in file', value: state.total_rows },
    { label: 'Messages imported', value: state.imported_messages - state.rolled_back_messages },
    { label: 'Duplicates skipped', value: state.duplicate_rows },
    { label: 'New contacts', value: state.started_at ? state.contacts_created : state.new_contacts_estimate },
    ...skipped,
  ]
})

function formatNumber(value) {
  return Number(value || 0).toLocaleString()
}

function formatDate(value) {
  if (!value) return ''
  return new Date(value).toLocaleString()
}

function badgeClass(status) {
  return {
    success: 'bg-green-50 text-green-800',
    error: 'bg-red-50 text-red-700',
    warning: 'bg-amber-50 text-amber-800',
    info: 'bg-sky-50 text-sky-800',
  }[statusTone(status)]
}

function stepClass(state) {
  return {
    done: 'bg-primary text-white',
    current: 'border-2 border-primary text-primary',
    failed: 'bg-red-600 text-white',
    pending: 'border border-slate-300 text-slate-400',
  }[state]
}

function choose(file) {
  uploadError.value = null
  if (!file) return

  if (!looksLikeCsv(file.name)) {
    uploadError.value = trans('Only CSV files are accepted.')
    return
  }

  if (file.size > props.limits.max_file_mb * 1024 * 1024) {
    uploadError.value = trans('The file is larger than {size} MB.', { size: props.limits.max_file_mb })
    return
  }

  selectedFile.value = file
}

function onPick(event) {
  choose(event.target.files?.[0])
  event.target.value = ''
}

function onDrop(event) {
  dragging.value = false
  if (!busy.value) choose(event.dataTransfer.files?.[0])
}

/**
 * أوضح ما في الردّ: رسالتنا، ثم أوّل خطأ حقل، ثم نصّ الاستثناء.
 *
 * «An error occurred» وحدها لا تقول شيئاً — كان الرفض الحقيقي («القطعة
 * أكبر من حدّ الخادم») مدفوناً في error ولا يظهر.
 */
function errorMessage(error, fallback) {
  const data = error?.response?.data ?? {}
  const firstFieldError = data.errors ? Object.values(data.errors).flat()[0] : null
  const generic = data.message === 'An error occurred' || data.message === trans('An error occurred')

  return (!generic && data.message) || firstFieldError || data.error || data.message || fallback
}

async function startUpload() {
  if (!selectedFile.value || busy.value) return

  uploadId = newUploadId()
  abortController = new AbortController()
  uploading.value = true
  uploadError.value = null
  confirmingRollback.value = false
  upload.value = { loaded: 0, total: selectedFile.value.size }

  try {
    const response = await uploadInChunks({
      post: axios.post,
      url: UPLOAD_URL,
      file: selectedFile.value,
      // حجم القطعة الذي يقبله هذا الخادم (upload_max_filesize قد يكون 2MB).
      chunkBytes: Number(props.limits.chunk_bytes) || undefined,
      signal: abortController.signal,
      onProgress: (loaded, total) => { upload.value = { loaded, total } },
      fields: {
        upload_id: uploadId,
        file_name: selectedFile.value.name,
        file_size: selectedFile.value.size,
        default_country: defaultCountry.value,
      },
    })

    setCurrent(response.data.import, null)
    selectedFile.value = null
    startPolling()
  } catch (error) {
    if (error?.name === 'AbortError' || axios.isCancel?.(error)) {
      discardUpload()
    } else {
      uploadError.value = errorMessage(error, trans('Upload could not be completed.'))
      toast.error(uploadError.value)
    }
  } finally {
    uploading.value = false
    upload.value = null
    abortController = null
  }
}

function abortUpload() {
  abortController?.abort()
}

function discardUpload() {
  if (uploadId) {
    axios.delete(UPLOAD_URL, { data: { upload_id: uploadId } }).catch(() => {})
  }
}

/** يحدّث البطاقة والسجلّ معاً، وينبّه إن تغيّرت الحالة. */
function setCurrent(state, previousStatus) {
  if (!state) return

  current.value = state

  const index = history.value.findIndex((item) => item.uuid === state.uuid)
  if (index === -1) {
    history.value.unshift(state)
  } else {
    history.value[index] = state
  }

  const message = transitionToast(previousStatus, state.status)
  if (message) {
    toast[message.type](trans(message.message))
  }
}

async function poll() {
  const state = current.value
  if (!state) return

  try {
    const { data } = await axios.get(`/settings/chat-imports/${state.uuid}/status`)
    if (current.value?.uuid === data.uuid) {
      setCurrent(data, state.status)
    }
  } catch (error) {
    // انقطاع عابر لا يوقف المتابعة؛ الاستيراد يعمل على الخادم أصلاً.
    if (error?.response?.status === 404) stopPolling()
  }

  if (!isActive(current.value?.status)) {
    stopPolling()
  }
}

function startPolling() {
  stopPolling()
  pollTimer = setInterval(poll, POLL_MS)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function act(action) {
  const state = current.value
  if (!state) return

  acting.value = true
  try {
    const { data } = await axios.post(`/settings/chat-imports/${state.uuid}/${action}`)
    confirmingRollback.value = false
    setCurrent(data, null)
    if (isActive(data.status)) startPolling()
  } catch (error) {
    toast.error(errorMessage(error, trans('Something went wrong. Please try again.')))
  } finally {
    acting.value = false
  }
}

async function show(item) {
  confirmingRollback.value = false
  current.value = item
  // السجلّ يُحمَّل بلا قائمة الأخطاء — نجلبها حين تُفتح.
  try {
    const { data } = await axios.get(`/settings/chat-imports/${item.uuid}/status`)
    if (current.value?.uuid === data.uuid) setCurrent(data, null)
  } catch (_) {}

  if (isActive(current.value?.status)) startPolling()
}

onMounted(() => {
  // إعادة تحميل الصفحة في منتصف الاستيراد لا تُضيّعه.
  if (isActive(current.value?.status)) startPolling()
})

onBeforeUnmount(() => {
  stopPolling()
  abortController?.abort()
})
</script>
