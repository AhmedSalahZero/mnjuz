/**
 * كل ترجمة في lang/*.json تُترجَم بمترجم vue-i18n نفسه.
 *
 * vue-i18n يقرأ نصّ الترجمة كصيغة: «@» بداية رابط لمفتاح آخر، و«{ }» متغيّر.
 * نصّ مثل «john@example.com» يرمي SyntaxError لحظة عرضه، فلا يُرسم المكوّن
 * كله — هكذا كان زرّ تعديل «Update contact» في منشئ المسارات لا يفتح شيئاً.
 * ولأن fallbackLocale هو ar، فترجمة عربية معطوبة تكسر كل اللغات الناقصة.
 *
 * القيم الحرفية تُمرَّر متغيّراتٍ: $t('… {email} …', { email: 'john@example.com' }).
 */
import { readFileSync, readdirSync } from 'node:fs'
import { baseCompile } from '@intlify/message-compiler'

const DIR = new URL('../../lang/', import.meta.url)

let checks = 0
const fail = []

for (const file of readdirSync(DIR).filter((f) => f.endsWith('.json')).sort()) {
    const messages = JSON.parse(readFileSync(new URL(file, DIR), 'utf8'))

    for (const [key, value] of Object.entries(messages)) {
        if (typeof value !== 'string') continue
        checks++

        const errors = []
        baseCompile(value, { onError: (error) => errors.push(error.message) })

        if (errors.length) {
            fail.push(`${file}: «${key}» — ${errors[0]}`)
        }
    }
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
