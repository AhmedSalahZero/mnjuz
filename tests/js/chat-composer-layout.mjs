/**
 * صندوق كتابة الرسائل في الـ chats بالشكل الكبير للكل، في Chrome حقيقي
 * (resources/js/Components/ChatComponents/ChatForm.vue).
 *
 * الطلب: الصندوق كان صغيراً (سطر واحد) عند من لم يفعّل مساعد الـ AI، وكبيراً
 * (ثلاثة أسطر وتحتها شريط الأدوات) عند من فعّله. المطلوب الكبير دائماً، ويبقى
 * زرّ «AI Assist» وحده مربوطاً بالتفعيل لأنه لا يعمل بدونه.
 *
 * والصندوق الكبير كان يصل إلى المفعّلين فقط، فما فيه من أعطال يصل الآن للكل:
 * - زرّ «Templates» النصّي بلا type داخل الـ form ⇒ يُرسل النصّ المكتوب.
 * - تسجيل صوتي بلا نصّ لا يُرسَل (الخروج المبكر في sendMessage).
 * - بعد أول تسجيل كانت كل رسالة نصية تُتبَع بـ POST صوتي فارغ.
 *
 * المكوّن الحقيقي مبنيّ بـ Vite مع Tailwind المشروع؛ المستبدَل فقط: axios
 * (يسجّل الطلبات)، والمسجّل الصوتي (mp3 وهمي)، وusePage.
 *
 * يُشغَّل من جذر المشروع: node tests/js/chat-composer-layout.mjs
 * يحتاج Chrome (CHROME_PATH أو /usr/bin/google-chrome). بدونه يخرج بالرمز 3.
 */
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:http'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const chrome = process.env.CHROME_PATH || '/usr/bin/google-chrome'

if (!existsSync(chrome)) {
    console.log(`skip: Chrome not found at ${chrome}`)
    process.exit(3)
}

const { build } = await import(resolve(root, 'node_modules/vite/dist/node/index.js'))
const { default: vue } = await import(resolve(root, 'node_modules/@vitejs/plugin-vue/dist/index.mjs'))
const { default: puppeteer } = await import(resolve(root, 'node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js'))

const tmp = mkdtempSync(join(tmpdir(), 'chat-composer-'))

writeFileSync(join(tmp, 'tailwind.css'), '@tailwind base;\n@tailwind components;\n@tailwind utilities;\n')

// كل طلب يُسجَّل بحقوله؛ الملفات تُختصر إلى اسمها ونوعها وحجمها.
writeFileSync(join(tmp, 'axios-stub.js'), `
const record = (method, url, data) => {
    const entry = { method, url, fields: {} }
    if (data instanceof FormData) {
        for (const [key, value] of data.entries()) {
            entry.fields[key] = value instanceof Blob ? { name: value.name, type: value.type, size: value.size } : value
        }
    } else if (data) {
        entry.fields = { ...data }
    }
    window.__requests.push(entry)
}

export default {
    get(url, config) {
        record('get', url, config?.params)
        if (url === '/shortcuts/available') return Promise.resolve({ data: { shortcuts: [] } })
        return Promise.resolve({ data: { success: true, data: { text: 'AI suggested reply' } } })
    },
    post(url, data) {
        record('post', url, data)
        return Promise.resolve({ data: { success: true } })
    },
}
`)

writeFileSync(join(tmp, 'mic-stub.js'), `
export default class MicRecorder {
    start() { return Promise.resolve() }
    stop() {
        const bytes = new Uint8Array([73, 68, 51, 4, 0, 0])
        // الترميز إلى mp3 يأخذ وقتاً في المسجّل الحقيقي؛ الحلّ الفوري يُزيل الزرّ
        // قبل أن يُنفَّذ submit الافتراضي فيُخفي عطل الأزرار بلا type.
        const mp3 = [[bytes], new Blob([bytes], { type: 'audio/mpeg' })]
        return { getMp3: () => new Promise((r) => setTimeout(() => r(mp3), 50)) }
    }
}
`)

writeFileSync(join(tmp, 'inertia-stub.js'), `
export const usePage = () => ({ props: { config: [], organization: null } })
`)

// عرض لوحة المحادثة تقريباً كما في Chat/Index.vue بجوار قائمة المحادثات.
writeFileSync(join(tmp, 'Harness.vue'), `
<script setup>
import ChatForm from '@/Components/ChatComponents/ChatForm.vue'

defineProps(['simpleForm'])

const contact = { uuid: 'contact-1', is_blocked: false, is_messaging_window_open: true, channel_capabilities: {} }
const onViewTemplate = () => window.__events.push('viewTemplate')
</script>
<template>
  <div id="pane" style="max-width:900px;margin:24px auto">
    <ChatForm :contact="contact" :simpleForm="simpleForm" :chatLimitReached="false" @viewTemplate="onViewTemplate" />
  </div>
</template>
`)

