/**
 * منطق widget المحادثة المباشرة بلا متصفّح: دمج الرسائل، ومتى نسأل
 * الخادم، والروابط الآمنة، ومعرّف الرسالة، والحفظ في المتصفّح.
 *
 * يُشغَّل بـ node مباشرةً: Node 22 يحذف أنواع TypeScript من ملفّات .ts.
 */
import { mergeMessages, addPending, markFailed, lastSeq, firstSeq, unreadCount, lastOutboundSeq } from '../../resources/widget/src/core/store.ts'
import { pollInterval, shouldConnectRealtime, isLeader, parseLock, POLL_OPEN_MS, POLL_CLOSED_MS } from '../../resources/widget/src/core/scheduler.ts'
import { linkify, safeHref } from '../../resources/widget/src/core/linkify.ts'
import { uuidv4 } from '../../resources/widget/src/core/uuid.ts'
import { createStorage } from '../../resources/widget/src/core/storage.ts'
import { ApiError, apiRoot } from '../../resources/widget/src/core/api.ts'
import { resolveLanguage, translator } from '../../resources/widget/src/core/i18n.ts'
import { pickFiles, filesPerSend, mediaType } from '../../resources/widget/src/core/files.ts'
import { BRAND_URL } from '../../resources/widget/src/core/brand.ts'
import { phoneMode, sessionFields } from '../../resources/widget/src/core/prechat.ts'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — expected ${e}, got ${a}`)
}
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const msg = (id, seq, extra = {}) => ({ id, seq, client_id: null, direction: 'out', type: 'text', text: id, media: null, interactive: null, status: 'sent', created_at: null, sender: { kind: 'bot' }, ...extra })

// ── الدمج ──
{
    // وصلت ٣ قبل ٢ (عمّال طابور متوازون): الترتيب بـ seq لا بالوصول.
    const list = mergeMessages([msg('a', 1)], [msg('c', 3), msg('b', 2)])
    is(list.map((m) => m.id), ['a', 'b', 'c'], 'الترتيب بـ seq')

    // الصفّ نفسه مرّتين (بثّ ثم مزامنة): لا يتكرّر، والأحدث يغلب.
    const again = mergeMessages(list, [msg('b', 2, { status: 'read' })])
    is(again.length, 3, 'لا تكرار')
    is(again[1].status, 'read', 'الأحدث يغلب')

    // الفقاعة المتفائلة تبقى في الآخر ثم يحلّ محلّها صفّها.
    const pending = addPending(again, { ...msg(null, null), id: null, client_id: 'c-1', direction: 'in', text: 'مرحبا' })
    is(pending.map((m) => m.client_id ?? m.id), ['a', 'b', 'c', 'c-1'], 'المتفائلة في الآخر')
    is(pending[3].local, 'pending', 'معلَّمة pending')
    const saved = mergeMessages(pending, [msg('d', 4, { client_id: 'c-1', direction: 'in' })])
    is(saved.map((m) => m.id), ['a', 'b', 'c', 'd'], 'الصفّ المحفوظ يحلّ محلّ المتفائلة')

    const failed = markFailed(pending, 'c-1')
    is(failed[3].local, 'failed', 'الفشل يُعلَّم على المتفائلة وحدها')

    is(lastSeq(saved), 4, 'آخر seq')
    is(firstSeq(saved), 1, 'أوّل seq')
    is(firstSeq([]), null, 'لا رسائل')

    const mixed = [msg('a', 1, { direction: 'in' }), msg('b', 2), msg('c', 3)]
    is(unreadCount(mixed, 1), 2, 'غير المقروء: الصادر بعد العلامة')
    is(unreadCount(mixed, 3), 0, 'كلّه مقروء')
    is(lastOutboundSeq(mixed), 3, 'آخر صادر')
}

// ── متى نسأل الخادم ──
{
    const base = { open: true, visible: true, realtimeConnected: false, hasConversation: true, leader: true, idleMs: 0 }
    is(pollInterval(base), POLL_OPEN_MS, 'مفتوحة: ٤ ثوانٍ')
    is(pollInterval({ ...base, open: false }), POLL_CLOSED_MS, 'مغلقة: ٣٠ ثانية')
    is(pollInterval({ ...base, visible: false }), null, 'التاب مخفيّ: لا شيء')
    is(pollInterval({ ...base, realtimeConnected: true }), null, 'الاتصال اللحظي يغني')
    is(pollInterval({ ...base, leader: false }), null, 'غير القائد لا يسأل')
    is(pollInterval({ ...base, hasConversation: false }), null, 'بلا محادثة لا شيء')
    is(pollInterval({ ...base, idleMs: 31 * 60 * 1000 }), null, 'بعد نصف ساعة خمول يتوقّف')

    ok(shouldConnectRealtime({ open: true, visible: false, hasConversation: true, idleMs: 0 }), 'النافذة مفتوحة: اتصال')
    ok(!shouldConnectRealtime({ open: false, visible: true, hasConversation: false, idleMs: 0 }), 'بلا محادثة: لا اتصال')
    ok(!shouldConnectRealtime({ open: true, visible: true, hasConversation: true, idleMs: 11 * 60 * 1000 }), 'الخمول يقطع الاتصال')

    ok(isLeader(null, 'me', 1000), 'لا قفل: أنا القائد')
    ok(isLeader({ id: 'me', ts: 0 }, 'me', 1000), 'قفلي أنا')
    ok(!isLeader({ id: 'other', ts: 1000 }, 'me', 5000), 'قفل حيّ لغيري')
    ok(isLeader({ id: 'other', ts: 0 }, 'me', 20000), 'قفل منتهٍ: أتولّى')
    is(parseLock('not json'), null, 'قفل تالف')
    is(parseLock('{"id":"x","ts":5}'), { id: 'x', ts: 5 }, 'قفل سليم')
}

// ── الروابط: لا HTML، ولا javascript: ──
{
    const parts = linkify('زر https://shop.example.com/p?id=1، أو <b>javascript:alert(1)</b>.')
    is(parts.filter((p) => p.kind === 'link').map((p) => p.href), ['https://shop.example.com/p?id=1'], 'رابط واحد بلا الفاصلة العربية')
    ok(parts.some((p) => p.kind === 'text' && p.text.includes('<b>javascript:alert(1)</b>')), 'الوسوم تبقى نصّاً')
    is(safeHref('javascript:alert(1)'), null, 'javascript مرفوض')
    is(safeHref('data:text/html,x'), null, 'data مرفوض')
    is(safeHref('https://a.example/x'), 'https://a.example/x', 'https مقبول')
    is(linkify(''), [], 'نصّ فارغ')
}

// ── معرّف الرسالة بلا crypto.randomUUID (موقع http) ──
{
    const pattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
    ok(pattern.test(uuidv4()), 'uuid بالطريقة الأصلية')

    const original = Object.getOwnPropertyDescriptor(globalThis, 'crypto')
    const real = globalThis.crypto
    try {
        Object.defineProperty(globalThis, 'crypto', { value: { getRandomValues: (b) => real.getRandomValues(b) }, configurable: true })
        const id = uuidv4()
        ok(pattern.test(id), `uuid بلا randomUUID: ${id}`)
        ok(uuidv4() !== id, 'لا يتكرّر')
    } finally {
        if (original) Object.defineProperty(globalThis, 'crypto', original)
    }
}

// ── الحفظ في المتصفّح، ومتصفّح يمنعه ──
{
    const backing = new Map()
    const memory = { getItem: (k) => backing.get(k) ?? null, setItem: (k, v) => backing.set(k, v), removeItem: (k) => backing.delete(k) }
    const store = createStorage('wc_test', memory)
    store.write({ token: 't1', lastReadSeq: 4 })
    is(store.read(), { token: 't1', lastReadSeq: 4 }, 'يُحفظ ويُقرأ')
    store.write({ lastReadSeq: 9 })
    is(store.read().token, 't1', 'التحديث الجزئي لا يمسح')
    store.clear()
    is(store.read(), {}, 'المسح')

    const throwing = { getItem: () => { throw new Error('blocked') }, setItem: () => { throw new Error('blocked') }, removeItem: () => { throw new Error('blocked') } }
    const safe = createStorage('wc_test', throwing)
    safe.write({ token: 'x' })
    is(safe.read(), {}, 'متصفّح يمنع الحفظ لا يُسقط الـ widget')

    const noStore = createStorage('wc_test', null)
    noStore.write({ token: 'mem' })
    is(noStore.read().token, 'mem', 'بلا تخزين: يبقى في الذاكرة')
}

// ── متفرّقات ──
{
    is(apiRoot('https://app.example.com/', 'wc_abc'), 'https://app.example.com/api/webchat/v1/w/wc_abc', 'مسار الواجهة')
    const error = new ApiError('x', 401, 'unauthorized')
    is([error.status, error.code], [401, 'unauthorized'], 'خطأ الواجهة يحمل حالته')
    is(resolveLanguage('auto', 'ar-SA', 'en'), 'ar', 'لغة الموقع')
    is(resolveLanguage('en', 'ar', 'ar'), 'en', 'الإعداد يغلب')
    is(translator('ar')('send'), 'إرسال', 'الترجمة')
}

// ── ملفات الزائر: ثلاثة في المرّة، وما يُرفض يُرفض قبل الرفع ──
{
    const MB = 1024 * 1024
    const limits = { media: { image: 2 * MB, video: 25 * MB, audio: 16 * MB, document: 25 * MB }, accept: ['image/png', 'image/jpeg', 'application/pdf', 'video/mp4'], files_per_send: 3 }
    const f = (name, type, size = 1000) => ({ name, type, size })

    const five = pickFiles([f('1.png', 'image/png'), f('2.png', 'image/png'), f('3.png', 'image/png'), f('4.png', 'image/png'), f('5.png', 'image/png')], limits)
    is(five.accepted.map((x) => x.name), ['1.png', '2.png', '3.png'], 'خمسة ملفات: يُرسل أوّل ثلاثة')
    ok(five.overflow, 'وتُعلَّم الزيادة')
    is(five.max, 3, 'الحدّ ثلاثة')

    const mixed = pickFiles([f('big.png', 'image/png', 3 * MB), f('x.exe', 'application/x-msdownload'), f('ok.pdf', 'application/pdf', 5 * MB)], limits)
    is(mixed.accepted.map((x) => x.name), ['ok.pdf'], 'المقبول وحده يُرفع')
    is(mixed.rejected, [{ name: 'big.png', reason: 'size' }, { name: 'x.exe', reason: 'type' }], 'والمرفوض باسمه وسببه')
    ok(!mixed.overflow, 'ثلاثة بالضبط ليست زيادة')

    is(pickFiles([f('noext', '', 100)], limits).accepted.length, 1, 'نوعٌ لا يعرفه المتصفّح يُترك للخادم')
    is(filesPerSend({ media: {}, accept: [] }), 3, 'خادم قديم بلا files_per_send: ثلاثة')
    is(filesPerSend({ media: {}, accept: [], files_per_send: 0 }), 3, 'قيمة غير صالحة: ثلاثة')
    is([mediaType('image/webp'), mediaType('video/mp4'), mediaType('audio/ogg'), mediaType('application/pdf')], ['image', 'video', 'audio', 'document'], 'نوع النظام من MIME')

    const ar = translator('ar')
    is(ar('too_many_files').replace('{n}', '3'), 'يمكنك إرسال 3 ملفات كحدّ أقصى في المرة الواحدة.', 'رسالة الحدّ')
    is([ar('powered_by'), ar('brand'), translator('en')('brand')], ['بواسطة', 'منجز شات', 'Mnjz Chat'], 'سطر بواسطة منجز شات')
    is(BRAND_URL, 'https://app.mnjz.net/', 'رابط منجز شات')
}

// ── نموذج الزائر: الجوال بحسب إعداد التاجر ──
{
    const values = { name: 'سارة', phone: '0501234567', country: 'SA', email: 'sara@example.com', consent: true, website: '', elapsed_ms: 4000 }
    const base = { default_country: 'SA', email: 'off', name_label: null, phone_label: null, consent_text: null }
    const withPhone = { name: 'سارة', phone: '0501234567', country: 'SA', website: '', elapsed_ms: 4000 }
    const withoutPhone = { name: 'سارة', website: '', elapsed_ms: 4000 }

    is(phoneMode(base), 'required', 'خادم أقدم من الإعداد: الجوال إجباري')
    is(phoneMode({ ...base, phone: 'nonsense' }), 'required', 'قيمة مجهولة: إجباري')
    is(phoneMode({ ...base, phone: 'optional' }), 'optional', 'اختياري')
    is(phoneMode({ ...base, phone: 'off' }), 'off', 'مخفيّ')

    is(sessionFields({ ...base, phone: 'required' }, values), withPhone, 'الإجباري يُرسل الرقم ودولته')
    is(sessionFields(base, values), withPhone, 'وكذلك الإعداد الغائب')
    is(sessionFields({ ...base, phone: 'optional' }, values), withPhone, 'الاختياري المكتوب يُرسل')
    is(sessionFields({ ...base, phone: 'optional' }, { ...values, phone: '   ' }), withoutPhone, 'الاختياري الفارغ لا يُرسل')
    is(sessionFields({ ...base, phone: 'off' }, values), withoutPhone, 'المخفيّ لا يُرسل ولو بقي رقمٌ في الحالة')
    is(sessionFields({ ...base, email: 'optional', consent_text: 'أوافق' }, values), { name: 'سارة', phone: '0501234567', country: 'SA', email: 'sara@example.com', consent: true, website: '', elapsed_ms: 4000 }, 'البريد والموافقة كما كانا')
    is(sessionFields({ ...base, email: 'optional' }, { ...values, email: '' }), withPhone, 'البريد الفارغ لا يُرسل')
}

if (fail.length) {
    console.error(`✗ ${fail.length}/${checks} failed:\n` + fail.map((f) => '  - ' + f).join('\n'))
    process.exit(1)
}
console.log(`✓ webchat-widget: ${checks} checks`)
