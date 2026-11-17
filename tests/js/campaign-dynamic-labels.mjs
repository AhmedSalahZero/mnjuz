/**
 * عناوين «ثابت/ديناميكي» وحقول جهة الاتصال في نموذج الحملة.
 *
 * العطل: المفاتيح كانت بحروف صغيرة (trans('contact first name')، trans('static'))
 * والترجمة في lang بحروف كبيرة («Contact first name»، «Static»). vue-i18n يفرّق
 * بين الحالتين فيطبع المفتاح كما هو، فتظهر القائمة بالإنجليزية في الواجهة العربية.
 *
 * وقيم value هي ما يُحفظ في الحملة ويفهمه المُرسِل (TemplateTrait::getParameters)،
 * فتبقى كما هي.
 */
import { readFileSync } from 'node:fs'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

const form = read('../../resources/js/Components/CampaignForm.vue')
const languages = {
    ar: JSON.parse(read('../../lang/ar.json')),
    en: JSON.parse(read('../../lang/en.json')),
}

let checks = 0
const fail = []
const ok = (condition, msg) => {
    checks++
    if (!condition) fail.push(msg)
}

function options(name) {
    const block = form.match(new RegExp(`const ${name} = ref\\(\\[([\\s\\S]*?)\\]\\)`))
    ok(block !== null, `لم نجد ${name} في CampaignForm.vue`)
    if (!block) return []

    return [...block[1].matchAll(/value:\s*'([^']+)',\s*label:\s*trans\('([^']+)'\)/g)]
        .map(([, value, key]) => ({ value, key }))
}

const variable = options('variableOptions')
const dynamic = options('dynamicOptions')

ok(JSON.stringify(variable.map((o) => o.value)) === JSON.stringify(['static', 'dynamic']), 'قيم variableOptions تغيّرت')
ok(
    JSON.stringify(dynamic.map((o) => o.value)) === JSON.stringify(['first name', 'last name', 'name', 'phone', 'email']),
    'قيم dynamicOptions تغيّرت — وهي ما يُحفظ في الحملات القديمة'
)

for (const { key } of [...variable, ...dynamic]) {
    for (const [code, messages] of Object.entries(languages)) {
        ok(typeof messages[key] === 'string' && messages[key] !== '', `«${key}» ليس في lang/${code}.json`)
    }
}

ok(languages.ar['Contact first name'] === 'الاسم الأول لجهة الاتصال', 'عنوان الاسم الأول بالعربية')
ok(languages.ar['Dynamic'] === 'ديناميكي', 'عنوان ديناميكي بالعربية')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
