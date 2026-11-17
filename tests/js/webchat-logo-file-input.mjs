/**
 * صفحة إعدادات المحادثة المباشرة لا يظهر أسفلها شريط أبيض
 * (resources/js/Pages/User/Settings/WebChat.vue) في Chrome حقيقي.
 *
 * حقل رفع الشعار مخفيّ بـ sr-only، أي position: absolute. وبلا relative على
 * الـ label لا يكون له سلف positioned، فيتموضع على الصفحة نفسها عند مكانه في
 * عمود المحتوى — أسفل الشاشة بكثير، خارج العمود المُمرَّر فلا يقصّه. فيصير
 * المستند أطول من الشاشة: حين يبلغ العمود آخره يكمل التمرير على المستند، فيصعد
 * الـ layout (md:h-screen) ويظهر تحته الـ body أبيض. والضغط على «Upload» يمرّر
 * الصفحة كلّها إلى الحقل كذلك.
 *
 * الصفحة الحقيقية داخل Settings/Layout.vue الحقيقي، مبنيّة بـ Vite وCSS من
 * Tailwind المشروع نفسه. Layout/App.vue (Echo والقائمة…) بديلٌ بأبعاده وكلاساته
 * نفسها، و@inertiajs/vue3 بديلٌ يسجّل الطلبات بدل إرسالها.
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

const tmp = mkdtempSync(join(tmpdir(), 'webchat-logo-input-'))

// Tailwind المشروع (postcss.config.js وtailwind.config.js من الجذر).
writeFileSync(join(tmp, 'tailwind.css'), '@tailwind base;\n@tailwind utilities;\n')

// Layout/App.vue بكلاسات الحاويات نفسها، والقائمة الجانبية بعرضها (w-80).
writeFileSync(join(tmp, 'AppLayout.vue'), `
<template>
  <div class="direction-rtl">
    <div id="layout" class="md:mt-0 md:pt-0 flex md:h-screen w-full tracking-[0.3px] bg-gray-300/10">
      <aside class="md:flex flex-col h-full bg-white hidden relative border-r w-80">menu</aside>
      <div class="md:min-h-screen flex flex-col w-full min-w-0">
        <slot />
      </div>
    </div>
  </div>
</template>
`)

// Inertia بديلاً: الطلبات تُسجَّل فقط.
writeFileSync(join(tmp, 'inertia.js'), `
import { defineComponent, h, reactive } from 'vue'
window.__calls = []
export const page = reactive({
    url: '/settings/webchat',
    props: { auth: { user: { organization_team_role: 'owner' } }, settingsModuleWebChat: true, settingsModuleWorkingHours: true, organization: null },
})
export const usePage = () => page
export const Link = defineComponent({
    props: { href: String },
    setup: (props, { slots }) => () => h('a', { href: props.href, onClick: (e) => e.preventDefault() }, slots.default?.()),
})
export const router = {
    post: (url, data, o) => window.__calls.push({ method: 'post', url }),
    delete: (url, o) => window.__calls.push({ method: 'delete', url }),
}
export function useForm(initial) {
    const defaults = { ...initial }
    const form = reactive({
        ...initial,
        errors: {},
        processing: false,
        transform() { return form },
        reset() { Object.assign(form, defaults) },
        clearErrors() { form.errors = {} },
        post(url, o = {}) { window.__calls.push({ method: 'post', url }); o.onSuccess?.() },
    })
    return form
}
`)

writeFileSync(join(tmp, 'harness.js'), `
import './tailwind.css'
import { createApp, h } from 'vue'
import { page } from './inertia.js'
import WebChat from '@/Pages/User/Settings/WebChat.vue'

const question = (target) => ({ target, field: null, validation: target === 'name' ? 'text' : target, required: true, label_ar: null, label_en: null })

const widget = {
    settings: {
        enabled: true,
        allowed_domains: [],
        appearance: {
            color: '#034737', position: 'right', offset_x: 20, offset_y: 20,
            title: 'تواصل معنا', subtitle: 'نردّ خلال دقائق', welcome_message: 'أهلاً بك',
            title_en: '', subtitle_en: '', welcome_message_en: '', language: 'ar',
        },
        prechat: { default_country: 'SA', consent_text: '', consent_text_en: '' },
        behavior: { show_agent_names: true, sound: true, uploads: true },
    },
    questions: [question('name'), question('email'), question('phone')],
    contact_fields: [],
    snippet: '<script src="https://app.test/webchat/loader.js" data-key="abc" async></script>',
    preview_url: 'about:blank',
    logo: null,
    faqs: [],
    faqs_max: 30,
}

const app = createApp({ render: () => h(WebChat, { modules: [], widget }) })
app.config.globalProperties.$t = (key) => key
app.config.globalProperties.$page = page
app.mount('#host')
`)

// Settings/Layout.vue يستورد ./../Layout/App.vue: يُستبدل بالبديل أعلاه.
const stubAppLayout = {
    name: 'stub-app-layout',
    enforce: 'pre',
    resolveId(source, importer) {
        if (importer?.endsWith('/Pages/User/Settings/Layout.vue') && source.endsWith('Layout/App.vue')) {
            return join(tmp, 'AppLayout.vue')
        }
        return null
    },
}

let html

try {
    const result = await build({
        configFile: false,
        root,
        logLevel: 'silent',
        plugins: [stubAppLayout, vue()],
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

const browser = await puppeteer.launch({ executablePath: chrome, headless: 'new', args: ['--no-sandbox'] })

try {
    const page = await browser.newPage()
    await page.setViewport({ width: 1440, height: 800 })
    const errors = []
    page.on('pageerror', (e) => errors.push(e.message))
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
    await page.setContent(html, { waitUntil: 'load' })

    const settle = () => new Promise((r) => setTimeout(r, 120))
    // عمود المحتوى في Settings/Layout.vue: هو ما يُمرَّر، لا الصفحة.
    const column = 'div.md\\:overflow-y-auto.h-full'
    const state = () => page.evaluate((sel) => ({
        scrollY: window.scrollY,
        documentHeight: document.documentElement.scrollHeight,
        viewport: window.innerHeight,
        layoutBottom: Math.round(document.getElementById('layout').getBoundingClientRect().bottom),
        columnScrollable: document.querySelector(sel).scrollHeight > document.querySelector(sel).clientHeight,
    }), column)
    const unmoved = (s) => s.scrollY === 0 && s.documentHeight <= s.viewport && s.layoutBottom === s.viewport

    const logoInput = 'input[type=file][accept="image/png,image/jpeg,image/webp"]'
    ok(await page.$(logoInput) !== null, 'setup: the logo file input is on the page')
    ok(await page.evaluate((sel) => getComputedStyle(document.querySelector(sel)).position === 'absolute', logoInput), 'setup: Tailwind sr-only is applied to the logo input')

    // ── أول ما تفتح الصفحة ──
    let s = await state()
    ok(s.columnScrollable, `setup: the settings column is taller than the screen (the case that shows the strip), got ${JSON.stringify(s)}`)
    ok(unmoved(s), `load: the page itself must not be scrollable, got ${JSON.stringify(s)}`)

    // ── بعد النزول لآخر الإعدادات، ثم عجلة الماوس مرّة أخرى (scroll chaining) ──
    await page.evaluate((sel) => { const c = document.querySelector(sel); c.scrollTop = c.scrollHeight }, column)
    await settle()
    await page.mouse.move(900, 400)
    await page.mouse.wheel({ deltaY: 2000 })
    await settle()
    s = await state()
    ok(unmoved(s), `bottom: no white strip under the layout after scrolling past the end, got ${JSON.stringify(s)}`)

    // ── الحقل المخفيّ داخل حدود الـ label، لا في مكان آخر من الصفحة ──
    const inside = await page.evaluate((sel) => {
        const el = document.querySelector(sel)
        const box = el.getBoundingClientRect()
        const label = el.closest('label').getBoundingClientRect()
        const slack = 2 // sr-only: margin -1px
        return box.top >= label.top - slack && box.bottom <= label.bottom + slack
            && box.left >= label.left - slack && box.right <= label.right + slack
    }, logoInput)
    ok(inside, 'logo: the hidden input sits inside its label')

    // ── التركيز على الحقل (ما يفعله الضغط على الـ label) ──
    await page.evaluate((sel) => { window.scrollTo(0, 0); document.querySelector(sel).scrollTop = 0 }, column)
    await page.focus(logoInput)
    await settle()
    s = await state()
    ok(unmoved(s), `focus: the layout must stay in place, got ${JSON.stringify(s)}`)
    await page.evaluate(() => document.activeElement?.blur())

    // ── الضغط الفعلي على «Upload»: يفتح منتقي الملفات ولا يُزيح شيئاً ──
    const label = await page.evaluateHandle((sel) => document.querySelector(sel).closest('label'), logoInput)
    await label.evaluate((el) => el.scrollIntoView({ block: 'center' }))
    await settle()
    const [chooser] = await Promise.all([page.waitForFileChooser({ timeout: 5000 }), label.click()])
    ok(!!chooser, 'click: the Upload label opens the file chooser')
    await settle()
    s = await state()
    ok(unmoved(s), `click: the layout must stay in place, got ${JSON.stringify(s)}`)
    await chooser.cancel()

    ok(errors.length === 0, 'page errors: ' + errors.join('; '))
} finally {
    await browser.close()
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ webchat-logo-file-input: ${checks} checks`)
