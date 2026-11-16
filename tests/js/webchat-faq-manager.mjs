/**
 * محرّر الأسئلة الشائعة في إعدادات المحادثة المباشرة
 * (resources/js/Components/WebChat/FaqManager.vue) في Chrome حقيقي.
 *
 * يُبنى المكوّن بـ Vite و@vitejs/plugin-vue كما في التطبيق، ومعه Modal
 * وFormToggleSwitch الحقيقيان؛ و@inertiajs/vue3 بديلٌ يسجّل الطلبات بدل
 * إرسالها. فالفحص: ما يُرسل، وبأيّ خيارات (preserveState: true كي لا يضيع
 * ما لم يُحفظ من بقية الصفحة)، وما يظهر للتاجر.
 *
 * يحتاج Chrome (CHROME_PATH أو /usr/bin/google-chrome). بدونه يخرج بالرمز 3.
 */
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
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

const tmp = mkdtempSync(join(tmpdir(), 'faq-manager-'))

// Inertia بديلاً: يسجّل كل طلب، وwindow.__nextErrors يجعل التالي يفشل تحقّقاً.
writeFileSync(join(tmp, 'inertia.js'), `
import { reactive } from 'vue'
window.__calls = []
const plain = (value) => (value instanceof File ? 'file:' + value.name : value)
const options = (o = {}) => ({ preserveState: o.preserveState, preserveScroll: o.preserveScroll, forceFormData: o.forceFormData })
export const router = {
    post: (url, data, o) => window.__calls.push({ method: 'post', url, data: JSON.parse(JSON.stringify(data)), options: options(o) }),
    delete: (url, o) => window.__calls.push({ method: 'delete', url, options: options(o) }),
}
export function useForm(initial) {
    const defaults = { ...initial }
    const form = reactive({
        ...initial,
        errors: {},
        processing: false,
        reset() { Object.assign(form, defaults) },
        clearErrors() { form.errors = {} },
        post(url, o = {}) {
            const data = Object.fromEntries(Object.keys(defaults).map((key) => [key, plain(form[key])]))
            window.__calls.push({ method: 'post', url, data, options: options(o) })
            const errors = window.__nextErrors
            window.__nextErrors = null
            if (errors) { form.errors = errors; o.onError?.(errors) } else { o.onSuccess?.() }
        },
    })
    return form
}
`)

