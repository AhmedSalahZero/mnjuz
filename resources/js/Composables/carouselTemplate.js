/**
 * قالب الـ carousel في جهة الإرسال (الحملة والمحادثة) وفي العرض.
 *
 * مرآة لـ App\Services\Carousel\CarouselDefinition: القالب يصل إمّا كما
 * أنشأناه (UPPERCASE) أو كما جاء من مزامنة Meta (lowercase أحياناً)، فكل
 * مقارنة بلا حساسية لحالة الأحرف.
 *
 * وحدة ESM صرفة بلا استيراد من '@/' كي تُختبر بـ node مباشرة
 * (tests/js/carousel-template.mjs).
 */

export const CAROUSEL_FORMATS = ['IMAGE', 'VIDEO']
export const CAROUSEL_MEDIA_LIMITS = { IMAGE: 5 * 1024 * 1024, VIDEO: 16 * 1024 * 1024 }

const VARIABLE = /\{\{\s*(\d+)\s*\}\}/g
const upper = (value) => String(value ?? '').toUpperCase()

/** metadata نصّاً أو كائناً ← كائن، أو null. */
export function decodeMetadata(metadata) {
    if (metadata && typeof metadata === 'object') {
        return metadata
    }

    if (typeof metadata !== 'string' || metadata === '') {
        return null
    }

    try {
        const parsed = JSON.parse(metadata)
        return parsed && typeof parsed === 'object' ? parsed : null
    } catch (e) {
        return null
    }
}

/** عدد المتغيّرات المختلفة {{n}}. */
export function countVariables(text) {
    const numbers = new Set()
    for (const match of String(text ?? '').matchAll(VARIABLE)) {
        numbers.add(match[1])
    }

    return numbers.size
}

/** يستبدل {{n}} بالقيمة n-1 من values، ويترك ما لا قيمة له. */
export function substituteVariables(text, values = []) {
    return String(text ?? '').replace(VARIABLE, (whole, number) => {
        const value = values[Number(number) - 1]
        return value === undefined || value === null || value === '' ? whole : String(value)
    })
}

function normalizeButton(button) {
    const type = upper(button?.type)
    const url = typeof button?.url === 'string' ? button.url : null
    const example = Array.isArray(button?.example) ? (button.example[0] ?? null) : (button?.example ?? null)

    return {
        type,
        text: String(button?.text ?? ''),
        url,
        hasVariable: type === 'URL' && url !== null && countVariables(url) > 0,
        example: example === null || example === undefined ? null : String(example),
        phoneNumber: button?.phone_number ?? null,
    }
}

function bodyOf(component) {
    if (!component || typeof component.text !== 'string') {
        return null
    }

    const examples = component.example?.body_text?.[0]

    return {
        text: component.text,
        variables: countVariables(component.text),
        examples: Array.isArray(examples) ? examples.map(String) : [],
    }
}

/**
 * تعريف موحّد للقالب أو null إن لم يكن carousel.
 *
 * @returns {{body: {text: string, variables: number, examples: string[]}, format: string,
 *            cards: Array<{body: object|null, buttons: Array<object>}>}|null}
 */
export function extractCarousel(metadata) {
    const decoded = decodeMetadata(metadata)
    const components = Array.isArray(decoded?.components) ? decoded.components : null

    if (!components) {
        return null
    }

    const carousel = components.find((component) =>
        upper(component?.type) === 'CAROUSEL' && Array.isArray(component.cards) && component.cards.length > 0)

    if (!carousel) {
        return null
    }

    let format = null
    const cards = carousel.cards.map((card) => {
        const parts = Array.isArray(card?.components) ? card.components : []
        let body = null
        let buttons = []

        for (const part of parts) {
            const type = upper(part?.type)

            if (type === 'HEADER' && format === null) {
                const candidate = upper(part.format)
                format = CAROUSEL_FORMATS.includes(candidate) ? candidate : null
            } else if (type === 'BODY') {
                body = bodyOf(part)
            } else if (type === 'BUTTONS' && Array.isArray(part.buttons)) {
                buttons = part.buttons.map(normalizeButton)
            }
        }

        return { body, buttons }
    })

    return {
        body: bodyOf(components.find((component) => upper(component?.type) === 'BODY')) ?? { text: '', variables: 0, examples: [] },
        format: format ?? 'IMAGE',
        cards,
    }
}

