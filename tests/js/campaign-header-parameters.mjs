/**
 * معاملات ترويسة القالب في نموذج الحملة.
 *
 * العطل كما وصل من العميل: الحملة ترسل الفيديو القديم لا الذي اختاره. وسببه
 * أن قالباً بلا example يصل بلا header_handle، فتبقى قائمة المعاملات فارغة:
 * لا يظهر زرّ اختيار الفيديو، ولا يعترض التحقّق، وتخرج الحملة بلا وسائط —
 * فتملؤها Meta من مثال القالب نفسه، وهو أقدم فيديو عنده.
 */
import {
    buildHeaderParameters,
    headerMediaLabel,
    isHistoryItemSelected,
} from '../../resources/js/Composables/campaignHeaderParameters.js'

import { readFileSync } from 'node:fs'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}
const has = (haystack, needle, msg) => {
    checks++
    if (!haystack.includes(needle)) fail.push(`${msg} — لم نجد "${needle}"`)
}
const hasNot = (haystack, needle, msg) => {
    checks++
    if (haystack.includes(needle)) fail.push(`${msg} — وجدنا "${needle}" ولا يجب أن يبقى`)
}

// ------------------------------------- الحالة المكسورة: قالب بلا example

const noExample = buildHeaderParameters('VIDEO', null)
is(noExample.length, 1, 'ترويسة VIDEO بلا example لها معامل واحد لا صفر')
is(noExample[0]?.type, 'VIDEO', 'النوع من صيغة الترويسة')
is(noExample[0]?.selection, 'default', 'لم يُختَر شيء بعد')
is(noExample[0]?.value, null, 'ولا قيمة')
is(noExample[0]?.url, null, 'ولا رابط مثال')

is(buildHeaderParameters('VIDEO', {}).length, 1, 'example فارغ كذلك')
is(buildHeaderParameters('IMAGE', undefined).length, 1, 'وينطبق على IMAGE')
is(buildHeaderParameters('DOCUMENT', null).length, 1, 'وعلى DOCUMENT')

// ------------------------------------- مع example

const withExample = buildHeaderParameters('VIDEO', { header_handle: ['4::dmlkZW8='] })
is(withExample.length, 1, 'مثال واحد ⇐ معامل واحد')
is(withExample[0]?.url, '4::dmlkZW8=', 'المثال يُحفظ في url لا في value')
is(withExample[0]?.value, null, 'المثال ليس اختياراً')

// أكثر من مثال: Meta لا تقبل في ترويسة الوسائط إلا معاملاً واحداً
const twoHandles = buildHeaderParameters('VIDEO', { header_handle: ['a', 'b', 'c'] })
is(twoHandles.length, 1, 'أمثلة كثيرة تبقى معاملاً واحداً')
is(twoHandles[0]?.url, 'a', 'الأوّل هو المعروض')

// ------------------------------------- الترويسة النصّية لم تتغيّر

const text = buildHeaderParameters('TEXT', { header_text: ['أحمد', 'صلاح'] })
is(text.length, 2, 'الترويسة النصّية معامل لكل متغيّر')
is(text[0]?.selection, 'static', 'اختيار ثابت')
is(text[0]?.value, 'أحمد', 'القيمة من المثال')
is(buildHeaderParameters('TEXT', null).length, 0, 'نصّية بلا مثال = بلا متغيّرات')

is(buildHeaderParameters(null, null).length, 0, 'بلا صيغة')
is(buildHeaderParameters('LOCATION', { header_handle: ['x'] }).length, 0, 'صيغة غير مدعومة')

// ------------------------------------- «✓ مختار» يُقرأ من المعامل نفسه

const item = { uuid: 'uuid-new', name: 'new.mp4', path: 'https://s3/new.mp4' }
const other = { uuid: 'uuid-old', name: 'old.mp4', path: 'https://s3/old.mp4' }

is(isHistoryItemSelected({ selection: 'history', value: 'uuid-new' }, item), true, 'المختار يُعلَّم')
is(isHistoryItemSelected({ selection: 'history', value: 'uuid-new' }, other), false, 'وغيره لا')
is(isHistoryItemSelected({ selection: 'default', value: null }, item), false,
   'بعد تحميل قالب جديد لا يبقى شيء معلَّماً')
is(isHistoryItemSelected({ selection: 'upload', value: { name: 'x.mp4' } }, item), false, 'الرفع ليس اختياراً من السجلّ')
is(isHistoryItemSelected(null, item), false, 'بلا معامل')
is(isHistoryItemSelected({ selection: 'history', value: 'uuid-new' }, null), false, 'بلا عنصر')

// ------------------------------------- الاسم المعروض

is(headerMediaLabel({ selection: 'history', value: 'uuid-new' }, [item, other]), 'new.mp4',
   'الاسم من القيمة لا من متغيّر منفصل')
is(headerMediaLabel({ selection: 'history', value: 'uuid-old' }, [item, other]), 'old.mp4',
   'والقديم حين يكون هو المختار فعلاً')
is(headerMediaLabel({ selection: 'history', value: 'uuid-gone' }, [item]), null, 'ملف لم يعد في القائمة')
is(headerMediaLabel({ selection: 'upload', value: { name: 'fresh.mp4' } }), 'fresh.mp4', 'اسم الملف المرفوع')
is(headerMediaLabel({ selection: 'default', value: 'https://s3/saved.mp4' }), 'https://s3/saved.mp4', 'حملة محفوظة')
is(headerMediaLabel({ selection: 'default', value: null }), null, 'بلا قيمة')
is(headerMediaLabel(null), null, 'بلا معامل')

// ------------------------------------- حراسة على المكوّنات

const preview = readFileSync(new URL('../../resources/js/Components/WhatsappTemplate.vue', import.meta.url), 'utf8')

hasNot(preview, '<source :src="mediaSource"',
       'المصدر داخل <source> لا يُعيد تحميل الفيديو — يبقى القديم معروضاً')
has(preview, ':key="mediaSource" :src="mediaSource"',
    'المصدر على <video> نفسه مع key يُبدّل العنصر')

const formSource = readFileSync(new URL('../../resources/js/Components/CampaignForm.vue', import.meta.url), 'utf8')

hasNot(formSource, 'selectedHistoryUuid',
       'الاختيار يُقرأ من المعامل، فلا مصدر حقيقة ثانٍ يتخلّف عنه')
hasNot(formSource, 'readAsDataURL',
       'قراءة فيديو 16MB إلى data URL تُبقي المعاينة على القديم ثوانيَ ثم تُضخّم الطلب')
has(formSource, 'URL.createObjectURL(file)', 'رابط فوري للمعاينة')
has(formSource, 'URL.revokeObjectURL', 'ويُبطَل كي لا تتراكم الملفات في الذاكرة')
has(formSource, 'buildHeaderParameters(', 'بناء المعاملات صار في مكان واحد مُختبَر')
has(formSource, 'handleFileUpload(event, index)', 'الرفع يكتب في المعامل الذي ضغطه العميل')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
