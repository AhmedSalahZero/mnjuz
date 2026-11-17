/**
 * القوالب الجاهزة في «ابدأ من قالب جاهز». نصوص عامّة تصلح لأي نشاط، وتبدأ
 * باسم المنشأة واسم العميل متغيّرَين. التاجر يعدّل بعدها كما يشاء.
 *
 * النصّ المترجم لا يحوي {first_name} حرفياً (vue-i18n يقرؤه معاملاً): يُمرَّر
 * الرمز قيمةً لمعامل {name}.
 */
import { createBlock, emptyDesign } from './emailDesign.js'

const CUSTOMER = '{first_name}'
const MERCHANT = '{organization_name}'

/** ترويسة ونهاية بلون القالب. */
const frame = (t, color, middle) => [
    createBlock('header', t, { bg_color: color }),
    ...middle,
    createBlock('footer', t),
]

const PRESETS = [
    {
        key: 'blank',
        name: 'Blank design',
        description: 'Start from an empty email.',
        color: null,
        blocks: () => [],
    },
    {
        key: 'special_offer',
        name: 'Special offer',
        description: 'A limited-time discount or offer with a clear booking button.',
        color: '#b91c1c',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('A special offer just for you'), color }),
            createBlock('text', t, { text: t('Hello {name}, enjoy an exclusive discount for a limited time.', { name: CUSTOMER }), align: 'center' }),
            createBlock('button', t, { label: t('Get the offer'), bg_color: color }),
        ]),
    },
    {
        key: 'new_service',
        name: 'New service',
        description: 'Announce a new service or product to your customers.',
        color: '#0f766e',
        blocks: (t, color) => frame(t, color, [
            createBlock('image', t),
            createBlock('heading', t, { text: t('Something new is here'), color }),
            createBlock('text', t, { text: t('Hello {name}, we are happy to introduce our latest service, designed with you in mind.', { name: CUSTOMER }), align: 'center' }),
            createBlock('button', t, { label: t('Learn more'), bg_color: color }),
        ]),
    },
    {
        key: 'welcome',
        name: 'Welcome email',
        description: 'A welcome message for your new customers.',
        color: '#4338ca',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('Welcome aboard'), color }),
            createBlock('text', t, { text: t('Hello {name}, thank you for joining us. We look forward to serving you.', { name: CUSTOMER }), align: 'center' }),
            createBlock('button', t, { label: t('Visit us'), bg_color: color }),
        ]),
    },
    {
        key: 'miss_you',
        name: 'We miss you',
        description: 'Bring back customers who have not visited in a while.',
        color: '#7c3aed',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('We miss you'), color }),
            createBlock('text', t, { text: t('Hello {name}, it has been a while since your last visit. Come back and enjoy a gift waiting for you.', { name: CUSTOMER }), align: 'center' }),
            createBlock('button', t, { label: t('Come back'), bg_color: color }),
        ]),
    },
    {
        key: 'eid',
        name: 'Eid greetings',
        description: 'Greet your customers on Eid.',
        color: '#15803d',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('Eid Mubarak'), color }),
            createBlock('text', t, { text: t('Wishing you and your family a blessed Eid filled with joy. Thank you for being with us.'), align: 'center' }),
        ]),
    },
    {
        key: 'ramadan',
        name: 'Ramadan Kareem',
        description: 'Ramadan greetings and working hours.',
        color: '#1e3a8a',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('Ramadan Kareem'), color }),
            createBlock('text', t, { text: t('May this holy month bring you peace and blessings.'), align: 'center' }),
            createBlock('text', t, { text: t('Add your working hours during Ramadan here.'), align: 'center' }),
        ]),
    },
    {
        key: 'national_day',
        name: 'National Day',
        description: 'Celebrate National Day with your customers.',
        color: '#1b5e20',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('Happy National Day'), color }),
            createBlock('text', t, { text: t('On the occasion of National Day, {organization} is happy to share the celebration with you and offer you a special gift.', { organization: MERCHANT }), align: 'center' }),
            createBlock('button', t, { label: t('Celebrate with us'), bg_color: color }),
        ]),
    },
    {
        key: 'gift_cards',
        name: 'Gift cards',
        description: 'Encourage customers to gift their loved ones.',
        color: '#be185d',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('Give the perfect gift'), color }),
            createBlock('text', t, { text: t('Surprise your loved ones with a gift card they can use for any of our services.'), align: 'center' }),
            createBlock('button', t, { label: t('Get a gift card'), bg_color: color }),
        ]),
    },
    {
        key: 'loyalty',
        name: 'Loyalty program',
        description: 'Introduce your loyalty program and its rewards.',
        color: '#b45309',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('Earn rewards with every visit'), color }),
            createBlock('text', t, { text: t('Hello {name}, join our loyalty program and collect points with every purchase to redeem for rewards.', { name: CUSTOMER }), align: 'center' }),
            createBlock('button', t, { label: t('Join now'), bg_color: color }),
        ]),
    },
    {
        key: 'newsletter',
        name: 'Newsletter',
        description: 'Regular news and tips for your customers.',
        color: '#111827',
        blocks: (t, color) => frame(t, color, [
            createBlock('heading', t, { text: t('Our latest news'), color }),
            createBlock('image_text', t, { text: t('Share an update, a tip, or a story with your customers here.') }),
            createBlock('divider', t),
            createBlock('text', t, { text: t('Thank you for staying with us. See you in the next issue.') }),
            createBlock('social', t, { color }),
        ]),
    },
]

/** بطاقات المعرض: مفتاح، اسم ووصف (مفاتيح ترجمة)، ولون النقطة. */
export const presetCards = () => PRESETS.map(({ key, name, description, color }) => ({ key, name, description, color }))

/** تصميم القالب الجاهز بمعرّفات جديدة، أو null لمفتاح مجهول. */
export const presetDesign = (key, t = (text) => text, direction = 'rtl') => {
    const preset = PRESETS.find((item) => item.key === key)

    if (!preset) {
        return null
    }

    return { ...emptyDesign(direction), blocks: preset.blocks(t, preset.color) }
}
