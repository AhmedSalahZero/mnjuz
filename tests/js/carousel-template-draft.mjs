/**
 * مسودّة قالب الـ carousel في صفحة الإنشاء: حدود البطاقات، وتوحيد الأزرار،
 * والتحقّق قبل الرفع، وجسم الطلب.
 */
import {
    addCard,
    buildCarouselCreatePayload,
    draftPreviewCards,
    newCarouselDraft,
    removeCard,
    setButtonTypes,
    setFormat,
    validateCarouselDraft,
} from '../../resources/js/Composables/carouselTemplateDraft.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}
const codes = (result) => result.errors.map((error) => error.code)

const image = (size = 1000) => ({ name: 'a.jpg', type: 'image/jpeg', size })
const form = () => ({ name: 'summer_shoes', language: 'ar', category: 'MARKETING', body: { text: 'Hi {{1}}', example: ['Sara'] } })

function validDraft() {
    const draft = newCarouselDraft()
    setButtonTypes(draft, ['QUICK_REPLY', 'URL'])
    draft.cards.forEach((card, i) => {
        card.file = image()
        card.preview = `blob:${i}`
        card.body = { text: `Card {{1}}`, example: [`v${i}`] }
        card.buttons[0].text = 'Buy'
        card.buttons[1].text = 'Shop'
        card.buttons[1].url = 'https://shop.test/p/{{1}}'
        card.buttons[1].example = `sku${i}`
    })
    return draft
}

// ------------------------------------- عدد البطاقات

const draft = newCarouselDraft()
is(draft.cards.length, 2, 'تبدأ ببطاقتين')
is(removeCard(draft, 0), null, 'لا أقلّ من اثنتين')
for (let i = 0; i < 8; i++) addCard(draft)
is(draft.cards.length, 10, 'حتى عشر')
is(addCard(draft), false, 'ولا أكثر')
is(removeCard(draft, 9) !== null, true, 'الحذف فوق اثنتين')

// ------------------------------------- الأزرار موحّدة

setButtonTypes(draft, ['url', 'QUICK_REPLY', 'PHONE_NUMBER'])
is(draft.buttonTypes, ['URL', 'QUICK_REPLY'], 'زرّان على الأكثر وبأحرف كبيرة')
is(draft.cards.every((card) => JSON.stringify(card.buttons.map((b) => b.type)) === '["URL","QUICK_REPLY"]'), true, 'نفس الأزرار ونفس الترتيب في كل البطاقات')
draft.cards[0].buttons[0].text = 'kept'
setButtonTypes(draft, ['URL', 'PHONE_NUMBER'])
is(draft.cards[0].buttons[0].text, 'kept', 'الزرّ الذي لم يتغيّر نوعه يحتفظ بنصّه')
is(draft.cards[0].buttons[1].type, 'PHONE_NUMBER', 'والمتغيّر يُستبدل')

// ------------------------------------- الصيغة

const formatDraft = validDraft()
const dropped = setFormat(formatDraft, 'VIDEO')
is(formatDraft.format, 'VIDEO', 'الصيغة تتغيّر')
is(formatDraft.cards.every((card) => card.file === null), true, 'وتُسقط الملفات المختارة')
is(dropped, ['blob:0', 'blob:1'], 'وتُرجع روابط المعاينة لتُبطَل')

// ------------------------------------- التحقّق

is(validateCarouselDraft(form(), validDraft()), { valid: true, errors: [] }, 'مسودّة سليمة')
is(codes(validateCarouselDraft({ ...form(), category: 'UTILITY' }, validDraft())), ['category'], 'التسويق فقط')
is(codes(validateCarouselDraft({ ...form(), name: 'Summer Shoes' }, validDraft())), ['name'], 'اسم بأحرف صغيرة')
is(codes(validateCarouselDraft({ ...form(), body: { text: 'Hi {{1}}', example: [] } }, validDraft())), ['body_examples'], 'مثال كل متغيّر')

let d = validDraft(); d.cards[1].file = null
is(validateCarouselDraft(form(), d).errors, [{ code: 'card_media_required', card: 2 }], 'بطاقة بلا صورة ورقمها كما يراه العميل')

d = validDraft(); d.cards[0].file = { name: 'v.mp4', type: 'video/mp4', size: 10 }
is(codes(validateCarouselDraft(form(), d)), ['card_media_image_type'], 'فيديو في carousel صور')

d = validDraft(); d.cards[0].file = image(6 * 1024 * 1024)
is(codes(validateCarouselDraft(form(), d)), ['card_media_too_large'], 'صورة فوق 5MB')

d = validDraft(); d.cards[0].file = image(3 * 1024 * 1024)
is(codes(validateCarouselDraft(form(), d, { maxUploadBytes: 2 * 1024 * 1024 })), ['card_media_too_large'], 'وحدّ الخادم إن كان أصغر')

d = validDraft()
is(codes(validateCarouselDraft(form(), d, { maxPostBytes: 1500 })), ['total_too_large'], 'مجموع الملفات فوق حدّ الطلب')

d = validDraft(); d.cards[1].body.text = 'x'.repeat(161)
is(codes(validateCarouselDraft(form(), d)), ['card_body_too_long'], 'نصّ البطاقة 160 حرفاً')

d = validDraft(); d.cards[0].buttons[1].url = 'https://shop.test/{{1}}/x'
is(codes(validateCarouselDraft(form(), d)), ['button_url_variable'], 'متغيّر الرابط في آخره فقط')

d = validDraft(); d.cards[0].buttons[1].example = ''
is(codes(validateCarouselDraft(form(), d)), ['button_url_example'], 'ومثاله مطلوب')

d = validDraft(); d.cards[0].buttons[0].text = 'x'.repeat(26)
is(codes(validateCarouselDraft(form(), d)), ['button_text_too_long'], 'تسمية الزرّ 25 حرفاً')

// ------------------------------------- جسم الطلب

const valid = validDraft()
const payload = buildCarouselCreatePayload(form(), valid)
is(payload.template_type, 'carousel', 'يذهب إلى منشئ الـ carousel')
is(payload.category, 'MARKETING', 'تسويق')
is('header' in payload || 'footer' in payload || 'buttons' in payload, false, 'لا header ولا footer ولا buttons على المستوى الأعلى')
is(payload.body, { text: 'Hi {{1}}', example: ['Sara'] }, 'المتن ومثاله')
is(payload.carousel.cards[0].header.example === valid.cards[0].file, true, 'الملف نفسه لا نسخة')
is(payload.carousel.cards[1].buttons, [
    { type: 'QUICK_REPLY', text: 'Buy' },
    { type: 'URL', text: 'Shop', url: 'https://shop.test/p/{{1}}', example: 'sku1' },
], 'الأزرار')

const noBody = validDraft(); noBody.hasCardBody = false
is('body' in buildCarouselCreatePayload(form(), noBody).carousel.cards[0], false, 'بلا نصّ بطاقات لا مفتاح body')

// ------------------------------------- المعاينة

is(draftPreviewCards(valid)[1], { media: 'blob:1', body: 'Card v1', buttons: [{ type: 'QUICK_REPLY', text: 'Buy' }, { type: 'URL', text: 'Shop' }] }, 'معاينة البطاقة')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
