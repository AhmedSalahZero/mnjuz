/**
 * منتقي قناة الحملة: القنوات الأربع، اختيار واحد، والافتراضي واتساب.
 * ولكل قناة حدود رسالتها ومرفقاتها كما يرسلها الخادم.
 */
import { acceptsMedia, fileProblem, findSavedTemplate, initialCampaignChannel, isSelectableChannel, mediaAccept, mediaTypeOf, messageLimit, savedTemplateOptions } from '../../resources/js/Composables/campaignChannels.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

const option = (value, connected, comingSoon = false) => ({ value, connected, coming_soon: comingSoon })

// ------------------------------------- الاختيار والافتراضي

is(isSelectableChannel(option('telegram', true)), true, 'مربوطة تُختار')
is(isSelectableChannel(option('email', false)), false, 'غير مربوطة لا تُختار')
is(isSelectableChannel(option('sms', true, true)), false, 'قريباً لا تُختار ولو قيل إنها مربوطة')
is(initialCampaignChannel([option('whatsapp', true), option('telegram', true)]), 'whatsapp', 'واتساب افتراضياً')
is(initialCampaignChannel([option('whatsapp', false), option('telegram', true)]), 'telegram', 'بلا واتساب: أوّل مربوطة')
is(initialCampaignChannel([option('whatsapp', false), option('sms', false, true)]), 'whatsapp', 'لا شيء مربوط: واتساب (والنموذج يعرض رابط الربط)')
is(initialCampaignChannel([]), 'whatsapp', 'بلا خيارات')

// ------------------------------------- حدود النصّ

const telegram = { text_max: 4096, caption_max: 1024, media_limits: { image: 5242880, video: 20971520, audio: 20971520, document: 20971520 }, blocked_extensions: ['html', 'svg', 'js'] }
const email = { text_max: 10000, subject_max: 200, media_limits: {} }

is(messageLimit(telegram, false), 4096, 'تيليجرام بلا مرفق')
is(messageLimit(telegram, true), 1024, 'تيليجرام مع مرفق: حدّ التعليق')
is(messageLimit(email, true), 10000, 'البريد')
is(messageLimit({}, false), null, 'بلا قواعد')

// ------------------------------------- المرفقات

is(acceptsMedia(telegram), true, 'تيليجرام يقبل مرفقات')
is(acceptsMedia(email), false, 'البريد بلا مرفقات')
is(mediaAccept(telegram), '', 'المستندات مقبولة: بلا قيد accept')
is(mediaAccept({ media_limits: { image: 1 } }), 'image/*', 'صور فقط')
is(mediaTypeOf('image/png'), 'image', 'صورة')
is(mediaTypeOf('application/pdf'), 'document', 'مستند')

is(fileProblem(telegram, null), null, 'بلا ملف')
is(fileProblem(telegram, { name: 'a.png', type: 'image/png', size: 1000 }), null, 'صورة صغيرة')
is(fileProblem(telegram, { name: 'page.HTML', type: 'text/html', size: 10 })?.key, 'This file type cannot be sent as an attachment.', 'HTML ممنوع')
is(fileProblem(telegram, { name: 'big.jpg', type: 'image/jpeg', size: 6 * 1024 * 1024 }), { key: 'The file is larger than {limit} MB.', params: { limit: 5 } }, 'صورة أكبر من 5MB بالرابط')
is(fileProblem({ media_limits: { image: 10 } }, { name: 'a.pdf', type: 'application/pdf', size: 1 })?.key, 'This channel does not accept this type of file.', 'نوع لا تقبله القناة')

// ------------------------------------- القوالب المحفوظة

const saved = { telegram: [{ uuid: 'u1', name: 'عرض العيد', body: 'x' }], email: [] }
is(savedTemplateOptions(saved, 'telegram'), [{ value: 'u1', label: 'عرض العيد' }], 'قوالب القناة')
is(savedTemplateOptions(saved, 'email'), [], 'لا قوالب')
is(savedTemplateOptions({}, 'sms'), [], 'قناة بلا مفتاح')
is(findSavedTemplate(saved, 'telegram', 'u1')?.name, 'عرض العيد', 'القالب المختار')
is(findSavedTemplate(saved, 'email', 'u1'), null, 'قالب قناة أخرى لا يُطابق')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`campaign-channels: ${checks} checks passed`)
