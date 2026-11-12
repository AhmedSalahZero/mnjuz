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
import { Prechat } from './resources/widget/src/app/Prechat.tsx'
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

// نموذج الزائر بحسب إعداد الجوال؛ null = خادم أقدم من الإعداد.
window.renderPrechat = (phone) => {
    const host = document.getElementById('host')
    render(null, host)
    const prechat = { default_country: 'SA', email: 'off', name_label: null, phone_label: null, consent_text: null, ...(phone ? { phone } : {}) }
    render(
        <div class="mnjz-root" dir="rtl" style="width:370px">
            <Prechat config={{ widget: { prechat } }} lang="ar" t={translator('ar')} onSubmit={async () => null} />
        </div>,
        host,
    )
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

    // حقل الجوال: يختفي هو ودولته حين يُخفى، وبلا required ومعلَّماً «اختياري» حين يكون اختيارياً.
    for (const [mode, expected] of [
        [null, { field: true, country: true, required: true, optional: false }],
        ['required', { field: true, country: true, required: true, optional: false }],
        ['optional', { field: true, country: true, required: false, optional: true }],
        ['off', { field: false, country: false, required: null, optional: false }],
    ]) {
        await page.evaluate((m) => window.renderPrechat(m), mode)
        const state = await page.evaluate(() => {
            const input = document.querySelector('input[name=phone]')
            const label = input ? input.closest('label').querySelector('span').textContent : ''

            return {
                field: !!input,
                country: !!document.querySelector('.mnjz-phone select'),
                required: input ? input.required : null,
                optional: label.includes('اختياري'),
                name: !!document.querySelector('input[name=name]'),
            }
        })

        ok(state.name, `prechat ${mode}: the name field is always shown`)
        for (const key of Object.keys(expected)) {
            ok(state[key] === expected[key], `prechat ${mode}: ${key} expected ${expected[key]}, got ${state[key]}`)
        }
    }

    ok(errors.length === 0, 'page errors: ' + errors.join('; '))
} finally {
    await browser.close()
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ widget render: ${checks} checks`)
