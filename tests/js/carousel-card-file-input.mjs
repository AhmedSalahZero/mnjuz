/**
 * زرّ «اختر صورة» في بطاقات الـ carousel لا يُزيح الصفحة
 * (resources/js/Components/Template/CarouselCardsEditor.vue) في Chrome حقيقي.
 *
 * حقل الملف مخفيّ بـ sr-only، أي position: absolute. وبلا relative على الـ label
 * كان يتموضع على الصفحة نفسها، عند مكانه قبل تمرير النموذج — أسفلها بكثير،
 * خارج اللوحة المُمرَّرة. فيصير المستند قابلاً للتمرير، وحين يُضغط الـ label يأخذ
 * الحقل التركيز فيمرّر المتصفّح الصفحة كلّها إليه: يصعد الـ layout ويظهر تحته
 * فراغ أبيض.
 *
 * المكوّن الحقيقي مبنيّ بـ Vite، وCSS من Tailwind المشروع نفسه (sr-only وrelative
 * كما في الإنتاج)، داخل حاويات بأبعاد Layout/App.vue وTemplates/Add.vue.
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

const tmp = mkdtempSync(join(tmpdir(), 'carousel-file-input-'))

// Tailwind المشروع (postcss.config.js وtailwind.config.js من الجذر).
writeFileSync(join(tmp, 'tailwind.css'), '@tailwind base;\n@tailwind utilities;\n')

// أبعاد الحاويات بأنماط مباشرة (عرض md وما فوقه):
// Layout/App.vue: flex md:h-screen ← Templates/Add.vue: md:overflow-y-hidden
// ← md:h-[88vh] ← اللوحة md:w-[50%] overflow-y-auto، وفوق المحرّر بقية النموذج.
writeFileSync(join(tmp, 'Harness.vue'), `
<script setup>
import { reactive } from 'vue'
import CarouselCardsEditor from '@/Components/Template/CarouselCardsEditor.vue'
import { newCarouselDraft } from '@/Composables/carouselTemplateDraft.js'

const draft = reactive(newCarouselDraft())
</script>
<template>
  <div id="layout" style="display:flex;height:100vh;width:100%">
    <aside style="width:240px;display:flex;flex-direction:column;justify-content:space-between">
      <div>menu</div>
      <div id="team">team</div>
    </aside>
    <div style="min-height:100vh;display:flex;flex-direction:column;width:100%;min-width:0">
      <div id="page" style="display:flex;flex-direction:column;overflow-y:hidden;padding:16px 0">
        <div style="padding-bottom:16px">header</div>
        <div style="display:flex;height:88vh">
          <div id="pane" style="width:50%;padding:32px;overflow-y:auto">
            <div style="height:1200px">name, category, language, body</div>
            <CarouselCardsEditor :draft="draft" />
          </div>
          <div style="width:50%">preview</div>
        </div>
      </div>
    </div>
  </div>
</template>
`)

writeFileSync(join(tmp, 'harness.js'), `
import './tailwind.css'
import { createApp } from 'vue'
import Harness from './Harness.vue'

const app = createApp(Harness)
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
    const state = () => page.evaluate(() => ({
        scrollY: window.scrollY,
        documentHeight: document.documentElement.scrollHeight,
        viewport: window.innerHeight,
        layoutBottom: Math.round(document.getElementById('layout').getBoundingClientRect().bottom),
        pageScrollTop: document.getElementById('page').scrollTop,
    }))
    const unmoved = (s) => s.scrollY === 0 && s.pageScrollTop === 0 && s.layoutBottom === s.viewport

    ok(await page.evaluate(() => !!getComputedStyle(document.querySelector('input[type=file]')).clip.includes('rect')), 'setup: Tailwind sr-only is applied to the file input')

    // المستخدم نزل في النموذج حتى البطاقات.
    await page.evaluate(() => { const pane = document.getElementById('pane'); pane.scrollTop = pane.scrollHeight })
    await settle()

    let s = await state()
    ok(s.documentHeight <= s.viewport, `layout: the page itself must not become scrollable, got ${JSON.stringify(s)}`)

    // ── التركيز على حقل كل بطاقة (ما يفعله الضغط على الـ label) ──
    const inputs = await page.$$('input[type=file]')
    ok(inputs.length === 2, `setup: one file input per card, got ${inputs.length}`)

    for (const [index, input] of inputs.entries()) {
        await input.focus()
        await settle()
        s = await state()
        ok(unmoved(s), `focus card ${index + 1}: the layout must stay in place, got ${JSON.stringify(s)}`)

        // الحقل المخفيّ داخل حدود الـ label، لا في مكان آخر من الصفحة.
        const inside = await page.evaluate((el) => {
            const box = el.getBoundingClientRect()
            const label = el.closest('label').getBoundingClientRect()
            const slack = 2 // sr-only: margin -1px
            return box.top >= label.top - slack && box.bottom <= label.bottom + slack
                && box.left >= label.left - slack && box.right <= label.right + slack
        }, input)
        ok(inside, `card ${index + 1}: the hidden input sits inside its label`)

        await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); document.getElementById('page').scrollTop = 0 })
    }

    // ── الضغط الفعلي على «Choose an image»: يفتح منتقي الملفات ولا يُزيح شيئاً ──
    const label = await page.evaluateHandle(() => [...document.querySelectorAll('label')].find((l) => l.textContent.includes('Choose an image')))
    const [chooser] = await Promise.all([page.waitForFileChooser({ timeout: 5000 }), label.click()])
    ok(!!chooser, 'click: the label opens the file chooser')
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

console.log(`✓ carousel-card-file-input: ${checks} checks`)
