/**
 * سطر الحالة في بطاقات صفحة القنوات: مربوط، يحتاج انتباهاً، غير مربوط،
 * أو غير مُعدّ على الخادم — من البطاقة كما يرسلها الخادم.
 */
import { channelCardStatus, emailStatus, webChatStatus, whatsappStatus, toneDotClass } from '../../resources/js/Composables/channelStatus.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

const account = (overrides = {}) => ({ is_active: true, needs_reauthorization: false, username: null, name: null, last_error: null, ...overrides })

// ------------------------------------- قنوات تُربط من الإعدادات

is(channelCardStatus({ account: account({ username: 'support_bot', name: 'بوت' }) }),
    { key: 'Connected', tone: 'success', detail: '@support_bot' }, 'المربوط يعرض اسم المستخدم')
is(channelCardStatus({ account: account({ name: 'متجر الرياض' }) }),
    { key: 'Connected', tone: 'success', detail: 'متجر الرياض' }, 'وبلا اسم مستخدم يعرض الاسم')
is(channelCardStatus({ account: account({ needs_reauthorization: true, is_active: false, last_error: 'expired' }) }),
    { key: 'Needs attention', tone: 'warning', detail: 'expired' }, 'الصلاحية المنتهية تحتاج انتباهاً')
is(channelCardStatus({ account: account({ is_active: false }) }).key, 'Needs attention', 'المعطّل يحتاج انتباهاً')
is(channelCardStatus({ account: null, uses_oauth: true, platform_ready: false }),
    { key: 'Not set up on the server', tone: 'muted', detail: null }, 'تفويض بلا تطبيق على الخادم')
is(channelCardStatus({ account: null, uses_oauth: true, platform_ready: true }).key, 'Not connected', 'تفويض جاهز بلا حساب')
is(channelCardStatus({ account: null, uses_oauth: false, platform_ready: true }).key, 'Not connected', 'تيليجرام بلا حساب')
is(channelCardStatus(null).key, 'Not connected', 'بطاقة غائبة لا تُسقط الصفحة')

// ------------------------------------- واتساب والمحادثة المباشرة

is(whatsappStatus(true).key, 'Connected', 'واتساب مربوط')
is(whatsappStatus(false).key, 'Not connected', 'واتساب غير مربوط')
is(webChatStatus({ exists: true, enabled: true }).key, 'Enabled', 'المحادثة مفعّلة')
is(webChatStatus({ exists: true, enabled: false }).key, 'Disabled', 'المحادثة موقوفة')
is(webChatStatus({ exists: false, enabled: false }).key, 'Not set up yet', 'لم تُنشأ بعد')
is(webChatStatus(undefined).key, 'Not set up yet', 'بلا ملخّص')

// ------------------------------------- البريد (SMTP التاجر)

is(emailStatus({ connected: true, from_address: 'sales@shop.example' }), { key: 'Connected', tone: 'success', detail: 'sales@shop.example' }, 'البريد مربوط')
is(emailStatus({ connected: false, needs_attention: true, from_address: 'x@y.z' }).key, 'Needs attention', 'دخول مرفوض')
is(emailStatus(null).key, 'Not connected', 'بلا حساب')

// ------------------------------------- الألوان

is(toneDotClass('success'), 'bg-green-500', 'أخضر للمربوط')
is(toneDotClass('warning'), 'bg-amber-400', 'كهرماني للانتباه')
is(toneDotClass('anything'), 'bg-slate-300', 'رمادي لغير المعروف')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`channel-status: ${checks} checks passed`)
