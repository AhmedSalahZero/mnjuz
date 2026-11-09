/**
 * شكل نافذة الـ24 ساعة كما يراه الموظّف: شارة الترويسة ولافتة حقل الكتابة.
 *
 * الشكوى: الحالة المغلقة كانت حلقة رمادية فارغة بساعة ونصّ باهت — تبدو معطّلة
 * أو جارية التحميل — وتحت xl حلقة وحدها بلا نصّ. واللافتة كانت تنتقل إلى
 * الصفّ بحسب عرض الشاشة (sm:flex) لا عرض لوحة المحادثة، فيُعصر نصّها في عمود.
 *
 * هنا يُترجَم المكوّنان فعلاً ويُرسمان بـ @vue/server-renderer، فالفحص على
 * الناتج لا على نصّ الملف.
 */
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'
import { parse, compileScript } from '@vue/compiler-sfc'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'

let checks = 0
const fail = []
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const ROOT = new URL('../../', import.meta.url)
const tmp = mkdtempSync(join(tmpdir(), 'window-ui-'))

// ترجمة وهمية تعيد المفتاح بعد التعويض — كافية لمعرفة أيّ نصّ عُرض.
const t = (key, params = {}) => key.replace(/\{(\w+)\}/g, (_, name) => params[name] ?? `{${name}}`)
const transStub = join(tmp, 'useTrans.mjs')
writeFileSync(transStub, 'export const useTrans = () => globalThis.__t\n')
globalThis.__t = t

const load = async (relative) => {
    const filename = relative.split('/').pop()
    const { descriptor, errors } = parse(readFileSync(new URL(relative, ROOT), 'utf8'), { filename })
    ok(errors.length === 0, `${filename}: خطأ في التحليل — ${errors.map((e) => e.message).join('؛ ')}`)

    const code = compileScript(descriptor, { id: filename, inlineTemplate: true }).content
        .replace(/from ['"]vue['"]/g, `from '${import.meta.resolve('vue')}'`)
        .replace(/from ['"]@\/Composables\/useTrans['"]/g, `from '${pathToFileURL(transStub)}'`)
        .replace(/from ['"]@\/Composables\/messagingWindow['"]/g, `from '${new URL('resources/js/Composables/messagingWindow.js', ROOT)}'`)

    ok(!/from ['"]@\//.test(code), `${filename}: استيراد @/ لم يُحلّ في الاختبار`)

    const file = join(tmp, filename.replace('.vue', '.mjs'))
    writeFileSync(file, code)

    return (await import(pathToFileURL(file))).default
}

const render = (component, props) => {
    const app = createSSRApp({ render: () => h(component, props) })
    app.config.globalProperties.$t = t

    return renderToString(app)
}

const HOUR = 60 * 60 * 1000
const inboundAgo = (ms) => ({ last_inbound_chat_created_at_iso: new Date(Date.now() - ms).toISOString() })

try {
    // ------------------------------------------------ شارة الترويسة

    const Countdown = await load('resources/js/Components/ChatComponents/MessagingWindowCountdown.vue')

    {
        const html = await render(Countdown, { contact: inboundAgo(30 * HOUR) })

        ok(html.includes('data-level="closed"'), 'مغلقة: المستوى ليس closed')
        ok(html.includes('bg-red-50') && html.includes('text-red-700'), 'مغلقة: الشارة ليست حمراء — تعود رمادية تبدو معطّلة')
        ok(!/slate|gray/.test(html), 'مغلقة: ما زال فيها لون رمادي')
        ok(/<rect[^>]*rx="2"/.test(html) && html.includes('M8 11V7a4 4 0 0 1 8 0v4'), 'مغلقة: بلا أيقونة القفل')
        ok(!html.includes('stroke-dasharray'), 'مغلقة: ما زالت حلقة العدّ الفارغة')
        ok(html.includes('>24-hour window closed<') || html.includes(' 24-hour window closed '), 'مغلقة: بلا نصّ الإغلاق')
        ok(html.includes('title="The customer has not messaged in over 24 hours.'), 'مغلقة: التلميح لا يشرح السبب')
    }

    for (const [label, elapsed, level, pill, compact] of [
        ['مفتوحة', 2 * HOUR, 'ok', 'bg-green-50', '22:00'],
        ['تحذير', 20 * HOUR, 'warning', 'bg-amber-50', '04:00'],
        ['حرجة', 23.5 * HOUR, 'critical', 'bg-red-50', '00:30'],
    ]) {
        const html = await render(Countdown, { contact: inboundAgo(elapsed) })

        ok(html.includes(`data-level="${level}"`), `${label}: المستوى ليس ${level}`)
        ok(html.includes(pill), `${label}: لون الشارة ليس ${pill}`)
        ok(html.includes('stroke-dasharray'), `${label}: بلا حلقة المتبقّي`)
        ok(!html.includes('M8 11V7a4 4 0 0 1 8 0v4'), `${label}: قفل على نافذة مفتوحة`)
        // الدقيقة قد تتقدّم بين حساب الوقت والرسم؛ الساعات ثابتة.
        ok(html.includes(`>${compact.slice(0, 3)}`) || html.includes(` ${compact.slice(0, 3)}`), `${label}: الوقت المختصر ليس ${compact}`)
        ok(html.includes('dir="ltr"'), `${label}: الوقت بلا dir=ltr — ينقلب في العربية`)
    }

    // ------------------------------------------------ لافتة حقل الكتابة

    const Banner = await load('resources/js/Components/ChatComponents/Chat24HourComposeBanner.vue')

    {
        const html = await render(Banner, { sendingAuthTemplate: false })
        const root = html.match(/<div class="([^"]+)"/)?.[1] ?? ''

        ok(root.split(' ').includes('flex-wrap'), 'اللافتة: بلا flex-wrap — يُعصر النصّ بجانب الأزرار')
        ok(!/\bsm:flex\b/.test(root), 'اللافتة: ما زال الصفّ يتبع عرض الشاشة (sm:flex) لا عرضها')
        ok(html.includes('min-w-[15rem]'), 'اللافتة: النصّ بلا حدّ أدنى للعرض فيضيق قبل أن تنزل الأزرار')
        ok(html.includes('24-hour window closed'), 'اللافتة: بلا العنوان')
        ok(html.includes('The customer has not messaged in over 24 hours. WhatsApp only allows template messages until they reply.'), 'اللافتة: بلا الشرح الجديد')
        ok(!html.includes('24 hour limit') && !html.includes('Whatsapp does not allow'), 'اللافتة: النصّ القديم ما زال')
        ok(html.includes('M8 11V7a4 4 0 0 1 8 0v4'), 'اللافتة: بلا القفل الذي في الترويسة')
        ok(html.includes('>Templates<') && html.includes('>Send Template<'), 'اللافتة: فقدت أحد الزرّين')
        ok(!html.includes('animateTransform'), 'اللافتة: مؤشّر الإرسال ظاهر وهي لا ترسل')
    }

    {
        const html = await render(Banner, { sendingAuthTemplate: true })
        ok(html.includes('animateTransform') && /<button[^>]*disabled/.test(html), 'اللافتة أثناء الإرسال: الزرّ غير معطّل أو بلا مؤشّر')
    }
} finally {
    rmSync(tmp, { recursive: true, force: true })
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
