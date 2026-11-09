import { defineConfig } from 'vite'
import { fileURLToPath } from 'node:url'

/**
 * أيقونات Font Awesome Free بترخيص CC BY 4.0 الذي يشترط الإسناد.
 * legalComments: 'none' يحذف تعليق الحزمة، وbanner في Rollup يُضاف قبل
 * التصغير فيُحذف معه — فيُلحق هنا بعد التصغير (generateBundle).
 */
const LICENSE_BANNER = '/*! Icons: Font Awesome Free 7 - https://fontawesome.com - License: https://fontawesome.com/license/free (Icons: CC BY 4.0) */'

function licenseBanner() {
    return {
        name: 'mnjz-widget-license-banner',
        apply: 'build',
        generateBundle(_options, bundle) {
            for (const chunk of Object.values(bundle)) {
                if (chunk.type === 'chunk') {
                    chunk.code = LICENSE_BANNER + '\n' + chunk.code
                }
            }
        },
    }
}

/**
 * بناء widget المحادثة المباشرة — منفصل عن تطبيق Vue تماماً.
 *
 * ملفّان بأسماء ثابتة في public/widget/v1:
 *   loader.js  — ما يلصقه التاجر (صغير جداً، يرسم الزرّ وحده)
 *   app.js     — الواجهة الكاملة (Preact وpusher-js)، تُحمَّل عند الضغط
 *
 * Vite 4 لا يبني ملفّي IIFE في مرور واحد، فالأمر يمرّ مرّتين:
 *   npm run build:widget  →  --mode loader  ثم  --mode app
 *
 * بلا laravel-vite-plugin ولا Tailwind: الـ widget يعيش في موقع غيرنا.
 */
export default defineConfig(({ mode }) => {
    const isLoader = mode === 'loader'
    const entry = isLoader ? 'resources/widget/src/loader.ts' : 'resources/widget/src/app/main.tsx'

    return {
        publicDir: false,
        plugins: [licenseBanner()],
        css: { postcss: {} },
        esbuild: {
            jsx: 'automatic',
            jsxImportSource: 'preact',
            legalComments: 'none',
        },
        define: {
            'process.env.NODE_ENV': '"production"',
        },
        build: {
            outDir: 'public/widget/v1',
            // المرور الثاني لا يمسح ما بناه الأول.
            emptyOutDir: false,
            target: 'es2017',
            minify: 'esbuild',
            sourcemap: false,
            reportCompressedSize: true,
            lib: {
                entry: fileURLToPath(new URL(entry, import.meta.url)),
                formats: ['iife'],
                name: isLoader ? 'MnjzWebchatLoader' : 'MnjzWebchatApp',
                fileName: () => (isLoader ? 'loader.js' : 'app.js'),
            },
            rollupOptions: {
                output: { inlineDynamicImports: true },
            },
        },
    }
})