writeFileSync(join(tmp, 'harness.js'), `
import { createApp, h, reactive } from 'vue'
import FaqManager from '@/Components/WebChat/FaqManager.vue'

const state = reactive({ faqs: [], max: 30 })
window.setFaqs = (faqs, max = 30) => { state.faqs = faqs; state.max = max }
const app = createApp({ render: () => h(FaqManager, { faqs: state.faqs, max: state.max }) })
app.config.globalProperties.$t = (key) => key
app.mount('#host')
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
                { find: '@inertiajs/vue3', replacement: join(tmp, 'inertia.js') },
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
    html = `<!doctype html><html dir="rtl"><head><meta charset="utf-8"><style>${css}</style></head><body><div id="host"></div><script>${js.replace(/<\/script/g, '<\\/script')}</script></body></html>`
} finally {
    rmSync(tmp, { recursive: true, force: true })
}

let checks = 0
const fail = []
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const faq = (id, fields = {}) => ({ id, question_ar: `سؤال ${id}`, question_en: null, answer_ar: `إجابة ${id}`, answer_en: null, image_url: null, is_active: true, ...fields })

const browser = await puppeteer.launch({ executablePath: chrome, headless: 'new', args: ['--no-sandbox'] })

try {
    const page = await browser.newPage()
    const errors = []
    page.on('pageerror', (e) => errors.push(e.message))
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
    await page.setContent(html, { waitUntil: 'load' })

    const settle = () => new Promise((r) => setTimeout(r, 120))
    const calls = () => page.evaluate(() => window.__calls.splice(0))
    const text = () => page.evaluate(() => document.body.innerText)
    const click = (label, scope = 'body') => page.evaluate((l, s) => {
        const el = [...document.querySelector(s).querySelectorAll('button, label')].find((b) => b.textContent.trim().replace(/\s+/g, ' ') === l)
        if (!el) throw new Error('no button: ' + l)
        el.click()
    }, label, scope)
    const dialog = () => page.evaluate(() => {
        const panel = document.querySelector('[role="dialog"]')
        return panel ? { text: panel.innerText, inputs: [...panel.querySelectorAll('input[type=text], textarea')].map((i) => i.value), image: panel.querySelector('img')?.getAttribute('src') ?? null } : null
    })
    const fill = async (index, value) => {
        await page.evaluate((i, v) => {
            const field = document.querySelectorAll('[role="dialog"] input[type=text], [role="dialog"] textarea')[i]
            field.value = v
            field.dispatchEvent(new Event('input', { bubbles: true }))
        }, index, value)
    }

    // ── فارغة ──
    await page.evaluate(() => window.setFaqs([]))
    await settle()
    ok((await text()).includes('No questions yet. Without questions the visitor goes straight to the chat.'), 'empty: the empty state explains what happens')
    ok((await text()).includes('0 / 30'), 'empty: the count')

    // ── القائمة ──
    await page.evaluate((list) => window.setFaqs(list), [faq(7), faq(9, { is_active: false, image_url: 'https://app.test/media/a.png', answer_ar: null })])
    await settle()
    let body = await text()
    ok(body.includes('سؤال 7') && body.includes('سؤال 9') && body.includes('2 / 30'), 'list: both questions and the count')
    ok(body.includes('Image') && body.includes('Hidden from visitors'), 'list: the image and hidden badges')

    // ── إضافة ──
    await click('+ Add a question')
    await settle()
    let d = await dialog()
    ok(d && d.text.includes('Add a question') && d.inputs.every((v) => v === ''), 'add: an empty form opens')
    await fill(0, 'ما طرق الدفع؟')
    await fill(2, 'مدى وفيزا')
    await click('Save', '[role="dialog"]')
    await settle()
    let sent = await calls()
    ok(sent.length === 1 && sent[0].url === '/settings/webchat/faqs', `add: posts to the store route, got ${JSON.stringify(sent)}`)
    ok(sent[0]?.data.question_ar === 'ما طرق الدفع؟' && sent[0]?.data.answer_ar === 'مدى وفيزا' && sent[0]?.data.is_active === true && sent[0]?.data.remove_image === false, 'add: the fields')
    ok(sent[0]?.options.preserveState === true && sent[0]?.options.forceFormData === true && sent[0]?.options.preserveScroll === true, `add: keeps the rest of the page and sends a form, got ${JSON.stringify(sent[0]?.options)}`)
    ok((await dialog()) === null, 'add: the dialog closes on success')

    // ── خطأ من الخادم: النافذة تبقى والسبب ظاهر ──
    await click('+ Add a question')
    await settle()
    await fill(0, 'بلا إجابة')
    await page.evaluate(() => (window.__nextErrors = { answer_ar: 'Write an answer or add an image.' }))
    await click('Save', '[role="dialog"]')
    await settle()
    d = await dialog()
    ok(d && d.text.includes('Write an answer or add an image.') && d.inputs[0] === 'بلا إجابة', 'error: the dialog stays with the reason and the text')
    await click('Cancel', '[role="dialog"]')
    await settle()
    await calls()

    // ── تعديل: القيم الحالية، وإزالة الصورة ──
    await page.evaluate(() => [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Edit')[1].click())
    await settle()
    d = await dialog()
    ok(d && d.text.includes('Edit question') && d.inputs[0] === 'سؤال 9' && d.image === 'https://app.test/media/a.png', `edit: the current values and image, got ${JSON.stringify(d)}`)
    ok(d && !d.text.includes('Write an answer or add an image.'), 'edit: no leftover error from the last dialog')
    await click('Remove image', '[role="dialog"]')
    await settle()
    ok((await dialog()).image === null, 'edit: the image disappears once removed')
    await fill(2, 'نص بدل الصورة')
    await click('Save', '[role="dialog"]')
    await settle()
    sent = await calls()
    ok(sent[0]?.url === '/settings/webchat/faqs/9' && sent[0]?.data.remove_image === true && sent[0]?.data.is_active === false, `edit: posts to the question with remove_image and its hidden state, got ${JSON.stringify(sent[0])}`)

    // ── إظهار/إخفاء من القائمة ──
    await page.evaluate(() => document.querySelector('.space-y-2 > div .w-12').click())
    await settle()
    sent = await calls()
    ok(sent[0]?.url === '/settings/webchat/faqs/7' && sent[0]?.data.is_active === false && sent[0]?.data.question_ar === 'سؤال 7', `toggle: hides question 7 keeping its text, got ${JSON.stringify(sent[0])}`)
    ok(sent[0]?.options.preserveState === true, 'toggle: keeps the rest of the page')

    // ── حذف بتأكيد ──
    await page.evaluate(() => [...document.querySelectorAll('button')].filter((b) => b.textContent.trim() === 'Delete')[0].click())
    await settle()
    ok((await dialog())?.text.includes('Delete this question?'), 'delete: asks first')
    ok((await calls()).length === 0, 'delete: nothing sent before confirming')
    await click('Delete', '[role="dialog"]')
    await settle()
    sent = await calls()
    ok(sent.length === 1 && sent[0].method === 'delete' && sent[0].url === '/settings/webchat/faqs/7' && sent[0].options.preserveState === true, `delete: the delete request, got ${JSON.stringify(sent)}`)

    // ── ما يعود من الخادم يحلّ محلّ القائمة ──
    await page.evaluate((list) => window.setFaqs(list), [faq(9)])
    await settle()
    body = await text()
    ok(!body.includes('سؤال 7') && body.includes('1 / 30'), 'props: the list follows the server')

    // ── الحدّ ──
    await page.evaluate((list) => window.setFaqs(list, 2), [faq(1), faq(2)])
    await settle()
    const add = await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Add a question')).disabled)
    ok(add && (await text()).includes('You have reached the maximum number of questions.'), 'max: adding is disabled with the reason')

    ok(errors.length === 0, 'page errors: ' + errors.join('; '))
} finally {
    await browser.close()
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ webchat-faq-manager: ${checks} checks`)
