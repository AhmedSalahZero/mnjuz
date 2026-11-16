/**
 * عرض حملة لا تحمل قالب واتساب: القائمة والصفحة كانتا تسقطان مع أوّل
 * حملة تيليجرام (template = null، metadata = "[]").
 */
import { campaignTemplateLabel, isWhatsappCampaign, parseMessagePayload, parseTemplateMetadata, tracksDelivery } from '../../resources/js/Composables/campaignDisplay.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

// ------------------------------------- القناة

is(isWhatsappCampaign('whatsapp'), true, 'حملة واتساب')
is(isWhatsappCampaign(null), true, 'الحملة القديمة بلا قناة = واتساب')
is(isWhatsappCampaign('telegram'), false, 'حملة تيليجرام')
is(tracksDelivery('whatsapp'), true, 'واتساب يُبلغ عن التسليم')
is(tracksDelivery('telegram'), false, 'تيليجرام لا يُبلغ البوت')
is(tracksDelivery('email'), false, 'البريد لا نتتبّع فتحه')

// ------------------------------------- عمود القالب

is(campaignTemplateLabel({ template: { name: 'eid_offer' } }, 'WhatsApp'), 'eid_offer', 'قالب واتساب')
is(campaignTemplateLabel({ template: null, message_template_name: 'عرض العيد' }, 'Telegram'), 'عرض العيد', 'قالب محفوظ')
is(campaignTemplateLabel({ template: null, message_template_name: null }, 'Telegram'), 'Telegram', 'رسالة مكتوبة مباشرة')
is(campaignTemplateLabel(null), '—', 'بلا حملة لا تسقط')

// ------------------------------------- الحمولة

is(parseMessagePayload('{"text":"أهلاً {first_name}","media":null}'), { text: 'أهلاً {first_name}', media: null, subject: '', template: null }, 'حملة تيليجرام نصّية')
is(parseMessagePayload('{"text":"x","subject":"عرض","template":{"uuid":"u","name":"n"}}').subject, 'عرض', 'موضوع البريد')
is(parseMessagePayload('[]'), { text: '', media: null, subject: '', template: null }, 'حملة واتساب قديمة')
is(parseMessagePayload(null).text, '', 'null')
is(parseMessagePayload('not json').text, '', 'نصّ تالف')
is(parseMessagePayload({ text: 'كائن', media: { url: 'u' } }).media, { url: 'u' }, 'كائن جاهز')

// ------------------------------------- metadata قالب واتساب

is(parseTemplateMetadata('[]'), {}, 'مصفوفة فارغة لا تُسقط المعاينة')
is(parseTemplateMetadata('{"header":{"format":"TEXT"}}'), { header: { format: 'TEXT' } }, 'metadata سليمة')
is(parseTemplateMetadata('{'), {}, 'JSON تالف')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`campaign-display: ${checks} checks passed`)
