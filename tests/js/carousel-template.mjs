/**
 * قالب الـ carousel في جهة الإرسال: قراءة التعريف من مصدريه، وبناء حالة
 * البطاقات في النموذج، وما يُرسَل إلى الخادم، والمعاينة.
 */
import {
    buildCarouselCardParameters,
    carouselPreviewCards,
    carouselRequestPayload,
    carouselUploadBytes,
    countVariables,
    extractCarousel,
    isCarouselMetadata,
    substituteVariables,
} from '../../resources/js/Composables/carouselTemplate.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

const inApp = {
    name: 'shoes',
    components: [
        { type: 'BODY', text: 'Hi {{1}}', example: { body_text: [['Sara']] } },
        { type: 'CAROUSEL', cards: [
            { components: [
                { type: 'HEADER', format: 'IMAGE', example: { header_handle: ['4::a'] } },
                { type: 'BODY', text: 'Shoe {{1}}', example: { body_text: [['red']] } },
                { type: 'BUTTONS', buttons: [
                    { type: 'QUICK_REPLY', text: 'Buy' },
                    { type: 'URL', text: 'Open', url: 'https://shop.test/p/{{1}}', example: ['sku1'] },
                ] },
            ] },
            { components: [
                { type: 'HEADER', format: 'IMAGE', example: { header_handle: ['4::b'] } },
                { type: 'BODY', text: 'Bag {{1}}', example: { body_text: [['blue']] } },
                { type: 'BUTTONS', buttons: [
                    { type: 'QUICK_REPLY', text: 'Buy' },
                    { type: 'URL', text: 'Open', url: 'https://shop.test/p/{{1}}', example: ['sku2'] },
                ] },
            ] },
        ] },
    ],
}

const synced = JSON.stringify({
    id: '1', status: 'APPROVED',
    components: [
        { type: 'body', text: 'Hello' },
        { type: 'carousel', cards: [
            { components: [
                { type: 'header', format: 'video', example: { header_handle: ['https://scontent/x.mp4'] } },
                { type: 'buttons', buttons: [{ type: 'phone_number', text: 'Call', phone_number: '+966500000000' }] },
            ] },
            { components: [
                { type: 'header', format: 'video', example: { header_handle: ['https://scontent/y.mp4'] } },
                { type: 'buttons', buttons: [{ type: 'phone_number', text: 'Call', phone_number: '+966500000000' }] },
            ] },
        ] },
    ],
})

// ------------------------------------- التعرّف

is(isCarouselMetadata(inApp), true, 'ما أنشأناه carousel')
is(isCarouselMetadata(JSON.stringify(inApp)), true, 'ونصّاً كذلك')
is(isCarouselMetadata(synced), true, 'وما جاء من المزامنة بأحرف صغيرة')
is(isCarouselMetadata(JSON.stringify({ components: [{ type: 'BODY', text: 'x' }] })), false, 'القالب العادي ليس carousel')
is(isCarouselMetadata('not json'), false, 'نصّ تالف')
is(isCarouselMetadata(null), false, 'null')
is(isCarouselMetadata({ components: [{ type: 'CAROUSEL', cards: [] }] }), false, 'carousel بلا بطاقات')

// ------------------------------------- التعريف

const def = extractCarousel(inApp)
is(def.format, 'IMAGE', 'الصيغة')
is(def.body, { text: 'Hi {{1}}', variables: 1, examples: ['Sara'] }, 'متن الرسالة')
is(def.cards.length, 2, 'بطاقتان')
is(def.cards[1].body.text, 'Bag {{1}}', 'نصّ البطاقة الثانية')
is(def.cards[0].buttons[1].hasVariable, true, 'رابط بمتغيّر')
is(def.cards[0].buttons[1].example, 'sku1', 'ومثاله')

const syncedDef = extractCarousel(synced)
is(syncedDef.format, 'VIDEO', 'الصيغة من المزامنة بأحرف كبيرة')
is(syncedDef.cards[0].body, null, 'بطاقة بلا نصّ')
is(syncedDef.cards[0].buttons[0].type, 'PHONE_NUMBER', 'نوع الزرّ بأحرف كبيرة')

// ------------------------------------- حالة النموذج

const withMedia = buildCarouselCardParameters(def, JSON.stringify({ format: 'IMAGE', cards: [{ url: 'https://app/one.jpg' }, null] }))
is(withMedia.cards[0].header.selection, 'default', 'لنا نسخة ⇐ افتراضي')
is(withMedia.cards[0].header.url, 'https://app/one.jpg', 'ورابطها للمعاينة')
is(withMedia.cards[1].header.selection, 'upload', 'لا نسخة ⇐ رفع')
is(withMedia.cards[1].header.hasDefault, false, 'ولا خيار افتراضي')
is(withMedia.cards[0].body.parameters, [{ type: 'text', selection: 'static', value: 'red' }], 'المتغيّر يبدأ بمثاله')
is(withMedia.cards[0].buttons[0].parameters, [{ selection: 'static', value: '' }], 'حمولة الردّ السريع اختيارية وفارغة')
is(withMedia.cards[0].buttons[1].parameters, [{ selection: 'static', value: 'sku1' }], 'متغيّر الرابط يبدأ بمثاله')

const syncedState = buildCarouselCardParameters(syncedDef, null)
is(syncedState.cards.map((card) => card.header.selection), ['upload', 'upload'], 'قالب المزامنة يطلب الرفع لكل بطاقة')
is(syncedState.cards[0].buttons[0].parameters, [], 'زرّ الهاتف بلا معاملات')

// ------------------------------------- ما يُرسَل

const file = { name: 'a.jpg', size: 1200 }
withMedia.cards[1].header.value = file
withMedia.cards[1].header.preview = 'blob:x'
const payload = carouselRequestPayload(withMedia)
is(Object.keys(payload.cards[0].header), ['selection', 'value'], 'بلا مفاتيح الواجهة')
is(payload.cards[0].header.value, null, 'الافتراضي بلا قيمة')
is(payload.cards[1].header.value === file, true, 'الملف نفسه لا نسخة')
is(payload.cards[1].buttons[1], { index: 1, type: 'URL', parameters: [{ selection: 'static', value: 'sku2' }] }, 'الزرّ بلا نصّه')
is(carouselUploadBytes(withMedia), 1200, 'مجموع المرفوع')

// ------------------------------------- المعاينة

const preview = carouselPreviewCards(def, withMedia)
is(preview[0].media, 'https://app/one.jpg', 'الافتراضي يُعاين بنسختنا')
is(preview[1].media, 'blob:x', 'والمرفوع بمعاينته')
is(preview[0].body, 'Shoe red', 'المتغيّر الثابت بقيمته')
withMedia.cards[0].body.parameters[0] = { type: 'text', selection: 'dynamic', value: 'first name' }
is(carouselPreviewCards(def, withMedia)[0].body, 'Shoe [first name]', 'والديناميكي باسم حقله')
is(preview[0].buttons, [{ type: 'QUICK_REPLY', text: 'Buy' }, { type: 'URL', text: 'Open' }], 'الأزرار من القالب')

// ------------------------------------- أدوات

is(countVariables('{{1}} {{2}} {{1}}'), 2, 'متغيّران مختلفان')
is(substituteVariables('Hi {{1}} {{2}}', ['Sara']), 'Hi Sara {{2}}', 'ما لا قيمة له يبقى')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
