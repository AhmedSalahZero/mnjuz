/**
 * builder قالب البريد (resources/js/Components/EmailBuilder/EmailBuilder.vue)
 * في Chrome حقيقي: القوالب الجاهزة، والعناصر، والترتيب (أعلى/أسفل/تكرار/
 * حذف)، والنقر في المعاينة، والمتغيّرات عند المؤشّر، والجوال، ومكتبة الصور
 * (اختيار ورفع)، وأخطاء الخادم.
 *
 * axios بديلٌ يسجّل الطلبات ويُرجع مكتبة ثابتة. السحب والإفلات نفسه من
 * vuedraggable ولا يُختبر هنا.
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

const tmp = mkdtempSync(join(tmpdir(), 'email-builder-'))
const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='

writeFileSync(join(tmp, 'axios.js'), `
window.__requests = []
window.__library = [
    { uuid: 'u1', name: 'banner.png', path: '${PIXEL}#banner', media_type: 'IMAGE' },
    { uuid: 'u2', name: 'logo.png', path: '${PIXEL}#logo', media_type: 'IMAGE' },
]
export default {
    async get(url, config = {}) {
        window.__requests.push({ method: 'get', url, params: config.params })
        const search = config.params?.search || ''
        return { data: { data: window.__library.filter((item) => item.name.includes(search)) } }
    },
    async post(url, body, config = {}) {
        const file = body.get('image')
        window.__requests.push({ method: 'post', url, file: file?.name })
        config.onUploadProgress?.({ loaded: 1, total: 1 })
        return { data: { data: { uuid: 'new', name: file.name, path: '${PIXEL}#new', media_type: 'IMAGE' } } }
    },
}
`)

writeFileSync(join(tmp, 'harness.js'), `
import { createApp, h, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import EmailBuilder from '@/Components/EmailBuilder/EmailBuilder.vue'

const state = reactive({ errors: {} })
window.__design = null
window.setErrors = (errors) => { state.errors = errors }

const placeholders = [
    { value: '{first_name}', label: 'اسم العميل' },
    { value: '{organization_name}', label: 'اسم المنشأة' },
    { value: '{url:first_name}', label: 'اسم العميل (URL encoded)' },
]

const App = {
    render: () => h(EmailBuilder, {
        modelValue: null,
        'onUpdate:modelValue': (value) => { window.__design = value },
        subject: 'عرض لك يا {first_name}',
        placeholders,
        organizationName: 'صالون الرواد',
        errors: state.errors,
        openPresets: true,
    }),
}

const i18n = createI18n({ legacy: false, locale: 'en', missingWarn: false, fallbackWarn: false, messages: { en: {} } })
createApp(App).use(i18n).mount('#host')
`)

const pngPath = join(tmp, 'new-banner.png')
writeFileSync(pngPath, Buffer.from(PIXEL.split(',')[1], 'base64'))
const gifPath = join(tmp, 'anim.gif')
writeFileSync(gifPath, Buffer.from('R0lGODlhAQABAAAAACw=', 'base64'))

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
                // الـ harness خارج المشروع: نسخة المشروع نفسها التي يستعملها useTrans.
                { find: /^vue-i18n$/, replacement: resolve(root, 'node_modules/vue-i18n') },
                { find: /^@\//, replacement: resolve(root, 'resources/js') + '/' },
            ],
        },
        define: { 'process.env.NODE_ENV': '"production"', __VUE_PROD_DEVTOOLS__: 'false', __VUE_OPTIONS_API__: 'true', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
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
} catch (error) {
    rmSync(tmp, { recursive: true, force: true })
    throw error
}

let checks = 0
const fail = []
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const browser = await puppeteer.launch({ executablePath: chrome, headless: 'new', args: ['--no-sandbox'] })

try {
    const page = await browser.newPage()
    await page.setViewport({ width: 1400, height: 1000 })
    const errors = []
    page.on('pageerror', (e) => errors.push(e.message))
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
    await page.setContent(html, { waitUntil: 'load' })

    const settle = (ms = 150) => new Promise((r) => setTimeout(r, ms))
    const design = () => page.evaluate(() => window.__design)
    const clickSel = (selector) => page.evaluate((s) => {
        const el = document.querySelector(s)
        if (!el) throw new Error('missing ' + s)
        el.click()
    }, selector)
    const clickText = (label, scope = 'body') => page.evaluate((l, s) => {
        const el = [...document.querySelector(s).querySelectorAll('button')].find((b) => b.textContent.trim().replace(/\s+/g, ' ') === l)
        if (!el) throw new Error('no button: ' + l)
        el.click()
    }, label, scope)
    const rows = () => page.evaluate(() => [...document.querySelectorAll('[data-block-row]')].map((row) => row.getAttribute('data-block-row')))
    const types = async () => (await design())?.blocks.map((block) => block.type) ?? []
    const previewText = (type) => page.evaluate((t) => document.querySelector(`[data-block-type="${t}"]`)?.innerText ?? null, type)
    const rowAction = (id, action) => clickSel(`[data-block-row="${id}"] [data-action="${action}"]`)

    await settle(300)

    // ── قالب جديد: المعرض يُفتح أوّلاً ──
    ok((await page.$$('[data-preset]')).length === 11, 'presets: the gallery opens with 11 templates')
    await clickSel('[data-preset="national_day"]')
    await settle(400)
    ok((await page.$$('[data-preset]')).length === 0, 'presets: the gallery closes after choosing')
    ok(JSON.stringify(await types()) === JSON.stringify(['header', 'heading', 'text', 'button', 'footer']), `presets: national day elements, got ${JSON.stringify(await types())}`)
    ok((await rows()).length === 5, 'list: one row per element')
    ok((await previewText('heading')) === 'Happy National Day', 'preview: the heading')
    ok((await previewText('header'))?.includes('صالون الرواد'), 'preview: the organization name replaces the variable')
    ok((await page.evaluate(() => document.body.innerText)).includes('عرض لك يا Sara'), 'preview: the subject with sample values')
    // الـ harness بلا رسائل ترجمة: المفتاح يظهر كما هو ({name} يملؤه ar.json وen.json في التطبيق).
    const footer = await page.evaluate(() => document.querySelector('[title="Added to every email automatically"]')?.innerText ?? '')
    ok(footer.includes('You received this email from') && footer.includes('Unsubscribe'), `preview: the locked unsubscribe footer, got ${footer}`)

    // ── إضافة من العناصر ──
    await clickSel('[data-palette="divider"]')
    await settle()
    let ids = await rows()
    ok(ids.length === 6 && (await types())[5] === 'divider', 'palette: a click appends the element')

    // ── أعلى / أسفل / تكرار / حذف ──
    await rowAction(ids[5], 'up')
    await settle()
    ok((await types())[4] === 'divider', 'list: up moves one step')
    await rowAction(ids[5], 'down')
    await settle()
    ok((await types())[5] === 'divider', 'list: down moves back')
    const lastUp = await page.evaluate((id) => document.querySelector(`[data-block-row="${id}"] [data-action="down"]`).disabled, ids[5])
    ok(lastUp, 'list: the last element cannot move down')

    await rowAction(ids[0], 'duplicate')
    await settle()
    ids = await rows()
    let current = await design()
    ok(ids.length === 7 && current.blocks[1].type === 'header' && current.blocks[1].id !== current.blocks[0].id, 'list: duplicate inserts a copy after the original')
    await rowAction(ids[1], 'remove')
    await settle()
    ok((await rows()).length === 6, 'list: delete removes the element')

    // ── النقر في المعاينة يفتح الخصائص ──
    current = await design()
    const textId = current.blocks.find((block) => block.type === 'text').id
    await clickSel(`[data-block-id="${textId}"]`)
    await settle()
    ok(await page.$(`[data-properties-for="${textId}"]`), 'preview: clicking an element opens its properties')
    ok(await page.evaluate(() => document.querySelector('[data-tab="properties"]').getAttribute('aria-selected') === 'true'), 'tabs: the properties tab is active')

    // ── المتغيّر عند المؤشّر ──
    await page.evaluate(() => {
        const field = document.querySelector('[data-properties-for] textarea')
        field.value = 'أهلاً !'
        field.dispatchEvent(new Event('input', { bubbles: true }))
        field.focus()
        field.setSelectionRange(6, 6)
    })
    await settle()
    await clickText('اسم العميل', '[data-properties-for]')
    await settle()
    current = await design()
    ok(current.blocks.find((block) => block.id === textId).props.text === 'أهلاً {first_name}!', `chips: inserted at the cursor, got ${current.blocks.find((block) => block.id === textId).props.text}`)
    ok((await previewText('text')) === 'أهلاً Sara!', 'preview: the variable shows a sample value')
    const chipLabels = await page.evaluate(() => [...document.querySelectorAll('[data-properties-for] textarea ~ div button')].map((b) => b.textContent.trim()))
    ok(!chipLabels.some((label) => label.includes('URL encoded')), 'chips: URL tokens are kept for links')

    await page.evaluate(() => {
        const range = document.querySelector('[data-properties-for] input[type=range]')
        range.value = '20'
        range.dispatchEvent(new Event('input', { bubbles: true }))
    })
    await settle()
    ok((await design()).blocks.find((block) => block.id === textId).props.font_size === 20, 'properties: the font size slider')

    // ── الجوال ──
    await clickSel('[data-device="mobile"]')
    await settle()
    ok(await page.evaluate(() => document.querySelector('[data-preview-frame]').style.maxWidth === '375px'), 'preview: mobile width')
    await clickSel('[data-device="desktop"]')

    // ── مكتبة الصور: اختيار ──
    await clickSel('[data-tab="elements"]')
    await settle()
    await clickSel('[data-palette="image"]')
    await settle()
    current = await design()
    const imageId = current.blocks.find((block) => block.type === 'image').id
    await clickSel(`[data-block-id="${imageId}"]`)
    await settle()
    await clickText('Choose from the image library', '[data-properties-for]')
    await settle(400)
    let requests = await page.evaluate(() => window.__requests.splice(0))
    ok(requests.some((r) => r.method === 'get' && r.url === '/campaigns/media-history' && r.params.type === 'IMAGE'), `library: loads the organization images, got ${JSON.stringify(requests)}`)
    ok((await page.$$('[data-library-item]')).length === 2, 'library: shows the images')
    ok(await page.evaluate(() => document.querySelector('[data-library-confirm]').disabled), 'library: confirm waits for a choice')

    await page.type('[data-library-search]', 'logo')
    await settle(500)
    requests = await page.evaluate(() => window.__requests.splice(0))
    ok(requests.some((r) => r.params?.search === 'logo') && (await page.$$('[data-library-item]')).length === 1, 'library: search by name')
    await page.evaluate(() => { const s = document.querySelector('[data-library-search]'); s.value = ''; s.dispatchEvent(new Event('input', { bubbles: true })) })
    await settle(500)

    await clickSel('[data-library-item="u1"]')
    await clickSel('[data-library-confirm]')
    await settle(400)
    ok((await design()).blocks.find((block) => block.id === imageId).props.src.endsWith('#banner'), 'library: the chosen image goes into the element')
    ok((await page.$$('[data-library-item]')).length === 0, 'library: closes after choosing')

    // ── مكتبة الصور: رفع ──
    await clickText('Change image', '[data-properties-for]')
    await settle(400)
    const input = await page.$('[data-library-file]')
    await input.uploadFile(gifPath)
    await settle(300)
    ok((await page.evaluate(() => document.querySelector('[data-library-error]')?.innerText)) === 'Upload a JPG or PNG image.', 'upload: other formats are refused before sending')
    await input.uploadFile(pngPath)
    await settle(400)
    requests = await page.evaluate(() => window.__requests.splice(0))
    ok(requests.some((r) => r.method === 'post' && r.url === '/templates/channel/email/images' && r.file === 'new-banner.png'), `upload: posts the image, got ${JSON.stringify(requests)}`)
    ok(await page.evaluate(() => document.querySelector('[data-library-item="new"]')?.getAttribute('aria-pressed') === 'true'), 'upload: the new image is listed and selected')
    await clickSel('[data-library-confirm]')
    await settle(400)
    ok((await design()).blocks.find((block) => block.id === imageId).props.src.endsWith('#new'), 'upload: confirm uses the uploaded image')

    // ── أخطاء الخادم ──
    await page.evaluate(() => window.setErrors({ 'design.blocks.1.props.text': 'This text is too long.', 'design.blocks': 'Add some content to the email.' }))
    await clickSel('[data-tab="elements"]')
    await settle()
    ids = await rows()
    ok(await page.evaluate((id) => document.querySelector(`[data-block-row="${id}"]`).className.includes('border-red-400'), ids[1]), 'errors: the element with an error is marked')
    ok((await page.evaluate(() => document.querySelector('[data-design-error]')?.innerText)) === 'Add some content to the email.', 'errors: the general message')
    await page.evaluate(() => window.setErrors({}))

    // ── قالب جاهز فوق محتوى: تأكيد ──
    await clickSel('[data-open-presets]')
    await settle(400)
    await clickSel('[data-preset="welcome"]')
    await settle(500)
    ok((await page.evaluate(() => document.body.innerText)).includes('Replace the current design?'), 'presets: replacing asks first')
    ok((await types()).includes('divider'), 'presets: nothing changes before confirming')
    await clickText('Replace')
    await settle(1500)
    ok((await previewText('heading')) === 'Welcome aboard', 'presets: confirmed replacement')

    ok(errors.length === 0, 'page errors: ' + errors.join('; '))
} finally {
    await browser.close()
    rmSync(tmp, { recursive: true, force: true })
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ email-builder: ${checks} checks`)