writeFileSync(join(tmp, 'harness.js'), `
import './tailwind.css'
import { createApp } from 'vue'
import { createI18n } from 'vue-i18n'
import Harness from './Harness.vue'

window.__requests = []
window.__events = []
window.__mount = (simpleForm) => {
    const i18n = createI18n({ legacy: false, locale: 'en', missingWarn: false, fallbackWarn: false, messages: { en: {} } })
    createApp(Harness, { simpleForm }).use(i18n).mount('#host')
}
`)

let html

try {
    const result = await build({
        configFile: false,
        root,
        logLevel: 'silent',
        plugins: [vue()],
        resolve: {
            alias: [
                { find: /^axios$/, replacement: join(tmp, 'axios-stub.js') },
                { find: /^mic-recorder-to-mp3-fixed$/, replacement: join(tmp, 'mic-stub.js') },
                { find: /^@inertiajs\/vue3$/, replacement: join(tmp, 'inertia-stub.js') },
                // الـ harness خارج المشروع: نسخة المشروع نفسها التي يستعملها useTrans.
                { find: /^vue-i18n$/, replacement: resolve(root, 'node_modules/vue-i18n') },
                { find: /^@\//, replacement: resolve(root, 'resources/js') + '/' },
            ],
        },
        define: { 'process.env.NODE_ENV': '"production"' },
        build: {
            write: false,
            minify: false,
            modulePreload: false,
            rollupOptions: { input: join(tmp, 'harness.js'), output: { format: 'iife', inlineDynamicImports: true } },
        },
    })
    const output = (Array.isArray(result) ? result[0] : result).output
    const js = output.find((chunk) => chunk.type === 'chunk').code
    const css = output.filter((asset) => asset.type === 'asset' && asset.fileName.endsWith('.css')).map((asset) => asset.source).join('\n')
    html = `<!doctype html><html dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style></head><body><div id="host"></div><script>${js.replace(/<\/script/g, '<\\/script')}</script></body></html>`
} finally {
    rmSync(tmp, { recursive: true, force: true })
}

let checks = 0
const fail = []
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const DESKTOP = { width: 1440, height: 900 }
const MOBILE = { width: 390, height: 844, isMobile: true, hasTouch: true }
const MIC_PATH = 'M842 454'

// أصل حقيقي على 127.0.0.1 لا about:blank: الطابور يفتح IndexedDB، وsendMessage
// يستعمل crypto.randomUUID — وكلاهما ممنوع في about:blank.
const server = createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
    res.end(html)
})
await new Promise((r) => server.listen(0, '127.0.0.1', r))
const origin = `http://127.0.0.1:${server.address().port}/`

const browser = await puppeteer.launch({ executablePath: chrome, headless: 'new', args: ['--no-sandbox'] })

const open = async (viewport, simpleForm) => {
    const page = await browser.newPage()
    await page.setViewport(viewport)
    page.errors = []
    page.on('pageerror', (e) => page.errors.push(e.message))
    page.on('console', (m) => m.type() === 'error' && page.errors.push(m.text()))
    await page.goto(origin, { waitUntil: 'load' })
    await page.evaluate((flag) => window.__mount(flag), simpleForm)
    await page.waitForSelector('textarea')
    return page
}

const settle = () => new Promise((r) => setTimeout(r, 200))

// زرّ ظاهر (لا داخل عنصر hidden/md:hidden) يحقّق الشرط.
const visibleButton = (page, test, arg) => page.evaluateHandle((source, value) => {
    const match = new Function('b', 'value', `return (${source})(b, value)`)
    return [...document.querySelectorAll('button')].find((b) => b.offsetParent !== null && match(b, value)) ?? null
}, test.toString(), arg)

const hasVisibleButton = async (page, test, arg) => {
    const handle = await visibleButton(page, test, arg)
    return (await handle.evaluate((b) => b !== null))
}

const appears = async (page, selector, message) => {
    try {
        await page.waitForSelector(selector, { timeout: 3000 })
        return true
    } catch {
        ok(false, message)
        return false
    }
}

// ضغط حقيقي بالفأرة؛ الزرّ الغائب إخفاقٌ مُبلَّغ لا استثناء يوقف بقية الفحوص.
const clickButton = async (page, label, test, arg) => {
    const handle = await visibleButton(page, test, arg)
    if (await handle.evaluate((b) => b === null)) {
        ok(false, `${label}: button not found`)
        return false
    }
    await handle.asElement().click()
    return true
}

const byText = (b, text) => b.textContent.trim() === text
const byIcon = (b, path) => !!b.querySelector(`path[d^="${path}"]`)

