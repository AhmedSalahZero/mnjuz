/**
 * مسودّة قالب الـ carousel في صفحة إنشاء القوالب.
 *
 * قائمة أنواع الأزرار واحدة للمسودّة كلها لا لكل بطاقة: Meta تشترط أن تحمل
 * كل البطاقات الأزرار نفسها وبالترتيب نفسه، فلا نترك للعميل فرصة مخالفتها.
 * والقواعد هنا مرآة لـ App\Services\Carousel\CarouselTemplateValidator —
 * الخادم يعيد فحصها كلها، وهذه لتظهر الأخطاء قبل رفع عشرة ملفات.
 *
 * وحدة ESM صرفة تُختبر بـ node (tests/js/carousel-template-draft.mjs).
 */

import { CAROUSEL_MEDIA_LIMITS, countVariables, substituteVariables } from './carouselTemplate.js'

export const CAROUSEL_MIN_CARDS = 2
export const CAROUSEL_MAX_CARDS = 10
export const CAROUSEL_MAX_BUTTONS = 2
export const CAROUSEL_BODY_MAX = 1024
export const CAROUSEL_CARD_BODY_MAX = 160
export const CAROUSEL_BUTTON_TEXT_MAX = 25
export const CAROUSEL_BUTTON_TYPES = ['QUICK_REPLY', 'URL', 'PHONE_NUMBER']

/** نصّ كل خطأ بالإنجليزية كما في lang/*.json؛ المكوّن يمرّره إلى $t. */
export const CAROUSEL_ERROR_TEXT = {
    category: 'Carousel templates are available for the Marketing category only.',
    name: 'The template name may only contain lowercase letters, numbers and underscores.',
    language: 'Select a language.',
    body_required: 'The message body is required.',
    body_too_long: 'The message body is too long.',
    body_examples: 'Add a sample value for every variable.',
    cards_count: 'Add between 2 and 10 cards.',
    card_media_required: 'Every card needs an image or a video.',
    card_media_image_type: 'Card media must be a PNG or JPG image of up to 5MB.',
    card_media_video_type: 'Card media must be an MP4 video of up to 16MB.',
    card_media_too_large: 'This file is larger than the allowed size.',
    card_body_required: 'Write the text of this card.',
    card_body_too_long: 'Card text is too long.',
    card_body_examples: 'Add a sample value for every variable.',
    button_text_required: 'Every button needs a label.',
    button_text_too_long: 'Button labels must not exceed 25 characters.',
    button_url_invalid: 'Enter a valid website address for the button.',
    button_url_variable: 'A button link can have one variable, and only at the end.',
    button_url_example: 'Add a sample value for the link variable.',
    button_phone_invalid: 'Enter a valid phone number for the button.',
    total_too_large: 'The files together are larger than one upload allows. Use smaller files.',
}

const MIME = {
    IMAGE: ['image/png', 'image/jpeg', 'image/jpg'],
    VIDEO: ['video/mp4'],
}

function newButton(type) {
    return { type, text: '', url: '', example: '', country: '', phone_number: '' }
}

export function newCarouselCard(buttonTypes = []) {
    return {
        file: null,
        preview: null,
        body: { text: '', example: [] },
        buttons: buttonTypes.map(newButton),
    }
}

export function newCarouselDraft() {
    const buttonTypes = ['URL']

    return {
        format: 'IMAGE',
        hasCardBody: true,
        buttonTypes,
        cards: [newCarouselCard(buttonTypes), newCarouselCard(buttonTypes)],
    }
}

export function addCard(draft) {
    if (draft.cards.length >= CAROUSEL_MAX_CARDS) {
        return false
    }

    draft.cards.push(newCarouselCard(draft.buttonTypes))
    return true
}

/** @returns {object|null} البطاقة المحذوفة (ليُبطل المكوّن رابط معاينتها) */
export function removeCard(draft, index) {
    if (draft.cards.length <= CAROUSEL_MIN_CARDS || index < 0 || index >= draft.cards.length) {
        return null
    }

    return draft.cards.splice(index, 1)[0]
}

/**
 * يغيّر أنواع الأزرار في كل البطاقات معاً، ويُبقي ما كتبه العميل في الزرّ
 * الذي لم يتغيّر نوعه في موضعه.
 */
export function setButtonTypes(draft, types) {
    const clean = types
        .map((type) => String(type ?? '').toUpperCase())
        .filter((type) => CAROUSEL_BUTTON_TYPES.includes(type))
        .slice(0, CAROUSEL_MAX_BUTTONS)

    draft.buttonTypes = clean
    draft.cards.forEach((card) => {
        card.buttons = clean.map((type, position) =>
            card.buttons[position]?.type === type ? card.buttons[position] : newButton(type))
    })

    return clean
}

/** تبديل الصيغة يُسقط الملفات المختارة: صورة في carousel فيديو مرفوضة. */
export function setFormat(draft, format) {
    const next = String(format ?? '').toUpperCase() === 'VIDEO' ? 'VIDEO' : 'IMAGE'
    const dropped = []

    if (next !== draft.format) {
        draft.cards.forEach((card) => {
            if (card.preview) dropped.push(card.preview)
            card.file = null
            card.preview = null
        })
    }

    draft.format = next
    return dropped
}

