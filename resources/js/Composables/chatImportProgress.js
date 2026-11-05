/**
 * حالة استيراد المحادثات كما تعرضها الصفحة.
 *
 * دوال خالصة بلا Vue: شريط التقدّم يمرّ بثلاث مراحل (رفع ثم فحص ثم كتابة)
 * والخادم يعطي نسبة المرحلة الحاليّة وحدها، فحساب «أين نحن» يجب أن يكون
 * صحيحاً في كل حالة — ويُختبر في tests/js/chat-import-progress.mjs.
 */

/** حالات يعمل فيها شيء على الطابور — الصفحة تسأل الخادم عنها دورياً. */
export const ACTIVE_STATUSES = ['pending_validation', 'validating', 'queued', 'importing', 'cancelling', 'rolling_back']

/** مراحل الشريط بترتيبها. */
export const STEPS = ['upload', 'validate', 'import']

/** نصوص الحالات — مفاتيح ترجمة. */
export const STATUS_LABELS = {
    uploading: 'Uploading',
    pending_validation: 'Waiting to be checked',
    validating: 'Checking the file',
    invalid: 'Rejected',
    queued: 'Waiting to import',
    importing: 'Importing',
    completed: 'Completed',
    failed: 'Failed',
    cancelling: 'Cancelling',
    cancelled: 'Cancelled',
    rolling_back: 'Rolling back',
    rolled_back: 'Rolled back',
}

/** فترة السؤال عن الحالة بالمللي ثانية. */
export const POLL_MS = 2000

export function isActive(status) {
    return ACTIVE_STATUSES.includes(status)
}

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? String(status ?? '')
}

/** لون الشارة: success | error | warning | info */
export function statusTone(status) {
    switch (status) {
        case 'completed':
        case 'rolled_back':
            return 'success'
        case 'invalid':
        case 'failed':
            return 'error'
        case 'cancelling':
        case 'cancelled':
            return 'warning'
        default:
            return 'info'
    }
}

export function clampPercent(value) {
    const number = Math.floor(Number(value))

    if (!Number.isFinite(number)) {
        return 0
    }

    return Math.min(100, Math.max(0, number))
}

/**
 * المرحلة الحاليّة ونسبتها.
 *
 * @param {{upload?: {loaded: number, total: number}|null, importState?: object|null}} state
 * @returns {{step: 'upload'|'validate'|'import'|'rollback'|'done'|null, percent: number, status: string|null}}
 */
export function progressView({ upload = null, importState = null } = {}) {
    if (!importState) {
        if (upload && upload.total > 0) {
            return { step: 'upload', percent: clampPercent((upload.loaded / upload.total) * 100), status: 'uploading' }
        }

        return { step: null, percent: 0, status: null }
    }

    const status = importState.status
    const percent = clampPercent(importState.percent)

    if (status === 'rolling_back') {
        return { step: 'rollback', percent, status }
    }

    if (status === 'pending_validation' || status === 'validating') {
        return { step: 'validate', percent, status }
    }

    if (status === 'queued' || status === 'importing' || status === 'cancelling') {
        return { step: 'import', percent, status }
    }

    // invalid يقف عند الفحص: لم يُكتب شيء، والشريط يقول ذلك.
    if (status === 'invalid') {
        return { step: 'validate', percent: 100, status }
    }

    return { step: 'done', percent: 100, status }
}

/**
 * حالة خطوة في الشريط: done | current | failed | pending
 *
 * @param {string} step
 * @param {{step: string|null, status: string|null}} view نتيجة progressView
 * @param {boolean} started هل بدأت الكتابة (started_at)؟ فشلٌ قبلها فشلُ فحص.
 */
export function stepState(step, view, started = true) {
    const current = view?.step

    if (current === 'done') {
        if (view.status !== 'failed' && view.status !== 'cancelled') {
            return 'done'
        }

        const stoppedAt = started ? 'import' : 'validate'

        if (step === stoppedAt) {
            return 'failed'
        }

        return STEPS.indexOf(step) < STEPS.indexOf(stoppedAt) ? 'done' : 'pending'
    }

    const index = STEPS.indexOf(step)
    const currentIndex = STEPS.indexOf(current)

    if (currentIndex === -1) {
        return 'pending'
    }

    if (index < currentIndex) {
        return 'done'
    }

    if (index === currentIndex) {
        return view.status === 'invalid' ? 'failed' : 'current'
    }

    return 'pending'
}

/**
 * التنبيه الذي يظهر حين تتغيّر الحالة والصفحة مفتوحة.
 *
 * @returns {{type: 'success'|'error'|'info'|'warning', message: string}|null}
 */
export function transitionToast(previous, next) {
    if (!previous || previous === next) {
        return null
    }

    switch (next) {
        case 'invalid':
            return { type: 'error', message: 'The file was rejected. Fix the errors below and upload it again.' }
        case 'queued':
            return previous === 'validating' || previous === 'pending_validation'
                ? { type: 'success', message: 'The file passed all checks. Importing now.' }
                : null
        case 'completed':
            return { type: 'success', message: 'Import completed successfully.' }
        case 'failed':
            return { type: 'error', message: 'The import failed.' }
        case 'cancelled':
            return { type: 'warning', message: 'The import was cancelled.' }
        case 'rolled_back':
            return { type: 'success', message: 'The import was rolled back.' }
        default:
            return null
    }
}

/** معرّف رفع فريد — الخادم يبني منه مجلّد القطع. */
export function newUploadId() {
    const random = Math.random().toString(36).slice(2, 10)

    return Date.now().toString(36) + '-' + random
}

export function formatBytes(bytes) {
    const value = Number(bytes) || 0

    if (value < 1024) {
        return value + ' B'
    }

    if (value < 1024 * 1024) {
        return (value / 1024).toFixed(1) + ' KB'
    }

    return (value / (1024 * 1024)).toFixed(1) + ' MB'
}

/** هل يبدو الملف CSV؟ بالامتداد لا بالنوع: ويندوز يُرسل CSV أحياناً بنوع Excel. */
export function looksLikeCsv(fileName) {
    return /\.(csv|txt)$/i.test(String(fileName ?? ''))
}