// كل خطوة تبدأ بسجلّ فارغ، فإخفاق خطوة لا يُحسَب على التي بعدها.
const resetRequests = (page) => page.evaluate(() => { window.__requests.length = 0 })

const chatPosts = (page) => page.evaluate(() => window.__requests.filter((r) => r.method === 'post' && r.url === '/chats'))

const clearText = async (page) => {
    await page.click('textarea')
    await page.keyboard.down('Control')
    await page.keyboard.press('KeyA')
    await page.keyboard.up('Control')
    await page.keyboard.press('Backspace')
}

try {
    // ── الشكل: كبير في الحالتين، والفرق الوحيد زرّ المساعد ──
    const heights = {}

    for (const [label, simpleForm, aiExpected] of [['AI off', true, false], ['AI on', false, true]]) {
        const page = await open(DESKTOP, simpleForm)

        const layout = await page.evaluate(() => {
            const textarea = document.querySelector('textarea')
            const style = getComputedStyle(textarea)
            return {
                forms: document.querySelectorAll('form').length,
                textareas: document.querySelectorAll('textarea').length,
                rows: textarea.getAttribute('rows'),
                height: textarea.getBoundingClientRect().height,
                lineHeight: parseFloat(style.lineHeight),
            }
        })
        heights[label] = layout.height

        ok(layout.forms === 1, `${label}: expected one composer form, got ${layout.forms}`)
        ok(layout.textareas === 1, `${label}: expected one textarea, got ${layout.textareas}`)
        ok(layout.rows === '3', `${label}: textarea rows should be 3 (large composer), got ${layout.rows}`)
        ok(layout.height >= 3 * layout.lineHeight, `${label}: textarea is not three lines tall, got ${JSON.stringify(layout)}`)

        // شريط الأدوات تحت الحقل كما في الصورة الثانية.
        ok(await hasVisibleButton(page, byText, 'Templates'), `${label}: toolbar without the Templates button`)
        ok(await hasVisibleButton(page, byIcon, MIC_PATH), `${label}: toolbar without the voice recording button`)
        ok(await hasVisibleButton(page, byText, 'Send'), `${label}: toolbar without the Send button`)

        const ai = await hasVisibleButton(page, (b) => b.textContent.includes('AI Assist'))
        ok(ai === aiExpected, `${label}: AI Assist button visible=${ai}, expected ${aiExpected}`)

        ok(page.errors.length === 0, `${label}: page errors: ${page.errors.join('; ')}`)
        await page.close()
    }

    ok(heights['AI off'] === heights['AI on'],
        `the composer must be the same size with and without AI, got ${JSON.stringify(heights)}`)

    // ── الموبايل: الصندوق كبير، و«AI Assist» في قائمة المرفقات مع التفعيل فقط ──
    for (const [label, simpleForm, aiExpected] of [['mobile AI off', true, false], ['mobile AI on', false, true]]) {
        const page = await open(MOBILE, simpleForm)

        ok(await page.$eval('textarea', (t) => t.getAttribute('rows')) === '3', `${label}: textarea rows should be 3`)

        const attach = await page.$('button[aria-label="Attach"]')
        ok(!!attach, `${label}: no Attach button`)
        if (attach) {
            await attach.click()
            await settle()
            const ai = await hasVisibleButton(page, (b) => b.textContent.includes('AI Assist'))
            ok(ai === aiExpected, `${label}: AI Assist in the attach menu visible=${ai}, expected ${aiExpected}`)
            ok(await hasVisibleButton(page, (b) => b.textContent.trim() === 'Templates'), `${label}: attach menu lost Templates`)
        }

        ok(page.errors.length === 0, `${label}: page errors: ${page.errors.join('; ')}`)
        await page.close()
    }

    // ── السلوك عند من لم يفعّل الـ AI (يرى الصندوق الكبير لأول مرة) ──
    {
        const page = await open(DESKTOP, true)

        // «Templates» يفتح القوالب ولا يُرسل ما كُتب.
        await page.type('textarea', 'draft text')
        await clickButton(page, 'Templates', byText, 'Templates')
        await settle()
        ok((await page.evaluate(() => window.__events)).includes('viewTemplate'), 'Templates: click did not open the templates')
        ok((await chatPosts(page)).length === 0, `Templates: clicking it sent the typed text: ${JSON.stringify(await chatPosts(page))}`)
        ok(await page.$eval('textarea', (t) => t.value) === 'draft text', 'Templates: the typed text was cleared')

        // إيقاف التسجيل وحذفه وفي الحقل نصّ: لا يُرسَل شيء ويبقى النصّ.
        await resetRequests(page)
        await clearText(page)
        await clickButton(page, 'stop/delete: record', byIcon, MIC_PATH)
        if (await appears(page, 'button[title="Stop Recording"]', 'stop/delete: recording did not start')) {
            await page.type('textarea', 'typed while recording')
            await page.click('button[title="Stop Recording"]')
            if (await appears(page, 'button[title="Delete Recording"]', 'stop/delete: no recording preview after stopping')) {
                await settle()
                ok((await chatPosts(page)).length === 0, `stop: stopping the recording sent the typed text: ${JSON.stringify(await chatPosts(page))}`)
                await page.click('button[title="Delete Recording"]')
                await settle()
                ok((await chatPosts(page)).length === 0, `delete: deleting the recording sent the typed text: ${JSON.stringify(await chatPosts(page))}`)
                ok(await page.$('button[title="Delete Recording"]') === null, 'delete: the recording preview stayed')
            }
        }
        ok(await page.$eval('textarea', (t) => t.value) === 'typed while recording', 'stop/delete: the typed text was cleared')

        // تسجيل صوتي بلا نصّ: الإيقاف لا يُرسل، والإرسال يُرسل الصوت مرة واحدة.
        await resetRequests(page)
        await clearText(page)
        await clickButton(page, 'voice: record', byIcon, MIC_PATH)
        if (await appears(page, 'button[title="Stop Recording"]', 'voice: recording did not start')) {
            await page.click('button[title="Stop Recording"]')
            await appears(page, 'button[title="Delete Recording"]', 'voice: no recording preview after stopping')
        }
        await settle()
        ok((await chatPosts(page)).length === 0, `voice: stopping the recording sent something: ${JSON.stringify(await chatPosts(page))}`)

        await clickButton(page, 'Send', byText, 'Send')
        try {
            await page.waitForFunction(() => window.__requests.some((r) => r.url === '/chats' && r.fields.type === 'audio'), { timeout: 3000 })
        } catch {
            // يُبلَّغ في الفحص التالي
        }
        await settle()
        let posts = await chatPosts(page)
        ok(posts.length === 1, `voice: expected exactly one POST /chats, got ${JSON.stringify(posts)}`)
        ok(posts[0]?.fields.type === 'audio' && posts[0]?.fields.file?.type === 'audio/mpeg',
            `voice: the POST is not the recorded audio: ${JSON.stringify(posts[0])}`)
        ok(await page.$('button[title="Delete Recording"]') === null, 'voice: the recording preview stayed after sending')

        // رسالة نصية بعد التسجيل: POST واحد للنص، بلا POST صوتي فارغ.
        await page.type('textarea', 'text after voice')
        await clickButton(page, 'Send', byText, 'Send')
        try {
            await page.waitForFunction(() => window.__requests.some((r) => r.url === '/chats' && r.fields.message === 'text after voice'), { timeout: 3000 })
        } catch {
            // يُبلَّغ في الفحص التالي
        }
        await settle()
        posts = await chatPosts(page)
        ok(posts.length === 2, `text after voice: expected 2 POSTs in total, got ${JSON.stringify(posts)}`)
        ok(posts[1]?.fields.type === 'text' && posts[1]?.fields.message === 'text after voice',
            `text after voice: second POST is not the text: ${JSON.stringify(posts[1])}`)

        // Enter ما زال يُرسل النص.
        await page.type('textarea', 'sent with enter')
        await page.keyboard.press('Enter')
        await settle()
        posts = await chatPosts(page)
        ok(posts.length === 3 && posts[2]?.fields.message === 'sent with enter', `Enter: expected the text POST, got ${JSON.stringify(posts)}`)

        ok(page.errors.length === 0, `behaviour (AI off): page errors: ${page.errors.join('; ')}`)
        await page.close()
    }

    // ── مع الـ AI: زرّ المساعد ما زال يجلب الاقتراح إلى الحقل ──
    {
        const page = await open(DESKTOP, false)

        await clickButton(page, 'AI on: AI Assist', (b) => b.textContent.includes('AI Assist'))
        try {
            await page.waitForFunction(() => document.querySelector('textarea').value === 'AI suggested reply', { timeout: 3000 })
        } catch {
            // يُبلَّغ في الفحص التالي
        }
        const requests = await page.evaluate(() => window.__requests)
        ok(requests.some((r) => r.method === 'get' && r.url === '/automation/chat/suggestion' && r.fields.contact === 'contact-1'),
            `AI on: no suggestion request, got ${JSON.stringify(requests)}`)
        ok(await page.$eval('textarea', (t) => t.value) === 'AI suggested reply', 'AI on: the suggestion was not put in the textarea')
        ok((await chatPosts(page)).length === 0, 'AI on: the suggestion was sent instead of filled in')

        ok(page.errors.length === 0, `AI on: page errors: ${page.errors.join('; ')}`)
        await page.close()
    }
} finally {
    await browser.close()
    server.close()
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ chat-composer-layout: ${checks} checks`)