export function cardFileError(file, format, maxUploadBytes = null) {
    if (!file) {
        return 'card_media_required'
    }

    const allowed = MIME[format] ?? MIME.IMAGE
    if (file.type && !allowed.includes(file.type)) {
        return format === 'VIDEO' ? 'card_media_video_type' : 'card_media_image_type'
    }

    const limit = Math.min(CAROUSEL_MEDIA_LIMITS[format] ?? CAROUSEL_MEDIA_LIMITS.IMAGE, maxUploadBytes || Infinity)
    if (typeof file.size === 'number' && file.size > limit) {
        return 'card_media_too_large'
    }

    return null
}

function examplesMissing(text, examples) {
    const count = countVariables(text)
    const list = Array.isArray(examples) ? examples : []

    for (let k = 0; k < count; k++) {
        if (!String(list[k] ?? '').trim()) {
            return true
        }
    }

    return false
}

function isHttpUrl(value) {
    try {
        const url = new URL(String(value).replace(/\{\{\s*\d+\s*\}\}/g, 'x'))
        return url.protocol === 'http:' || url.protocol === 'https:'
    } catch (e) {
        return false
    }
}

/**
 * @returns {{valid: boolean, errors: Array<{code: string, card?: number}>}}
 *          card مرقّم من 1 كما يراه العميل.
 */
export function validateCarouselDraft(form, draft, { maxPostBytes = null, maxUploadBytes = null } = {}) {
    const errors = []
    const add = (code, card) => errors.push(card === undefined ? { code } : { code, card })

    if (form?.category !== 'MARKETING') add('category')
    if (!/^[a-z0-9_]+$/.test(String(form?.name ?? ''))) add('name')
    if (!form?.language) add('language')

    const body = String(form?.body?.text ?? '')
    if (!body.trim()) add('body_required')
    if (body.length > CAROUSEL_BODY_MAX) add('body_too_long')
    if (examplesMissing(body, form?.body?.example)) add('body_examples')

    const cards = Array.isArray(draft?.cards) ? draft.cards : []
    if (cards.length < CAROUSEL_MIN_CARDS || cards.length > CAROUSEL_MAX_CARDS) add('cards_count')

    let total = 0
    cards.forEach((card, index) => {
        const number = index + 1
        const fileError = cardFileError(card.file, draft.format, maxUploadBytes)
        if (fileError) add(fileError, number)
        total += card.file?.size ?? 0

        if (draft.hasCardBody) {
            const text = String(card.body?.text ?? '')
            if (!text.trim()) add('card_body_required', number)
            if (text.length > CAROUSEL_CARD_BODY_MAX) add('card_body_too_long', number)
            if (examplesMissing(text, card.body?.example)) add('card_body_examples', number)
        }

        card.buttons.forEach((button) => {
            const text = String(button.text ?? '').trim()
            if (!text) add('button_text_required', number)
            if (text.length > CAROUSEL_BUTTON_TEXT_MAX) add('button_text_too_long', number)

            if (button.type === 'URL') {
                const url = String(button.url ?? '').trim()
                const variables = countVariables(url)
                if (!isHttpUrl(url)) add('button_url_invalid', number)
                else if (variables > 1 || (variables === 1 && !/\{\{\s*1\s*\}\}$/.test(url))) add('button_url_variable', number)
                else if (variables === 1 && !String(button.example ?? '').trim()) add('button_url_example', number)
            }

            if (button.type === 'PHONE_NUMBER' && !/^\+?\d{6,20}$/.test(`${button.country ?? ''}${button.phone_number ?? ''}`)) {
                add('button_phone_invalid', number)
            }
        })
    })

    if (maxPostBytes && total > maxPostBytes) add('total_too_large')

    return { valid: errors.length === 0, errors }
}

/** جسم الطلب إلى /templates/create — لا header ولا footer ولا buttons على المستوى الأعلى. */
export function buildCarouselCreatePayload(form, draft) {
    const bodyText = String(form.body?.text ?? '')
    const bodyExamples = Array.isArray(form.body?.example) ? form.body.example : []

    return {
        name: form.name,
        language: form.language,
        category: 'MARKETING',
        template_type: 'carousel',
        body: {
            text: bodyText,
            example: bodyExamples.slice(0, countVariables(bodyText)),
        },
        carousel: {
            format: draft.format,
            cards: draft.cards.map((card) => {
                const result = { header: { example: card.file } }

                if (draft.hasCardBody) {
                    const text = String(card.body?.text ?? '')
                    const examples = Array.isArray(card.body?.example) ? card.body.example : []
                    result.body = { text, example: examples.slice(0, countVariables(text)) }
                }

                result.buttons = card.buttons.map((button) => {
                    if (button.type === 'URL') {
                        const url = String(button.url ?? '').trim()
                        return countVariables(url) > 0
                            ? { type: 'URL', text: button.text, url, example: button.example }
                            : { type: 'URL', text: button.text, url }
                    }

                    if (button.type === 'PHONE_NUMBER') {
                        return { type: 'PHONE_NUMBER', text: button.text, country: button.country, phone_number: button.phone_number }
                    }

                    return { type: 'QUICK_REPLY', text: button.text }
                })

                return result
            }),
        },
    }
}

/** بطاقات المعاينة في صفحة الإنشاء. */
export function draftPreviewCards(draft) {
    return draft.cards.map((card) => ({
        media: card.preview ?? null,
        body: draft.hasCardBody ? substituteVariables(card.body?.text ?? '', card.body?.example ?? []) || null : null,
        buttons: card.buttons.map((button) => ({ type: button.type, text: button.text })),
    }))
}
