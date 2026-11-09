/**
 * نافذة الأربع والعشرين ساعة: الحساب الذي يعرضه العدّاد بجانب «Mark as closed»
 * ويقرّر به ChatForm هل يُسمح بالإرسال.
 *
 * الاثنان يقرآن من الوحدة نفسها، فلو اختلفا لرأى الموظّف عدّاداً يقول «مفتوحة»
 * وحقل كتابة مقفولاً — أو العكس.
 */
import {
    WINDOW_MS,
    parseUtcDate,
    lastInboundOf,
    windowRemaining,
    isWindowOpen,
} from '../../resources/js/Composables/messagingWindow.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const NOW = Date.parse('2026-06-01T12:00:00Z')
const HOUR = 60 * 60 * 1000
const MIN = 60 * 1000
const ago = (ms) => new Date(NOW - ms).toISOString()

// ------------------------------------------------ الحدود

{
    const r = windowRemaining(ago(0), NOW)
    ok(r.open, 'رسالة وصلت الآن: مفتوحة')
    is(r.hours, 24, 'والمتبقّي 24 ساعة')
    is(r.minutes, 0, 'و0 دقيقة')
    is(r.fraction, 1, 'والحلقة ممتلئة')
    is(r.level, 'ok', 'والمستوى عادي')
}

{
    // المثال الذي طلبه العميل: «باقي 23 ساعة و24 دقيقة»
    const r = windowRemaining(ago(36 * MIN), NOW)
    is(r.hours, 23, 'بعد 36 دقيقة: 23 ساعة')
    is(r.minutes, 24, 'و24 دقيقة')
    is(r.level, 'ok', 'مستوى عادي')
}

{
    const r = windowRemaining(ago(24 * HOUR), NOW)
    ok(!r.open, 'بعد 24 ساعة بالضبط: مغلقة')
    is(r.level, 'closed', 'مستوى مغلق')
    is(r.remainingMs, 0, 'لا متبقّي')
    is(r.fraction, 0, 'حلقة فارغة')
}

{
    const r = windowRemaining(ago(24 * HOUR - 1), NOW)
    ok(r.open, 'قبل الإغلاق بجزء من الثانية: ما زالت مفتوحة')
    is(r.minutes, 1, 'والدقائق ceil — لا تُعرض 0 والنافذة مفتوحة')
    is(r.hours, 0, 'و0 ساعة')
    is(r.level, 'critical', 'ومستوى حرج')
}

ok(!windowRemaining(ago(30 * HOUR), NOW).open, 'بعد 30 ساعة: مغلقة')

// ------------------------------------------------ مستويات التحذير

is(windowRemaining(ago(18 * HOUR - 1), NOW).level, 'ok', 'أكثر من 6 ساعات متبقّية: عادي')
is(windowRemaining(ago(18 * HOUR), NOW).level, 'warning', 'عند 6 ساعات: تحذير')
is(windowRemaining(ago(23 * HOUR - 1), NOW).level, 'warning', 'أكثر من ساعة: ما زال تحذيراً')
is(windowRemaining(ago(23 * HOUR), NOW).level, 'critical', 'عند ساعة: حرج')

{
    const r = windowRemaining(ago(12 * HOUR), NOW)
    is(r.fraction, 0.5, 'نصف الوقت: نصف الحلقة')
}

// ------------------------------------------------ التوقيت

{
    // «2026-06-01 11:00:00» بلا منطقة هو UTC لا توقيت الجهاز
    const d = parseUtcDate('2026-06-01 11:00:00')
    is(d.toISOString(), '2026-06-01T11:00:00.000Z', 'نصّ بلا منطقة يُفهم UTC')

    const r = windowRemaining('2026-06-01 11:00:00', NOW)
    is(r.hours, 23, 'ساعة مضت: 23 ساعة متبقّية')
}

is(parseUtcDate('2026-06-01T11:00:00+00:00').toISOString(), '2026-06-01T11:00:00.000Z', 'ISO بإزاحة يبقى كما هو')
is(parseUtcDate(null), null, 'null يبقى null')
is(parseUtcDate(''), null, 'نصّ فارغ يبقى null')
is(parseUtcDate('ليس تاريخاً'), null, 'نصّ لا يُقرأ يبقى null')

{
    // ساعة جهاز متقدّمة: الرسالة «في المستقبل» — لا نعرض أكثر من 24 ساعة
    const r = windowRemaining(new Date(NOW + 5 * MIN).toISOString(), NOW)
    ok(r.open, 'رسالة في المستقبل: مفتوحة')
    is(r.hours, 24, 'لكن لا تزيد على 24 ساعة')
    is(r.minutes, 0, 'ولا دقائق زائدة')
    ok(r.remainingMs <= WINDOW_MS, 'المتبقّي لا يتجاوز النافذة')
}

// ------------------------------------------------ لا رسالة واردة

{
    const r = windowRemaining(null, NOW)
    ok(!r.open, 'بلا رسالة واردة: مغلقة')
    is(r.level, 'closed', 'ومستوى مغلق')
}

ok(!windowRemaining(undefined, NOW).open, 'undefined: مغلقة')
ok(!windowRemaining('لا شيء', NOW).open, 'تاريخ غير مقروء: مغلقة')

// ------------------------------------------------ قراءة آخر رسالة من المحادثة

is(lastInboundOf({ last_inbound_chat_created_at_iso: 'A', last_inbound_chat: { created_at_iso: 'B' } }), 'A', 'الأولوية للحقل الأوّل')
is(lastInboundOf({ last_inbound_chat: { created_at_iso: 'B', created_at: 'C' } }), 'B', 'ثم created_at_iso')
is(lastInboundOf({ last_inbound_chat: { created_at: 'C' } }), 'C', 'ثم created_at')
is(lastInboundOf({}), null, 'لا شيء: null')
is(lastInboundOf(null), null, 'محادثة null: null')

// ------------------------------------------------ القرار الذي يستعمله ChatForm

ok(isWindowOpen({ last_inbound_chat_created_at_iso: ago(HOUR) }, NOW), 'وارد قبل ساعة: مفتوحة')
ok(!isWindowOpen({ last_inbound_chat_created_at_iso: ago(25 * HOUR) }, NOW), 'وارد قبل 25 ساعة: مغلقة')
ok(isWindowOpen({ is_messaging_window_open: true }, NOW), 'بلا تاريخ: يُؤخذ علم الخادم — مفتوحة')
ok(!isWindowOpen({ is_messaging_window_open: false }, NOW), 'وعلم الخادم false: مغلقة')
ok(!isWindowOpen({}, NOW), 'لا تاريخ ولا علم: مغلقة')
ok(!isWindowOpen(null, NOW), 'محادثة null: مغلقة')

{
    // التاريخ يغلب العلم: علم قديم لا يفتح نافذة انتهت
    ok(!isWindowOpen({ last_inbound_chat_created_at_iso: ago(30 * HOUR), is_messaging_window_open: true }, NOW), 'التاريخ المنتهي يغلب علم الخادم')
}

// العدّاد وحقل الكتابة يتّفقان دائماً: كلاهما من الدالة نفسها
for (const elapsed of [0, MIN, HOUR, 12 * HOUR, 24 * HOUR - MIN, 24 * HOUR, 25 * HOUR]) {
    const contact = { last_inbound_chat_created_at_iso: ago(elapsed) }
    is(
        isWindowOpen(contact, NOW),
        windowRemaining(lastInboundOf(contact), NOW).open,
        `العدّاد وحقل الكتابة يتّفقان عند مرور ${elapsed / MIN} دقيقة`,
    )
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
