import { computed, reactive } from 'vue'

/**
 * طابور رفع المرفقات — خارج دورة حياة المكوّن عمداً.
 *
 * كان الرفع داخل ChatForm ينتظر بـawait والنافذة مفتوحة فوق الشاشة: الموظّف
 * محبوس حتى يرتفع آخر بايت، لا يفتح محادثة أخرى ولا يردّ على عميل ينتظر.
 * وملفٌّ من عشرة ميغابايت على شبكة متوسّطة يعني دقيقة كاملة من التعطّل.
 *
 * والحالة داخل المكوّن تموت بموته: أي انتقال يُلغي الرفع الجاري. فالمخزن هنا
 * على مستوى الوحدة — يعيش ما دامت الصفحة مفتوحة، والمكوّنات تقرؤه ولا تملكه.
 *
 * الفقاعات التفاؤلية تبقى مسؤولية المكوّن: هو وحده يعرف أي محادثة معروضة.
 * المخزن يُبلّغه بالفشل عبر onFailure ليزيلها.
 */

let nextId = 1

/**
 * @typedef {object} UploadJob
 * @property {number} id
 * @property {string} contactUuid
 * @property {string} contactName
 * @property {string[]} fileNames
 * @property {number} loaded
 * @property {number} total
 * @property {'uploading'|'failed'} state
 * @property {?string} error
 */

