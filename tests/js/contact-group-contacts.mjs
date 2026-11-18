/**
 * زرّ «عرض جهات الاتصال» في صفحة المجموعة ونافذته، في Chrome حقيقي.
 *
 * الصفحة كانت تعرض اسم المجموعة وعددها فقط. يُبنى ContactGroupInfo بـ Vite
 * و@vitejs/plugin-vue كما في التطبيق، ومعه ContactGroupContactsModal وModal
 * الحقيقيان؛ وaxios بديلٌ يسجّل الطلبات ويترك للاختبار متى وبماذا يردّ —
 * فيُفحص ترتيب الردود (بحث قديم يصل بعد الأحدث) والخطأ والصفحات.
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

const tmp = mkdtempSync(join(tmpdir(), 'group-contacts-'))

// axios بديلاً: كل get يُسجَّل ويبقى معلّقاً حتى يردّ الاختبار عليه بـ window.__respond / __reject.
writeFileSync(join(tmp, 'axios.js'), `
window.__requests = []
const axios = {
    get(url, config = {}) {
        return new Promise((resolve, reject) => {
            window.__requests.push({ url, params: JSON.parse(JSON.stringify(config.params ?? {})), resolve, reject })
        })
    },
    post() { return Promise.resolve({ data: {} }) },
}
window.__respond = (index, data) => window.__requests[index].resolve({ data })
window.__reject = (index) => window.__requests[index].reject(new Error('Network Error'))
export default axios
`)

writeFileSync(join(tmp, 'inertia.js'), `
import { defineComponent, h, reactive } from 'vue'
export const router = { visit: () => {}, post: () => {}, delete: () => {} }
export function useForm(initial) { return reactive({ ...initial }) }
export const Link = defineComponent({
    props: { href: String },
    setup(props, { slots }) { return () => h('a', { href: props.href, onClick: (e) => e.preventDefault() }, slots.default?.()) },
})
`)

writeFileSync(join(tmp, 'harness.js'), `
import { createApp, h, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import ContactGroupInfo from '@/Components/ContactGroupInfo.vue'

const state = reactive({ group: { uuid: 'g-1', name: 'أنثى', contact_count: 25 } })
window.setGroup = (group) => { state.group = group }
const i18n = createI18n({ legacy: false, globalInjection: true, locale: 'en', messages: { en: {} }, missingWarn: false, fallbackWarn: false })
const app = createApp({ render: () => h(ContactGroupInfo, { group: state.group }) })
app.use(i18n)
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
                { find: /^axios$/, replacement: join(tmp, 'axios.js') },
                // الـ harness خارج المشروع فلا يجد node_modules؛ ونسخة واحدة للـ harness وuseTrans.
                { find: /^vue-i18n$/, replacement: resolve(root, 'node_modules/vue-i18n/dist/vue-i18n.mjs') },
                { find: '@inertiajs/vue3', replacement: join(tmp, 'inertia.js') },
                { find: /^@\//, replacement: resolve(root, 'resources/js') + '/' },
            ],
        },
        define: { 'process.env.NODE_ENV': '"production"', __VUE_I18N_FULL_INSTALL__: true, __VUE_I18N_LEGACY_API__: false, __INTLIFY_PROD_DEVTOOLS__: false },
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

const contact = (n, fields = {}) => ({ uuid: `c-${n}`, full_name: `عميل ${n}`, phone: `+966 50 000 000${n}`, email: null, opted_out: false, channel: 'whatsapp', channel_label: 'WhatsApp', ...fields })
const pageOf = (data, current_page = 1, last_page = 1, total = data.length) => ({ data, current_page, last_page, total, per_page: 20 })

const browser = await puppeteer.launch({ executablePath: chrome, headless: 'new', args: ['--no-sandbox'] })

try {
    const page = await browser.newPage()
    const errors = []
    page.on('pageerror', (e) => errors.push(e.message))
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
    await page.setContent(html, { waitUntil: 'load' })

    const settle = (ms = 120) => new Promise((r) => setTimeout(r, ms))
    const requests = () => page.evaluate(() => window.__requests.map(({ url, params }) => ({ url, params })))
    const respond = (index, data) => page.evaluate((i, d) => window.__respond(i, d), index, data)
    const reject = (index) => page.evaluate((i) => window.__reject(i), index)
    const click = (label, scope = 'body') => page.evaluate((l, s) => {
        const el = [...document.querySelector(s).querySelectorAll('button')].find((b) => b.textContent.trim() === l)
        if (!el) throw new Error('no button: ' + l)
        el.click()
    }, label, scope)
    const dialog = () => page.evaluate(() => {
        const panel = document.querySelector('[role="dialog"]')
        if (!panel) return null
        const button = (name) => panel.querySelector(`[data-test="group-contacts-${name}"]`)
        return {
            text: panel.innerText,
            items: [...panel.querySelectorAll('[data-test="group-contacts-item"]')].map((a) => ({
                href: a.getAttribute('href'),
                text: a.innerText.replace(/\s+/g, ' ').trim(),
                optedOut: a.querySelector('[data-test="group-contacts-opted-out"]')?.innerText ?? null,
                channel: a.querySelector('[data-test="group-contacts-channel"]')?.innerText ?? null,
            })),
            summary: button('summary')?.innerText ?? null,
            previousDisabled: button('previous')?.disabled ?? null,
            nextDisabled: button('next')?.disabled ?? null,
            search: button('search')?.value ?? null,
        }
    })
    const type = (value) => page.evaluate((v) => {
        const field = document.querySelector('[data-test="group-contacts-search"]')
        field.value = v
        field.dispatchEvent(new Event('input', { bubbles: true }))
    }, value)

    // ── الزرّ في صفحة المجموعة، ولا طلب قبل فتح النافذة ──
    await settle()
    ok((await page.evaluate(() => document.body.innerText)).includes('View contacts'), 'button: shown next to Edit and Delete')
    ok((await requests()).length === 0, 'closed: nothing is fetched before opening')

    // ── الفتح: تحميل ثم القائمة ──
    await click('View contacts')
    await settle()
    let d = await dialog()
    ok(d && d.text.includes('أنثى'), 'open: the dialog is titled with the group name')
    ok(d && d.text.includes('Loading...'), 'open: loading state while waiting')
    let sent = await requests()
    ok(sent.length === 1 && sent[0].url === '/contact-groups/g-1/contacts' && sent[0].params.page === 1 && !('search' in sent[0].params), `open: asks for page 1 of the group, got ${JSON.stringify(sent)}`)

    await respond(0, pageOf([
        contact(1, { opted_out: true }),
        contact(2, { phone: null, email: 'noura@example.com' }),
        contact(3, { phone: null, full_name: '', channel: 'webchat', channel_label: 'Web Chat' }),
    ], 1, 2, 23))
    await settle()
    d = await dialog()
    ok(d.items.length === 3, `list: three contacts, got ${d.items.length}`)
    ok(d.items[0].href === '/contacts/c-1' && d.items[0].text.includes('عميل 1') && d.items[0].text.includes('+966 50 000 0001'), `list: name, phone and link to the contact, got ${JSON.stringify(d.items[0])}`)
    ok(d.items[1].text.includes('noura@example.com'), 'list: the email when there is no phone')
    ok(d.items[2].text.includes('—'), 'list: a dash when there is neither name nor phone')
    ok(d.items[0].optedOut === 'Opted out of marketing' && d.items[1].optedOut === null, `badges: opted-out contacts are marked, got ${JSON.stringify(d.items.map((i) => i.optedOut))}`)
    ok(d.items[2].channel === 'Web Chat' && d.items[0].channel === null, `badges: the channel shows only when it is not WhatsApp, got ${JSON.stringify(d.items.map((i) => i.channel))}`)
    ok(d.summary === '1 / 2 · 23', `pagination: summary, got ${d.summary}`)
    ok(d.previousDisabled === true && d.nextDisabled === false, 'pagination: only Next on the first page')

    // ── الصفحة التالية ──
    await click('Next', '[role="dialog"]')
    await settle()
    sent = await requests()
    ok(sent.length === 2 && sent[1].params.page === 2, `next: asks for page 2, got ${JSON.stringify(sent[1])}`)
    await respond(1, pageOf([contact(4)], 2, 2, 23))
    await settle()
    d = await dialog()
    ok(d.items.length === 1 && d.items[0].href === '/contacts/c-4', 'next: page 2 replaces the list')
    ok(d.summary === '2 / 2 · 23' && d.previousDisabled === false && d.nextDisabled === true, 'next: only Previous on the last page')

    // ── البحث: انتظار قبل الطلب، وطلب واحد لكتابة متتالية ──
    await type('س')
    await type('سا')
    await type('سارة')
    await settle(100)
    ok((await requests()).length === 2, 'search: nothing sent while typing')
    await settle(350)
    sent = await requests()
    ok(sent.length === 3 && sent[2].params.search === 'سارة' && sent[2].params.page === 1, `search: one request from page 1 with the term, got ${JSON.stringify(sent.slice(2))}`)

    // ── ردّ قديم يصل بعد الأحدث لا يُعرض ──
    await type('نورة')
    await settle(450)
    sent = await requests()
    ok(sent.length === 4 && sent[3].params.search === 'نورة', 'stale: a second search is sent')
    await respond(3, pageOf([contact(5, { full_name: 'نورة' })]))
    await settle()
    await respond(2, pageOf([contact(6, { full_name: 'سارة' })]))
    await settle()
    d = await dialog()
    ok(d.items.length === 1 && d.items[0].text.includes('نورة') && !d.text.includes('سارة'), `stale: the older response is ignored, got ${JSON.stringify(d.items)}`)

    // ── بحث بلا نتائج ──
    await type('لا أحد')
    await settle(450)
    await respond(4, pageOf([]))
    await settle()
    d = await dialog()
    ok(d.text.includes('No matching contacts') && d.summary === null, 'search: an empty result says so, without pagination')

    // ── خطأ ثم إعادة المحاولة ──
    await type('')
    await settle(450)
    sent = await requests()
    ok(sent.length === 6 && !('search' in sent[5].params), 'search: clearing the term lists everyone again')
    await reject(5)
    await settle()
    d = await dialog()
    ok(d.text.includes('Something went wrong') && d.text.includes('Retry'), 'error: message and Retry')
    await click('Retry', '[role="dialog"]')
    await settle()
    sent = await requests()
    ok(sent.length === 7 && sent[6].params.page === 1, 'retry: asks again')
    await respond(6, pageOf([contact(7)], 1, 2, 21))
    await settle()
    ok((await dialog()).items.length === 1, 'retry: the list appears')

    // ── فشل الصفحة التالية: إعادة المحاولة تطلبها هي ──
    await click('Next', '[role="dialog"]')
    await settle()
    await reject(7)
    await settle()
    await click('Retry', '[role="dialog"]')
    await settle()
    sent = await requests()
    ok(sent.length === 9 && sent[8].params.page === 2, `retry: asks again for the page that failed, got ${JSON.stringify(sent[8])}`)
    await respond(8, pageOf([contact(8)], 2, 2, 21))
    await settle()
    d = await dialog()
    ok(d.items[0]?.href === '/contacts/c-8' && d.summary === '2 / 2 · 21', 'retry: page 2 is shown')

    // ── الإغلاق ثم الفتح من جديد: بحث فارغ وطلب جديد ──
    await type('عميل')
    await page.evaluate(() => document.querySelector('[role="dialog"] span.cursor-pointer').click())
    await settle(400)
    ok((await dialog()) === null, 'close: the dialog closes')
    ok((await requests()).length === 9, 'close: a pending search is dropped')
    await click('View contacts')
    await settle()
    d = await dialog()
    sent = await requests()
    ok(d.search === '' && sent.length === 10 && sent[9].params.page === 1 && !('search' in sent[9].params), `reopen: fresh search and page 1, got ${JSON.stringify(sent[9])}`)
    await respond(9, pageOf([]))
    await settle()
    d = await dialog()
    ok(d.text.includes('No contacts in this group') && d.summary === null, 'empty group: says so, without pagination')

    // ── ردّ يصل بعد الإغلاق لا يكسر شيئاً ──
    await page.evaluate(() => document.querySelector('[role="dialog"] span.cursor-pointer').click())
    await settle(400)
    await click('View contacts')
    await settle()
    await page.evaluate(() => document.querySelector('[role="dialog"] span.cursor-pointer').click())
    await settle(400)
    await respond(10, pageOf([contact(9)]))
    await settle()
    ok((await dialog()) === null, 'late response: the closed dialog stays closed')

    ok(errors.length === 0, 'page errors: ' + errors.join('; '))
} finally {
    await browser.close()
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ contact-group-contacts: ${checks} checks`)
