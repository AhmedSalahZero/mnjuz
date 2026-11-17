/**
 * رسم فقاعات الـ widget في متصفّح حقيقي، بـ widget.css الفعلي.
 *
 * بلا متصفّح لا يظهر عيبٌ كالذي وصل من التاجر: زرّ «تقييم مستوى الخدمة»
 * مرسوم فوق السطر الأخير من نصّ الرسالة. هنا نقيس المستطيلات نفسها:
 * كل زرّ تحت النص، ولا عنصرين داخل الفقاعة يتقاطعان، ولا شيء يخرج منها.
 *
 * يُشغَّل من جذر المشروع: node tests/js/webchat-widget-render.mjs
 * يحتاج Chrome (CHROME_PATH أو /usr/bin/google-chrome). بدونه يخرج بالرمز 3.
 */
import { build } from 'esbuild'
import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const chrome = process.env.CHROME_PATH || '/usr/bin/google-chrome'

if (!existsSync(chrome)) {
    console.log(`skip: Chrome not found at ${chrome}`)
    process.exit(3)
}

const { default: puppeteer } = await import(resolve(root, 'node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js'))

const harness = `
import { render } from 'preact'
import { MessageList } from './resources/widget/src/app/MessageList.tsx'
import { Widget } from './resources/widget/src/app/Widget.tsx'
import { translator } from './resources/widget/src/core/i18n.ts'

const bot = { kind: 'bot' }
const at = '2026-11-10T03:39:00Z'
const out = (id, text, interactive) => ({ id, seq: null, client_id: null, direction: 'out', type: 'text', text, media: null, interactive, status: 'sent', created_at: at, sender: bot })

const scenarios = {
    // ما أرسله التاجر: نصّ الاستبيان على سطرين وزرّ الرابط.
    cta: [out('m1', 'شكراً لتواصلك معنا 🌟 يسعدنا تقييمك لمستوى الخدمة', { kind: 'cta_url', display_text: 'تقييم مستوى الخدمة', url: 'https://example.com/rate/abc' })],
    ctaShort: [out('m1', 'قيّمنا', { kind: 'cta_url', display_text: 'تقييم مستوى الخدمة', url: 'https://example.com/rate/abc' })],
    buttons: [out('m1', 'اختر القسم الذي تريد التواصل معه من فضلك حتى نخدمك بشكل أسرع', { kind: 'buttons', buttons: [{ id: 'b1', title: 'المبيعات' }, { id: 'b2', title: 'الدعم الفني' }, { id: 'b3', title: 'الشكاوى' }] })],
    list: [out('m1', 'اختر من القائمة', { kind: 'list', button: 'القائمة', footer: 'نسعد بخدمتك', sections: [{ title: 'خدمات', rows: [{ id: 'r1', title: 'طلب جديد' }] }] })],
}

// الـ widget كاملاً بواجهة وهمية: ما يُطلب يُسجَّل، والجلسة ترفض رقماً
// يقبله المتصفّح ويرفضه الخادم (+15550001234) كما يفعل الخادم.
window.__requests = []
window.fetch = async (url, init = {}) => {
    const body = init.body && typeof init.body === 'string' ? JSON.parse(init.body) : null
    window.__requests.push({ url: String(url), method: init.method || 'GET', body })
    const json = (status, data) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } })

    if (String(url).includes('/sessions')) {
        if (window.__failSession) {
            window.__failSession = false
            return json(429, { message: 'Too Many Attempts.' })
        }

        return body.answers.phone === '+15550001234'
            ? json(422, { error: 'validation_failed', errors: { phone: ['أدخل رقم جوال صحيحاً.'] } })
            : json(201, { token: 'tok', visitor: { id: 'v1', name: body.answers.name }, channel: null })
    }

    if (String(url).endsWith('/faqs')) {
        return json(200, { faqs: [
            { id: 7, question_ar: 'كم مدة التوصيل؟', question_en: null, answer_ar: 'يومان', answer_en: null, image_url: null },
            { id: 9, question_ar: 'طرق الدفع؟', question_en: null, answer_ar: 'مدى وفيزا', answer_en: null, image_url: 'https://app.test/media/cards.png' },
        ] })
    }

    if (String(url).includes('/messages') && init.method === 'POST') {
        return json(202, { client_message_id: body.client_message_id, status: 'pending' })
    }

    return String(url).includes('/messages') ? json(200, { messages: [], has_more: false }) : json(200, {})
}

window.mountWidget = (options = {}) => {
    const host = document.getElementById('host')
    render(null, host)
    const config = {
        widget: {
            name: 'متجر',
            appearance: { color: '#16a34a', position: 'right', offset_x: 20, offset_y: 20, logo_url: null, title: 'تحدّث معنا', subtitle: '', welcome_message: 'أهلاً بك', language: 'ar' },
            prechat: {
                default_country: 'SA', phone: 'required', email: 'optional', name_label: null, phone_label: null,
                consent_text: options.consent ?? null,
                handoff_message: options.handoff ?? null,
                handoff_message_en: options.handoffEn ?? null,
                questions: [
                    { id: 'name', target: 'name', validation: 'text', required: true, label_ar: null, label_en: null },
                    { id: 'phone', target: 'phone', validation: 'phone', required: true, label_ar: null, label_en: null },
                    { id: 'email', target: 'email', validation: 'email', required: false, label_ar: null, label_en: null },
                    { id: 'f_city', target: 'field', validation: 'select', required: true, label_ar: 'مدينتك؟', label_en: 'Your city?', options: ['الرياض', 'جدة'] },
                ],
            },
            behavior: { show_agent_names: true, sound: false, uploads: true },
        },
        available: true, open_now: true, realtime: null, bundle: null,
        limits: { text: 2000, media: {}, accept: [], files_per_send: 3 },
        faqs: options.faqs ?? 0,
    }
    if (options.questions) config.widget.prechat.questions = options.questions
    // نقاط الكتابة: 0 (بلا نقاط) ما لم يطلبها السيناريو، فتبقى انتظارات البقية قصيرة.
    render(<Widget ctx={{ base: 'https://app.test', key: 'wc_test', config, preview: false, open: true, typingMs: options.typingMs ?? 0 }} />, host)
}

window.renderScenario = (name) => {
    const host = document.getElementById('host')
    render(null, host)
    render(
        <div class="mnjz-root" dir="rtl" style="width:370px">
            <MessageList messages={scenarios[name]} t={translator('ar')} lang="ar" hasMore={false} loadingEarlier={false} onEarlier={() => {}} onChoose={() => {}} onRetry={() => {}} welcome="" />
        </div>,
        host,
    )
}
`