export function createUploadQueue({ post, onError } = {}) {
    const state = reactive({ jobs: [] })

    /**
     * الطلبات الأصلية خارج التفاعلية عمداً.
     *
     * حفظها على المهمّة يلفّ كائنات File بوكيل Proxy، وFormData لا تقبل وكيلاً:
     * تُرسل "[object Object]" مكان الملف. لا يظهر إلا عند إعادة المحاولة —
     * وهي أسوأ لحظة لعطلٍ جديد.
     */
    const requests = new Map()

    /** المتحكّمات كذلك: abort() على وكيل سلوكٌ لا نراهن عليه. */
    const controllers = new Map()

    const find = (id) => state.jobs.find((job) => job.id === id)

    const remove = (id) => {
        const index = state.jobs.findIndex((job) => job.id === id)
        if (index !== -1) state.jobs.splice(index, 1)
        requests.delete(id)
        controllers.delete(id)
    }

    /**
     * بدء الرفع. يعود فوراً بمعرّف المهمّة — لا ينتظر شيئاً، وهذا كلّ الغرض.
     *
     * @param {{contactUuid: string, contactName?: string, files: Array<{file: File, type: string}>,
     *          caption?: string, tempIds: string[], onFailure?: (tempIds: string[]) => void}} request
     * @returns {number} معرّف المهمّة
     */
    const enqueue = (request) => {
        const job = reactive({
            id: nextId++,
            contactUuid: request.contactUuid,
            contactName: request.contactName ?? '',
            fileNames: request.files.map((item) => item.file?.name ?? ''),
            tempIds: [...request.tempIds],
            caption: request.caption ?? '',
            loaded: 0,
            // مجموع الأحجام تقديرٌ أوّليّ حتى يصل أوّل تقرير تقدّم من الشبكة.
            total: request.files.reduce((sum, item) => sum + (item.file?.size ?? 0), 0),
            state: 'uploading',
            error: null,
        })

        state.jobs.push(job)
        start(job, request)

        return job.id
    }

    const start = (job, request) => {
        job.state = 'uploading'
        job.error = null
        job.loaded = 0

        const controller = typeof AbortController === 'function' ? new AbortController() : null
        controllers.set(job.id, controller)
        requests.set(job.id, request)

        const formData = buildFormData(request, job)

        Promise.resolve(
            post('/chats', formData, {
                signal: controller?.signal,
                onUploadProgress: (event) => {
                    if (job.state !== 'uploading') return
                    job.loaded = event.loaded ?? 0
                    if (event.total) job.total = event.total
                },
            })
        )
            .then(() => {
                // النجاح يُزيل المهمّة: الرسالة صارت في المحادثة، وإبقاء سطر
                // «اكتمل» يحوّل المؤشّر إلى أرشيف يحتاج تنظيفاً يدوياً.
                remove(job.id)
            })
            .catch((error) => {
                if (isAborted(error)) {
                    remove(job.id)
                    request.onFailure?.(job.tempIds)

                    return
                }

                job.state = 'failed'
                job.loaded = 0
                job.error = readErrorMessage(error)
                // الفقاعات التفاؤلية تُزال فوراً: إبقاؤها يوهم أن الملف وصل.
                request.onFailure?.(job.tempIds)
                onError?.(job.error)
            })
    }

    /** الطلب الأصلي بملفاته غير الملفوفة — لإعادة المحاولة وإعادة بناء الفقاعات. */
    const requestFor = (id) => requests.get(id) ?? null

    const buildFormData = (request, job) => {
        const formData = new FormData()
        formData.append('uuid', request.contactUuid)

        if (job.caption !== '') formData.append('message', job.caption)

        request.files.forEach((item, index) => {
            formData.append('files[]', item.file)
            formData.append('types[]', item.type)
            formData.append('tempMessageIds[]', job.tempIds[index])
        })

        return formData
    }

    /** إعادة محاولة مهمّة أخفقت. المعرّفات المؤقّتة تُجدَّد لأن القديمة أُزيلت. */
    const retry = (id, tempIds) => {
        const job = find(id)
        if (!job || job.state !== 'failed') return false

        if (Array.isArray(tempIds) && tempIds.length === job.tempIds.length) {
            job.tempIds = [...tempIds]
        }

        const request = requests.get(job.id)
        if (!request) return false

        start(job, request)

        return true
    }

    const cancel = (id) => {
        const job = find(id)
        if (!job) return false

        const controller = controllers.get(job.id)

        if (job.state === 'uploading' && controller) {
            controller.abort()

            return true
        }

        const request = requests.get(job.id)
        remove(job.id)
        request?.onFailure?.(job.tempIds)

        return true
    }

    const dismiss = (id) => {
        const job = find(id)
        if (!job || job.state === 'uploading') return false

        remove(id)

        return true
    }

    const jobs = computed(() => state.jobs)
    const uploading = computed(() => state.jobs.filter((job) => job.state === 'uploading'))
    const failed = computed(() => state.jobs.filter((job) => job.state === 'failed'))
    const isBusy = computed(() => uploading.value.length > 0)

    /** عدد الملفات في الرفع الجاري — أوضح للمستخدم من عدد المهامّ. */
    const fileCount = computed(() =>
        uploading.value.reduce((sum, job) => sum + job.fileNames.length, 0)
    )

    /**
     * النسبة الإجمالية موزونة بالبايتات لا بعدد المهامّ: مهمّة صغيرة اكتملت
     * لا تقفز بالمؤشّر إلى النصف بينما الملف الكبير في أوّله.
     */
    const percent = computed(() => {
        const active = uploading.value
        if (active.length === 0) return 0

        const total = active.reduce((sum, job) => sum + (job.total || 0), 0)
        if (total <= 0) return 0

        const loaded = active.reduce((sum, job) => sum + Math.min(job.loaded, job.total || 0), 0)

        return Math.min(100, Math.round((loaded / total) * 100))
    })

    return { jobs, uploading, failed, isBusy, fileCount, percent, enqueue, retry, cancel, dismiss, requestFor, jobPercent }
}

/** نسبة مهمّة واحدة. */
export function jobPercent(job) {
    if (!job || !job.total) return 0

    return Math.min(100, Math.round((job.loaded / job.total) * 100))
}

function isAborted(error) {
    return error?.name === 'CanceledError'
        || error?.name === 'AbortError'
        || error?.code === 'ERR_CANCELED'
        || error?.__CANCEL__ === true
}

function readErrorMessage(error) {
    return error?.response?.data?.message
        ?? error?.message
        ?? 'تعذّر رفع الملفات.'
}

/**
 * النسخة المشتركة التي يستعملها التطبيق.
 *
 * واحدة لا أكثر: مؤشّران يقرآن طابورين مختلفين يعرضان حقيقتين مختلفتين.
 */
let shared = null

export function useUploadQueue() {
    if (!shared) {
        throw new Error('طابور الرفع لم يُهيّأ بعد — نادِ initUploadQueue أولاً.')
    }

    return shared
}

/** يُنادى مرّة عند الإقلاع بحاقن الشبكة (axios). */
export function initUploadQueue(options) {
    if (!shared) {
        shared = createUploadQueue(options)
    }

    return shared
}
