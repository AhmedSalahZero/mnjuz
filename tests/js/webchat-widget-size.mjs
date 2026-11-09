/**
 * حجم الـ widget في موقع التاجر: loader.js يُحمَّل في كل صفحة من موقعه،
 * فلا يتجاوز 3KB مضغوطاً. والواجهة الكاملة لا تتجاوز 55KB.
 * يُشغَّل بعد npm run build:widget.
 */
import { existsSync, readFileSync } from 'node:fs'
import { gzipSync } from 'node:zlib'

const budgets = { 'public/widget/v1/loader.js': 3 * 1024, 'public/widget/v1/app.js': 55 * 1024 }
const fail = []

for (const [file, limit] of Object.entries(budgets)) {
    if (!existsSync(file)) {
        fail.push(`${file} غير موجود — شغّل npm run build:widget`)
        continue
    }

    const size = gzipSync(readFileSync(file)).length
    console.log(`${file}: ${(size / 1024).toFixed(1)}KB gzip (الحدّ ${limit / 1024}KB)`)

    if (size > limit) fail.push(`${file} تجاوز حدّه`)
}

if (fail.length) {
    console.error('✗ ' + fail.join('\n✗ '))
    process.exit(1)
}
console.log('✓ webchat-widget-size')
