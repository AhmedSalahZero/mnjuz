import { computed, reactive } from 'vue'
import { CHUNK_BYTES, uploadInChunks } from './chunkedUpload.js'

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

/** معرّف رفع: حروف وأرقام فقط — الخادم يبني منه مساراً. */
function randomId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    return 'u' + Math.abs(Date.now()).toString(36) + Math.floor(Math.random() * 1e9).toString(36)
}

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
            state: 'pending',
            error: null,
            // معرّف ثابت للرفع المجزّأ: القطع تُجمَّع تحته على الخادم.
            uploadId: randomId(),
            /** ما أُرسل فعلاً — لا تُزال فقاعاته عند إخفاق ما بعده. */
            sentTempIds: [],
        })

        state.jobs.push(job)
        requests.set(job.id, request)
        pump()

        return job.id
    }

    /**
     * تشغيل مهمّة واحدة في كل مرّة.
     *
     * التوازي لا يُسرِّع شيئاً — الملفات تتقاسم نفس عرض النطاق — ويُضيّع
     * الترتيب فتصل الملفات مقلوبة. والأهمّ أنه يجعل «الإلغاء قبل الرفع»
     * مستحيلاً: ما بدأ فعلاً لا يُلغى إلّا بقطع اتصال قائم.
     */
    const pump = () => {
        if (state.jobs.some((job) => job.state === 'uploading')) {
            return
        }

        const next = state.jobs.find((job) => job.state === 'pending')

        if (next) {
            start(next, requests.get(next.id))

            return
        }

        // لا جاري ولا منتظِر: انتهت الجولة، فتُطوى المكتملة ويختفي المؤشّر.
        // إبقاؤها يحوّله إلى أرشيف يحتاج تنظيفاً يدوياً.
        state.jobs
            .filter((job) => job.state === 'done')
            .forEach((job) => remove(job.id))
    }

    /** المعرّفات التي لم تُرسَل بعد — إزالة فقاعة رسالة وصلت تُخفي الحقيقة. */
    const pendingTempIds = (job) =>
        job.tempIds.filter((id) => !job.sentTempIds.includes(id))

    const start = (job, request) => {
        job.state = 'uploading'
        job.error = null
        job.loaded = 0
        job.sentTempIds = []

        const controller = typeof AbortController === 'function' ? new AbortController() : null
        controllers.set(job.id, controller)
        requests.set(job.id, request)

        const progress = (loaded, total) => {
            if (job.state !== 'uploading') return
            job.loaded = loaded
            if (total) job.total = total
        }

        Promise.resolve(send(request, job, controller, progress))
            .then(() => {
                // المكتملة تُعلَّم ولا تُحذف حتى يفرغ الطابور.
                //
                // حذفها فوراً كان يُخرج بايتاتها من الحساب: يصغر المقام ويعود
                // البسط للصفر، فترتدّ النسبة الإجمالية إلى الصفر مع نهاية كل
                // ملف — يرى الموظّف تقدّماً يُمحى ثلاث مرّات ويظنّ الرفع يُعاد.
                job.state = 'done'
                job.loaded = job.total
                pump()
            })
            .catch((error) => {
                if (isAborted(error)) {
                    remove(job.id)
                    request.onFailure?.(pendingTempIds(job))
                    pump()

                    return
                }

                job.state = 'failed'
                job.loaded = 0
                job.error = readErrorMessage(error)
                // الفقاعات التفاؤلية تُزال فوراً: إبقاؤها يوهم أن الملف وصل.
                request.onFailure?.(pendingTempIds(job))
                onError?.(job.error)
                pump()
            })
    }

    /** الطلب الأصلي بملفاته غير الملفوفة — لإعادة المحاولة وإعادة بناء الفقاعات. */
    const requestFor = (id) => requests.get(id) ?? null

    /**
     * اختيار طريق الإرسال.
     *
     * الملف الكبير يُرفَع على قطع: الوكيل الأمامي يقطع أي طلب تجاوز ١٢٥ ثانية،
     * فطلبٌ واحد لملف كبير على شبكة بطيئة يموت دائماً — بلا رسالة، ويتجمّد
     * الشريط في منتصفه. والصغير يبقى في طلب واحد: التجزئة تُضاعف الرحلات بلا
     * فائدة حين يكفي طلبٌ قصير.
     */
    const send = async (request, job, controller, progress) => {
        const files = request.files
        const total = files.reduce((sum, item) => sum + Number(item.file?.size ?? 0), 0)

        // القياس على الحمولة لا على عدد الملفات.
        //
        // مهلة الوكيل الأمامي على **الطلب**، فعشرة ملفات صغيرة في طلب واحد
        // تتجاوزها على خطّ بطيء كما يتجاوزها ملفٌ كبير. وربط التجزئة بعدد
        // الملفات كان يُسقط الدفعة إلى المسار القديم: ملفٌ واحد يمرّ، وملفان
        // يعودان إلى طلبٍ واحد بحجمهما معاً فيموت كما كان.
        if (total <= CHUNK_BYTES) {
            return post('/chats', buildFormData(request, job), {
                signal: controller?.signal,
                onUploadProgress: (event) => progress(event.loaded ?? 0, event.total),
            })
        }

        let uploaded = 0

        for (const [index, item] of files.entries()) {
            await uploadInChunks({
                post,
                file: item.file,
                signal: controller?.signal,
                onProgress: (loaded) => progress(uploaded + loaded, total),
                fields: {
                    // معرّف مستقلّ لكل ملف: الخادم يبني منه اسم مجلّد القطع،
                    // فمعرّفٌ مشترك يخلط قطع ملفين في مجلّد واحد ويُتلفهما معاً.
                    upload_id: job.uploadId + '-' + index,
                    contact_uuid: request.contactUuid,
                    file_name: item.file.name,
                    file_type: item.type,
                    // التعليق مع الأوّل وحده — تكراره يُغرق المحادثة.
                    caption: index === 0 ? job.caption : '',
                    temp_message_id: job.tempIds[index],
                },
            })

            uploaded += Number(item.file?.size ?? 0)
            // ما وصل صار رسالةً فعلاً: فقاعته لا تُزال لو أخفق ما بعده.
            job.sentTempIds.push(job.tempIds[index])
            progress(uploaded, total)
        }
    }

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

        // منتظِرٌ لم يبدأ: يُحذف فوراً بلا لمس الشبكة — وهذا هو الغرض من
        // الطابور المتتابع.
        const request = requests.get(job.id)
        remove(job.id)
        request?.onFailure?.(pendingTempIds(job))
        pump()

        return true
    }

    const dismiss = (id) => {
        const job = find(id)
        if (!job || job.state === 'uploading') return false

        remove(id)

        return true
    }

    /**
     * مهامّ محادثة بعينها.
     *
     * المؤشّر خاصّ بمحادثته: من انتقل إلى عميل آخر لا يعنيه رفعٌ يخصّ عميلاً
     * سابقاً، وعرضه هناك ضجيج يربك أكثر ممّا يُطمئن. والرفع يكمل في الخلفية
     * على أي حال، فيراه صاحبه حين يعود.
     */
    const jobsFor = (contactUuid) =>
        contactUuid ? state.jobs.filter((job) => job.contactUuid === contactUuid) : []

    const uploadingFor = (contactUuid) =>
        jobsFor(contactUuid).filter((job) => job.state === 'uploading' || job.state === 'pending')

    const fileCountFor = (contactUuid) =>
        uploadingFor(contactUuid).reduce((sum, job) => sum + job.fileNames.length, 0)

    /** نسبة محادثة واحدة — موزونة بالبايتات كالنسبة العامّة. */
    const percentFor = (contactUuid) => weighted(inRound(jobsFor(contactUuid)))

    const jobs = computed(() => state.jobs)
    /** ما يعمل الآن أو ينتظر دوره — لا المكتمل ولا المخفق. */
    const uploading = computed(() =>
        state.jobs.filter((job) => job.state === 'uploading' || job.state === 'pending'))

    /** ما تُحسب عليه النسبة: الجولة كلّها بما أُنجز منها. */
    const inRound = (jobs) => jobs.filter((job) => job.state !== 'failed')
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
    const percent = computed(() => weighted(inRound(state.jobs)))

    return {
        jobs, uploading, failed, isBusy, fileCount, percent,
        jobsFor, uploadingFor, fileCountFor, percentFor,
        enqueue, retry, cancel, dismiss, requestFor, jobPercent,
    }
}

/**
 * نسبة مجموعة مهامّ، موزونة بالبايتات لا بعددها: مهمّة صغيرة اكتملت لا تقفز
 * بالمؤشّر إلى النصف بينما الملف الكبير في أوّله.
 */
function weighted(jobs) {
    if (jobs.length === 0) return 0

    const total = jobs.reduce((sum, job) => sum + (job.total || 0), 0)
    if (total <= 0) return 0

    const loaded = jobs.reduce((sum, job) => sum + Math.min(job.loaded, job.total || 0), 0)

    return Math.min(100, Math.round((loaded / total) * 100))
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
