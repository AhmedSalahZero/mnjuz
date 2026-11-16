/**
 * نصّ الـ header في صفحة القالب (resources/js/Components/Template/HeaderTextArea.vue)
 * حين تصله أمثلة null في Chrome حقيقي.
 *
 * Templates/Add.vue يمرّر form.header.example، وقيمته الأولى null. وافتراضيّ الـ prop
 * ([]) لا يُطبَّق على null بل على undefined وحده، فكان المكوّن:
 * - يطبع «customValues is not an array» في الـ console مع كل فتح للصفحة؛
 * - ويسقط عرضه بـ TypeError إن كان أوّل إدخال نصّاً ملصوقاً فيه {{1}}، إذ يقرأ
 *   customValues[0] من null.
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

const tmp = mkdtempSync(join(tmpdir(), 'header-text-area-'))

// نسختان مستقلّتان، كلٌّ بأمثلة null كما في Add.vue: واحدة للّصق، وأخرى للكتابة ثم «Add variable».
writeFileSync(join(tmp, 'harness.js'), `
import { createApp, h } from 'vue'
import HeaderTextArea from '@/Components/Template/HeaderTextArea.vue'

window.__examples = { paste: [], type: [] }
for (const name of ['paste', 'type']) {
    const app = createApp({
        render: () => h(HeaderTextArea, {
            modelValue: null,
            customValues: null,
            onUpdateExamples: (value) => window.__examples[name].push(JSON.parse(JSON.stringify(value))),
        }),
    })
    app.config.globalProperties.$t = (key) => key
    app.mount('#' + name)
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
            alias: [{ find: /^@\//, replacement: resolve(root, 'resources/js') + '/' }],
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
    html = `<!doctype html><html><head><meta charset="utf-8"></head><body><div id="paste"></div><div id="type"></div><script>${js.replace(/<\/script/g, '<\\/script')}</script></body></html>`
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
    const errors = []
    page.on('pageerror', (e) => errors.push(e.message))
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
    await page.setContent(html, { waitUntil: 'load' })

    const settle = () => new Promise((r) => setTimeout(r, 120))
    const typeInto = (host, value) => page.evaluate((h, v) => {
        const input = document.querySelector('#' + h + ' input[type=text]')
        input.value = v
        input.dispatchEvent(new Event('input', { bubbles: true }))
    }, host, value)
    const examples = (host) => page.evaluate((h) => window.__examples[h], host)

    await settle()
    ok(errors.length === 0, `mount: no console error with null examples, got ${errors.join('; ')}`)
    errors.length = 0

    // ── لصق نصّ فيه متغيّر كأوّل إدخال ──
    await typeInto('paste', 'Hello {{1}}')
    await settle()
    ok(errors.length === 0, `paste: the component keeps rendering, got ${errors.join('; ')}`)
    ok(await page.evaluate(() => document.querySelector('#paste').innerText.includes('Samples for header content')), 'paste: the sample field for {{1}} appears')
    const pasted = await examples('paste')
    ok(pasted.length > 0 && pasted.every(Array.isArray), `paste: examples reach the page as an array, got ${JSON.stringify(pasted)}`)
    errors.length = 0

    // ── المسار المعتاد: كتابة ثم «Add variable» ──
    await typeInto('type', 'Hi ')
    await settle()
    await page.evaluate(() => [...document.querySelectorAll('#type button')].find((b) => b.textContent.includes('Add variable')).click())
    await settle()
    const typed = await examples('type')
    ok(JSON.stringify(typed.at(-1)) === JSON.stringify(['']), `type: adding a variable sends one empty example, got ${JSON.stringify(typed)}`)
    ok(await page.evaluate(() => document.querySelector('#type input[type=text]').value === 'Hi {{1}}'), 'type: the variable is added to the text')

    ok(errors.length === 0, 'page errors: ' + errors.join('; '))
} finally {
    await browser.close()
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}

console.log(`✓ header-text-area-null-examples: ${checks} checks`)