export function isCarouselMetadata(metadata) {
    return extractCarousel(metadata) !== null
}

/**
 * حالة البطاقات في نموذج الإرسال.
 *
 * البطاقة التي لنا نسخة من وسائطها (templates.carousel_media) تبدأ بها
 * مختارة. وما سواها — قوالب المزامنة خصوصاً، إذ روابط Meta فيها تنتهي —
 * يبدأ بالرفع.
 */
export function buildCarouselCardParameters(definition, carouselMedia) {
    const media = decodeMetadata(carouselMedia)
    const stored = Array.isArray(media?.cards) ? media.cards : []

    return {
        format: definition.format,
        cards: definition.cards.map((card, index) => {
            const own = stored[index]?.url ?? null

            return {
                card_index: index,
                header: {
                    type: definition.format,
                    selection: own ? 'default' : 'upload',
                    value: null,
                    url: own,
                    hasDefault: Boolean(own),
                    preview: null,
                },
                body: {
                    parameters: Array.from({ length: card.body?.variables ?? 0 }, (_, k) => ({
                        type: 'text',
                        selection: 'static',
                        value: card.body?.examples?.[k] ?? '',
                    })),
                },
                buttons: card.buttons.map((button, j) => ({
                    index: j,
                    type: button.type,
                    text: button.text,
                    parameters: button.type === 'QUICK_REPLY'
                        ? [{ selection: 'static', value: '' }]
                        : (button.type === 'URL' && button.hasVariable
                            ? [{ selection: 'static', value: button.example ?? '' }]
                            : []),
                })),
            }
        }),
    }
}

/** ما يُرسَل إلى الخادم فقط، بلا مفاتيح الواجهة (url, preview, hasDefault). */
export function carouselRequestPayload(state) {
    return {
        format: state.format,
        cards: state.cards.map((card) => ({
            header: {
                selection: card.header.selection,
                value: card.header.selection === 'default' ? null : card.header.value,
            },
            body: {
                parameters: card.body.parameters.map((parameter) => ({
                    type: 'text',
                    selection: parameter.selection,
                    value: parameter.value,
                })),
            },
            buttons: card.buttons.map((button) => ({
                index: button.index,
                type: button.type,
                parameters: button.parameters.map((parameter) => ({
                    selection: parameter.selection,
                    value: parameter.value,
                })),
            })),
        })),
    }
}

/** رابط معاينة وسائط البطاقة: الملف المرفوع للتوّ، أو نسختنا، أو لا شيء. */
export function cardMediaSource(header) {
    if (!header) {
        return null
    }

    if (header.selection === 'upload' || header.selection === 'history') {
        return header.preview ?? null
    }

    return header.url ?? null
}

/**
 * بطاقات المعاينة من التعريف والحالة: المتغيّر الثابت يظهر بقيمته،
 * والديناميكي باسم حقله بين قوسين.
 */
export function carouselPreviewCards(definition, state) {
    if (!definition) {
        return []
    }

    return definition.cards.map((card, index) => {
        const current = state?.cards?.[index]
        const values = (current?.body?.parameters ?? []).map((parameter) =>
            parameter.selection === 'static' ? parameter.value : (parameter.value ? `[${parameter.value}]` : ''))

        return {
            media: cardMediaSource(current?.header),
            body: card.body ? substituteVariables(card.body.text, values) : null,
            buttons: card.buttons.map((button) => ({ type: button.type, text: button.text })),
        }
    })
}

/** مجموع أحجام الملفات المرفوعة في الطلب، لمقارنته بحدّ الرفع. */
export function carouselUploadBytes(state) {
    return (state?.cards ?? []).reduce((total, card) => {
        const value = card?.header?.selection === 'upload' ? card.header.value : null
        return total + (value && typeof value.size === 'number' ? value.size : 0)
    }, 0)
}