const bundle = await build({
    stdin: { contents: harness, resolveDir: root, loader: 'tsx', sourcefile: 'harness.tsx' },
    bundle: true,
    write: false,
    format: 'iife',
    jsx: 'automatic',
    jsxImportSource: 'preact',
    logLevel: 'silent',
})

const css = readFileSync(resolve(root, 'resources/widget/src/widget.css'), 'utf8')
const html = `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body><div id="host"></div><script>${bundle.outputFiles[0].text}</script></body></html>`

let checks = 0
const fail = []
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const browser = await puppeteer.launch({ executablePath: chrome, headless: 'new', args: ['--no-sandbox'] })

try {
    const page = await browser.newPage()
    await page.setViewport({ width: 420, height: 800 })
    const errors = []
    page.on('pageerror', (e) => errors.push(e.message))
    await page.setContent(html, { waitUntil: 'load' })

    for (const name of ['cta', 'ctaShort', 'buttons', 'list']) {
        await page.evaluate((n) => window.renderScenario(n), name)
        const layout = await page.evaluate(() => {
            const bubble = document.querySelector('.mnjz-bubble')
            const rect = (el) => {
                const r = el.getBoundingClientRect()
                return { top: r.top, bottom: r.bottom, left: r.left, right: r.right }
            }
            // أسطر النصّ نفسها لا صندوق العنصر: مستطيلات كل سطر.
            const text = bubble.querySelector('.mnjz-text')
            const range = document.createRange()
            range.selectNodeContents(text)
            const lines = [...range.getClientRects()].map((r) => ({ top: r.top, bottom: r.bottom }))
            const choices = [...bubble.querySelectorAll('.mnjz-choice')].map(rect)
            const blocks = [...bubble.children].map((el) => ({ cls: el.className, ...rect(el) }))

            return { bubble: rect(bubble), lines, choices, blocks }
        })

        const textBottom = Math.max(...layout.lines.map((l) => l.bottom))
        ok(layout.choices.length > 0, `${name}: no choice rendered`)

        for (const [i, c] of layout.choices.entries()) {
            ok(c.top >= textBottom, `${name}: choice ${i} starts at ${c.top.toFixed(1)} above the last text line ending at ${textBottom.toFixed(1)}`)
            ok(c.left >= layout.bubble.left - 0.5 && c.right <= layout.bubble.right + 0.5, `${name}: choice ${i} overflows the bubble horizontally`)
            ok(c.bottom <= layout.bubble.bottom + 0.5, `${name}: choice ${i} overflows the bubble vertically`)
        }

        // الأبناء المباشرون للفقاعة يتتابعون رأسياً بلا تداخل.
        for (let i = 1; i < layout.blocks.length; i++) {
            const prev = layout.blocks[i - 1]
            const cur = layout.blocks[i]
            ok(cur.top >= prev.bottom - 0.5, `${name}: "${cur.cls}" overlaps "${prev.cls}" (${cur.top.toFixed(1)} < ${prev.bottom.toFixed(1)})`)
        }
    }

    // ── أسئلة البداية في الـ widget كاملاً، على صفحة https (تخزين حقيقي) ──
    const site = await browser.newPage()
    await site.setViewport({ width: 420, height: 800 })
    site.on('pageerror', (e) => errors.push(e.message))
    await site.setRequestInterception(true)
    site.on('request', (request) => request.url().startsWith('https://shop.test/')
        ? request.respond({ status: 200, contentType: 'text/html', body: html })
        : request.abort())
    await site.goto('https://shop.test/')

    const view = () => site.evaluate(() => {
        const bubbles = [...document.querySelectorAll('.mnjz-bubble')].map((b) => b.querySelector('.mnjz-text')?.textContent ?? '')
        const input = document.querySelector('.mnjz-answer')

        return {
            last: bubbles[bubbles.length - 1],
            bubbles,
            choices: [...document.querySelectorAll('.mnjz-bubble:last-of-type .mnjz-choice, .mnjz-row:last-child .mnjz-choice')].map((c) => c.textContent.trim()),
            input: input ? { type: input.type, inputMode: input.inputMode, dir: input.getAttribute('dir') } : null,
            country: !!document.querySelector('.mnjz-country'),
            skip: !!document.querySelector('.mnjz-skip'),
            hint: document.querySelector('.mnjz-hint')?.textContent ?? null,
            composer: !!document.querySelector('.mnjz-composer textarea'),
            dir: document.querySelector('.mnjz-root').getAttribute('dir'),
        }
    })
    // مستطيلات سؤال الجوال: الحبّة والرقم وزرّ الإرسال والحقل كلّه.
    const phoneLayout = () => site.evaluate(() => {
        const rect = (el) => {
            const r = el.getBoundingClientRect()
            return { left: r.left, right: r.right, cy: r.top + r.height / 2 }
        }
        const chip = document.querySelector('.mnjz-country')
        const code = chip.querySelector('.mnjz-country-code')
        const select = chip.querySelector('select')
        const style = getComputedStyle(code)

        return {
            code: code.textContent,
            chip: rect(chip),
            codeBox: rect(code),
            caret: rect(chip.querySelector('.mnjz-country-caret')),
            input: rect(document.querySelector('.mnjz-answer')),
            send: rect(document.querySelector('.mnjz-compose .mnjz-send')),
            pill: rect(document.querySelector('.mnjz-compose')),
            fontSize: parseFloat(style.fontSize),
            fontWeight: Number(style.fontWeight),
            background: getComputedStyle(chip).backgroundColor,
            selectFontSize: parseFloat(getComputedStyle(select).fontSize),
            label: select.getAttribute('aria-label'),
            firstOption: select.options[0].textContent,
        }
    })
    const reply = async (text) => {
        // السؤال الجديد يفرّغ خانته بعد الرسم: الكتابة قبلها يضيع أوّل حرف منها.
        await site.waitForFunction(() => {
            const input = document.querySelector('.mnjz-answer')
            return input && input.value === '' && document.activeElement === input
        })
        await site.type('.mnjz-answer', text)
        await site.keyboard.press('Enter')
        await new Promise((r) => setTimeout(r, 80))
    }

    await site.evaluate(() => window.mountWidget())
    await site.waitForSelector('.mnjz-answer')

    // خانة الإجابة بلا إطار مستطيل حولها: التركيز تُظهره الحبّة كلّها (كخانة الكتابة).
    const focusLook = await site.evaluate(() => {
        const input = document.querySelector('.mnjz-answer')
        return {
            focused: document.activeElement === input && input.matches(':focus-visible'),
            outline: getComputedStyle(input).outlineStyle,
            ring: getComputedStyle(document.querySelector('.mnjz-compose')).boxShadow,
        }
    })
    ok(focusLook.focused, 'intake focus: the answer input is focused and :focus-visible (the case that drew the box)')
    ok(focusLook.outline === 'none', `intake focus: no outline box around the answer input, got ${focusLook.outline}`)
    ok(focusLook.ring !== 'none', 'intake focus: the rounded field still shows the focus ring')

    let v = await view()
    ok(v.bubbles[0] === 'أهلاً بك' && v.last === 'ما اسمك؟', `intake: welcome then the name question, got ${JSON.stringify(v.bubbles)}`)
    ok(v.input?.type === 'text' && !v.skip, 'intake: the required name has a text input and no skip')

    await reply('سارة')
    v = await view()
    ok(v.last === 'ما رقم جوالك؟', `intake: the phone question next, got ${v.last}`)
    ok(v.input?.type === 'tel' && v.input?.inputMode === 'tel' && v.input?.dir === 'ltr' && v.country, 'intake: the phone input is tel, left-to-right, with its country')

    // رمز الدولة (شكوى Waz: لم يره الزائر). حبّة واضحة يسار الرقم و«+966»
    // في وسطها، وزرّ الإرسال في مكانه (يسار الحبّة بالعربية).
    let phone = await phoneLayout()
    ok(phone.code === '+966', `phone: the chip shows the dial code, got ${phone.code}`)
    ok(phone.chip.right <= phone.input.left + 0.5, `phone: the code is left of the number (chip right ${phone.chip.right}, input left ${phone.input.left})`)
    ok(phone.send.right <= phone.chip.left + 0.5, 'phone: the send button keeps its place, left of the code in Arabic')
    ok(Math.abs(phone.chip.cy - phone.pill.cy) <= 1 && Math.abs(phone.input.cy - phone.pill.cy) <= 1, `phone: the chip and the number are vertically centered in the field, got ${JSON.stringify([phone.chip.cy, phone.input.cy, phone.pill.cy])}`)
    ok(Math.abs(phone.codeBox.cy - phone.chip.cy) <= 1, 'phone: the code is vertically centered in its chip')
    ok(Math.abs((phone.codeBox.left - phone.chip.left) - (phone.chip.right - phone.caret.right)) <= 1, `phone: the code and its arrow are horizontally centered, got ${phone.codeBox.left - phone.chip.left} vs ${phone.chip.right - phone.caret.right}`)
    ok(phone.fontSize >= 14 && phone.fontWeight >= 600 && phone.background !== 'rgba(0, 0, 0, 0)', `phone: the code is readable on a visible chip, got ${JSON.stringify([phone.fontSize, phone.fontWeight, phone.background])}`)
    ok(phone.selectFontSize >= 16, `phone: 16px+ on the select so iOS does not zoom, got ${phone.selectFontSize}`)
    ok(phone.label === 'رمز الدولة' && phone.firstOption.includes('السعودية') && phone.firstOption.includes('+966'), `phone: the list names countries in Arabic, got ${JSON.stringify([phone.label, phone.firstOption])}`)

    await site.select('.mnjz-country select', 'AE')
    ok((await phoneLayout()).code === '+971', 'phone: picking another country updates the chip')
    await site.select('.mnjz-country select', 'SA')
    await site.focus('.mnjz-answer')

    await reply('0112345678')
    v = await view()
    ok(v.bubbles.includes('0112345678'), 'intake: the rejected answer stays visible')
    ok(v.last === 'يبدو أنه رقم هاتف أرضي. أدخل رقم جوال.\nما رقم جوالك؟', `intake: the reason then the question again, got ${JSON.stringify(v.last)}`)

    await reply('+15550001234')
    v = await view()
    ok(v.last === 'ما بريدك الإلكتروني؟' && v.skip && v.input?.type === 'email', 'intake: the optional email with a skip chip')

    await site.click('.mnjz-skip')
    await new Promise((r) => setTimeout(r, 80))
    v = await view()
    ok(v.last === 'مدينتك؟' && v.input === null && v.hint === 'اختر من الخيارات أعلاه', 'intake: the select question is answered from its bubble')
    ok(JSON.stringify(v.choices) === JSON.stringify(['الرياض', 'جدة']), `intake: the city options, got ${JSON.stringify(v.choices)}`)

    // جدة ⇐ تنتهي الأسئلة فتُرسل؛ الخادم يرفض الرقم فيُعاد سؤاله بسببه.
    await site.evaluate(() => [...document.querySelectorAll('.mnjz-choice')].find((c) => c.textContent.trim() === 'جدة').click())
    await site.waitForFunction(() => document.querySelector('.mnjz-answer')?.type === 'tel')
    v = await view()
    ok(v.bubbles.includes('+15550001234') && v.last === 'أدخل رقم جوال صحيحاً.\nما رقم جوالك؟', `intake: the server rejection re-asks the phone, got ${JSON.stringify(v.last)}`)
    let sessions = await site.evaluate(() => window.__requests.filter((r) => r.url.includes('/sessions')))
    ok(sessions.length === 1 && sessions[0].url.endsWith('/sessions?lang=ar'), 'intake: one session request in the widget language')
    ok(JSON.stringify(sessions[0].body.answers) === JSON.stringify({ name: 'سارة', phone: '+15550001234', f_city: 'جدة' }), `intake: answers sent, got ${JSON.stringify(sessions[0].body.answers)}`)
    ok(sessions[0].body.countries.phone === 'SA' && sessions[0].body.locale === 'ar' && sessions[0].body.website === '', 'intake: country, locale and honeypot sent')

    await reply('٠٥٠١٢٣٤٥٦٧')
    await site.waitForSelector('.mnjz-composer textarea')
    v = await view()
    sessions = await site.evaluate(() => window.__requests.filter((r) => r.url.includes('/sessions')))
    ok(sessions.length === 2 && sessions[1].body.answers.phone === '0501234567', 'intake: the corrected answers are sent again, got ' + JSON.stringify(sessions.map((r) => r.body.answers)))
    ok(v.composer && v.bubbles.includes('سارة') && v.bubbles.includes('جدة'), 'intake: the chat opens with the answers still visible')

    // اللغة: الزرّ يقلب الاتجاه، والاختيار يبقى بعد تحديث الصفحة.
    await site.click('.mnjz-lang')
    ok((await view()).dir === 'ltr', 'language: switching to English flips the direction')
    await site.reload()
    await site.evaluate(() => window.mountWidget())
    await site.waitForSelector('.mnjz-root')
    ok((await view()).dir === 'ltr', 'language: the choice survives a reload')

    // ── القائمة والأسئلة الشائعة: widget بلا أسئلة بداية، وزائر جديد ──
    const press = async (title) => {
        await site.evaluate((t) => [...document.querySelectorAll('.mnjz-choice')].find((c) => c.textContent.trim() === t).click(), title)
        await new Promise((r) => setTimeout(r, 80))
    }
    const requests = () => site.evaluate(() => window.__requests.map((r) => `${r.method} ${r.url.replace(/^.*\/w\/wc_test\//, '')}`))
    await site.evaluate(() => localStorage.clear())
    await site.reload()
    await site.evaluate(() => window.mountWidget({ questions: [], faqs: 2 }))
    await site.waitForSelector('.mnjz-choice')
    v = await view()
    ok(v.last === 'كيف نقدر نساعدك؟' && JSON.stringify(v.choices) === JSON.stringify(['عندي سؤال', 'عندي مشكلة']), `faq: the menu first, got ${JSON.stringify([v.last, v.choices])}`)
    ok(!v.composer && v.hint === 'اختر من الخيارات أعلاه', 'faq: no typing while in the menu')

    await press('عندي سؤال')
    await site.waitForFunction(() => document.querySelectorAll('.mnjz-choice').length === 3)
    v = await view()
    ok(v.last === 'اختر سؤالك:' && JSON.stringify(v.choices) === JSON.stringify(['كم مدة التوصيل؟', 'طرق الدفع؟', 'لم أجد إجابتي']), `faq: the questions as buttons, got ${JSON.stringify(v.choices)}`)

    await press('طرق الدفع؟')
    v = await view()
    const image = await site.evaluate(() => document.querySelector('.mnjz-messages img.mnjz-image')?.getAttribute('src') ?? null)
    ok(image === 'https://app.test/media/cards.png' && v.bubbles.includes('مدى وفيزا'), `faq: the answer with its image, got ${image}`)
    ok(v.last === 'هل تحتاج شيئاً آخر؟' && JSON.stringify(v.choices) === JSON.stringify(['أسئلة أخرى', 'لم أجد إجابتي']), `faq: more or not found, got ${JSON.stringify(v.choices)}`)
    ok(JSON.stringify(await requests()) === JSON.stringify(['GET faqs']), `faq: reading opens no session, got ${JSON.stringify(await requests())}`)

    await press('لم أجد إجابتي')
    await site.waitForSelector('.mnjz-composer textarea')
    v = await view()
    ok(v.last === 'اكتب سؤالك هنا وسيردّ عليك فريقنا.', `faq: then the chat, got ${v.last}`)

    await site.type('.mnjz-composer textarea', 'متى يصل طلبي؟')
    await site.keyboard.press('Enter')
    await site.waitForFunction(() => window.__requests.filter((r) => r.method === 'POST' && r.url.includes('/messages')).length === 1)
    await new Promise((r) => setTimeout(r, 80))
    await site.type('.mnjz-composer textarea', 'شكراً')
    await site.keyboard.press('Enter')
    await site.waitForFunction(() => window.__requests.filter((r) => r.method === 'POST' && r.url.includes('/messages')).length === 2)
    const sent = await site.evaluate(() => window.__requests.filter((r) => r.method === 'POST' && r.url.includes('/messages')).map((r) => r.body.context ?? null))
    ok(JSON.stringify(sent) === JSON.stringify([{ menu: 'question', faqs: [9], not_found: true }, null]), `faq: what he read goes with the first message only, got ${JSON.stringify(sent)}`)
    ok((await requests()).filter((r) => r.startsWith('POST sessions')).length === 1, 'faq: the session starts with the first message')

    await site.reload()
    await site.evaluate(() => window.mountWidget({ questions: [], faqs: 2 }))
    await site.waitForSelector('.mnjz-composer textarea')
    // إجابة السؤال تنتظر جلب الأسئلة الشائعة (طلبٌ غير متزامن): لا نقرأ قبلها.
    await site.waitForSelector('.mnjz-messages img.mnjz-image')
    v = await view()
    ok(v.bubbles.includes('طرق الدفع؟') && v.bubbles.includes('لم أجد إجابتي'), 'faq: the journey stays above the chat after a reload')
    const alt = await site.evaluate(() => document.querySelector('.mnjz-messages img.mnjz-image')?.getAttribute('alt'))
    ok(alt === 'طرق الدفع؟', `faq: the answer image is described by its question, got ${alt}`)

    // ── حذف التاجر أسئلته كلها والزائر في منتصف القائمة: لا يعلق ──
    await site.evaluate(() => {
        localStorage.clear()
        localStorage.setItem('mnjz:wc_test:journey', JSON.stringify({ v: 1, steps: ['menu:question'], sent: false }))
        window.__requests = []
    })
    await site.reload()
    await site.evaluate(() => window.mountWidget({ questions: [], faqs: 0 }))
    await site.waitForSelector('.mnjz-choice')
    v = await view()
    ok(v.last === 'اختر سؤالك:' && JSON.stringify(v.choices) === JSON.stringify(['لم أجد إجابتي']), `faq: with no questions left only "not found" remains, got ${JSON.stringify([v.last, v.choices])}`)
    ok(!(await requests()).includes('GET faqs'), 'faq: no list to fetch when the merchant has none')
    await press('لم أجد إجابتي')
    await site.waitForSelector('.mnjz-composer textarea')
    ok(true, 'faq: the visitor reaches the chat')

    // ومن لم يضغط شيئاً بعد: المحادثة مباشرة كما قبل الأسئلة الشائعة.
    await site.evaluate(() => {
        localStorage.clear()
        localStorage.setItem('mnjz:wc_test', JSON.stringify({ token: 'tok', visitorId: 'v1', name: 'سارة', channel: null, lastReadSeq: 0 }))
        localStorage.setItem('mnjz:wc_test:journey', JSON.stringify({ v: 1, steps: [], sent: false }))
    })
    await site.reload()
    await site.evaluate(() => window.mountWidget({ questions: [], faqs: 0 }))
    await site.waitForSelector('.mnjz-composer textarea')
    ok(!(await view()).bubbles.includes('كيف نقدر نساعدك؟'), 'faq: an untouched menu disappears once the merchant has no questions')

    // ── widget بلا أسئلة: الجلسة مع أوّل رسالة، وفشلها لا يُضيّع الرسالة ──
    await site.evaluate(() => localStorage.clear())
    await site.reload()
    await site.evaluate(() => {
        window.__failSession = true
        window.mountWidget({ questions: [], faqs: 0 })
    })
    await site.waitForSelector('.mnjz-composer textarea')
    await site.type('.mnjz-composer textarea', 'مرحبا')
    await site.keyboard.press('Enter')
    await site.waitForSelector('.mnjz-retry')
    const banner = await site.evaluate(() => document.querySelector('.mnjz-banner-error')?.textContent ?? null)
    ok(banner === 'تمهّل قليلاً ثم حاول مرة أخرى.', `no questions: the reason is shown, got ${banner}`)
    ok(!(await requests()).some((r) => r.startsWith('POST messages')), 'no questions: nothing sent without a session')
    await site.click('.mnjz-retry')
    await site.waitForFunction(() => window.__requests.some((r) => r.method === 'POST' && r.url.includes('/messages')))
    const retried = await site.evaluate(() => window.__requests.filter((r) => r.method === 'POST' && r.url.includes('/messages')).map((r) => r.body.text))
    ok(JSON.stringify(retried) === JSON.stringify(['مرحبا']), `no questions: the retry sends the same message once, got ${JSON.stringify(retried)}`)

    // ── نقاط الكتابة ورسالة ما بعد الأسئلة: widget Waz (موافقة ثم الاسم، بلا أسئلة شائعة) ──
    const handoffAr = 'سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.'
    const handoffEn = 'We are transferring you to a customer service agent.'
    const waz = {
        questions: [{ id: 'name', target: 'name', validation: 'text', required: true, label_ar: 'ادخل أسمك', label_en: 'Your name please' }],
        consent: 'باستخدامك لهذه المحادثة فإنك توافق على الشروط',
        faqs: 0,
        typingMs: 600,
        handoff: handoffAr,
        handoffEn,
    }
    const dots = () => site.evaluate(() => !!document.querySelector('.mnjz-typing'))
    const noDots = () => site.waitForFunction(() => !document.querySelector('.mnjz-typing'))
    const fresh = async (options) => {
        await site.evaluate(() => {
            localStorage.clear()
            window.__requests = []
        })
        await site.reload()
        await site.evaluate((o) => window.mountWidget(o), options)
        await site.waitForSelector('.mnjz-root')
    }

    await fresh(waz)
    await site.waitForSelector('.mnjz-choice')
    v = await view()
    ok(!(await dots()) && v.last === waz.consent, `typing: the consent shows at once when the widget opens, got ${JSON.stringify(v.last)}`)

    await press('أوافق')
    v = await view()
    ok(await dots(), 'typing: dots after the consent tap')
    ok(v.bubbles.includes('أوافق') && !v.bubbles.includes('ادخل أسمك'), `typing: the tap shows, the name question waits behind the dots, got ${JSON.stringify(v.bubbles)}`)
    const waiting = await site.evaluate(() => ({ disabled: document.querySelector('.mnjz-answer')?.disabled ?? null, hint: document.querySelector('.mnjz-hint')?.textContent ?? null }))
    ok(waiting.disabled === true && waiting.hint === null, `typing: a disabled answer field meanwhile, not "choose above", got ${JSON.stringify(waiting)}`)
    const sr = await site.evaluate(() => document.querySelector('.mnjz-typing .mnjz-sr')?.textContent ?? null)
    ok(sr === 'يكتب…', `typing: screen readers hear it, got ${sr}`)

    await noDots()
    v = await view()
    ok(v.last === 'ادخل أسمك', `typing: then the name question, got ${v.last}`)

    // الاسم ⇐ تبدأ الجلسة ⇐ النقاط ثم رسالة ما بعد الأسئلة.
    await reply('عبدالاله')
    await site.waitForSelector('.mnjz-composer textarea')
    await site.waitForSelector('.mnjz-typing')
    v = await view()
    ok(v.bubbles.includes('عبدالاله') && !v.bubbles.includes(handoffAr), `handoff: hidden behind the dots first, got ${JSON.stringify(v.bubbles)}`)
    await noDots()
    v = await view()
    ok(v.last === handoffAr, `handoff: shown after the name when there are no FAQs, got ${JSON.stringify(v.last)}`)
    ok((await requests()).filter((r) => r.startsWith('POST sessions')).length === 1 && !(await requests()).some((r) => r.startsWith('POST messages')), 'handoff: a local bubble, nothing sent to the server')

    await site.click('.mnjz-lang')
    v = await view()
    ok(v.last === handoffEn && !(await dots()), `handoff: the English text after switching, without dots, got ${JSON.stringify(v.last)}`)

    await site.reload()
    await site.evaluate((o) => window.mountWidget(o), waz)
    await site.waitForSelector('.mnjz-composer textarea')
    v = await view()
    ok(!(await dots()) && v.last === handoffEn && v.bubbles.includes('عبدالاله'), `handoff: still there after a reload, no dots, got ${JSON.stringify(v.bubbles)}`)

    // الزائر يكتب والنقاط ظاهرة: يظهر كل شيء فوراً، والرسالة فوق رسالته.
    await fresh({ ...waz, typingMs: 5000 })
    await site.waitForSelector('.mnjz-choice')
    await site.evaluate(() => [...document.querySelectorAll('.mnjz-choice')].find((c) => c.textContent.trim() === 'أوافق').click())
    await site.waitForSelector('.mnjz-typing')
    await site.waitForFunction(() => !document.querySelector('.mnjz-typing'), { timeout: 8000 })
    await reply('سارة')
    await site.waitForSelector('.mnjz-composer textarea')
    await site.waitForSelector('.mnjz-typing')
    await site.type('.mnjz-composer textarea', 'هلا')
    await site.keyboard.press('Enter')
    await new Promise((r) => setTimeout(r, 80))
    v = await view()
    ok(!(await dots()), 'handoff: the visitor writing ends the dots')
    ok(v.bubbles.indexOf(handoffAr) >= 0 && v.bubbles.indexOf(handoffAr) < v.bubbles.indexOf('هلا'), `handoff: shown above the visitor message, got ${JSON.stringify(v.bubbles)}`)

    // مع أسئلة شائعة: القائمة بعد الاسم (بعد النقاط)، لا رسالة ما بعد الأسئلة.
    await fresh({ ...waz, faqs: 2, typingMs: 300 })
    await site.waitForSelector('.mnjz-choice')
    await press('أوافق')
    await noDots()
    await reply('سارة')
    await site.waitForSelector('.mnjz-typing')
    await noDots()
    v = await view()
    ok(v.last === 'كيف نقدر نساعدك؟' && !v.bubbles.includes(handoffAr), `handoff: the FAQ menu instead when FAQs exist, got ${JSON.stringify(v.bubbles)}`)

    await press('عندي سؤال')
    ok(await dots(), 'typing: dots before the FAQ list too')
    await site.waitForFunction(() => document.querySelectorAll('.mnjz-choice').length === 3)
    ok((await view()).last === 'اختر سؤالك:', 'typing: then the FAQ list')

    // widget الموافقة وحدها (بلا أسئلة): الرسالة بعد «أوافق»، بعد النقاط.
    await fresh({ ...waz, questions: [], typingMs: 300 })
    await site.waitForSelector('.mnjz-choice')
    await press('أوافق')
    await site.waitForSelector('.mnjz-composer textarea')
    await site.waitForSelector('.mnjz-typing')
    ok(!(await view()).bubbles.includes(handoffAr), 'handoff: consent only — hidden behind the dots first')
    await noDots()
    ok((await view()).last === handoffAr, `handoff: consent only — then shown, got ${JSON.stringify((await view()).bubbles)}`)

    // بلا نصّ عند التاجر: كما كان، لا شيء بعد الاسم ولا نقاط تبقى.
    await fresh({ ...waz, handoff: null, handoffEn: null, typingMs: 300 })
    await site.waitForSelector('.mnjz-choice')
    await press('أوافق')
    await noDots()
    await reply('سارة')
    await site.waitForSelector('.mnjz-composer textarea')
    await new Promise((r) => setTimeout(r, 400))
    v = await view()
    ok(!(await dots()) && v.last === 'سارة', `handoff: nothing when the merchant left it empty, got ${JSON.stringify(v.bubbles)}`)

    // ── رمز الدولة بالإنجليزية: يسار الرقم أيضاً، وزرّ الإرسال في آخر الحقل (يميناً) ──
    await fresh({ questions: [{ id: 'phone', target: 'phone', validation: 'phone', required: true, label_ar: null, label_en: null }], faqs: 0 })
    await site.waitForSelector('.mnjz-country')
    await site.click('.mnjz-lang')
    ok((await view()).dir === 'ltr', 'phone en: the widget is in English')
    phone = await phoneLayout()
    ok(phone.chip.right <= phone.input.left + 0.5 && phone.input.right <= phone.send.left + 0.5, `phone en: code, number, then send from left to right, got ${JSON.stringify([phone.chip, phone.input, phone.send])}`)
    ok(phone.code === '+966' && phone.label === 'Country code' && phone.firstOption.includes('Saudi Arabia') && phone.firstOption.includes('+966'), `phone en: English names in the list, got ${JSON.stringify([phone.code, phone.label, phone.firstOption])}`)

    ok(errors.length === 0, 'page errors: ' + errors.join('; '))
} finally {
    await browser.close()
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ widget render: ${checks} checks`)
