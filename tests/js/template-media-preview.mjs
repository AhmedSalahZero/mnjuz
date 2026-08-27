/**
 * معاينة وسائط القالب.
 *
 * العطل: عميلة اختارت صورة استخدمتها سابقاً في حملة، فلم تتغيّر المعاينة
 * ولا ظهرت الصورة، ففهمت أن «الصورة غير موجودة». السبب أن CampaignForm
 * يستدعي WhatsappTemplate بلا placeholder فيبقى على true دائماً، ثم أن
 * المعاينة كانت تقرأ value — وهو عند الرفع كائن File لا رابطاً.
 */
import {
    headerPreviewSource,
    mediaPreviewSource,
    shouldShowPlaceholder,
} from '../../resources/js/Composables/templateMediaPreview.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}

// ----------------------------------------- الأشكال الثلاثة للمعامل

is(mediaPreviewSource({ selection: 'history', value: 'f54a77a6-4ff9-4d75-84c4-e1b2ac80ebfe', url: 'https://s3.amazonaws.com/a.png' }),
   'https://s3.amazonaws.com/a.png', 'ملف سابق: url هو المعروض لا المعرّف')

is(mediaPreviewSource({ selection: 'upload', value: { name: 'a.png' }, url: 'data:image/png;base64,AAAA' }),
   'data:image/png;base64,AAAA', 'رفع جديد: data URL لا كائن الملف')

is(mediaPreviewSource({ selection: 'default', value: 'https://cdn.example.com/saved.png' }),
   'https://cdn.example.com/saved.png', 'حملة محفوظة: value وحده')

// ----------------------------------------- ما لا يصلح مصدراً

is(mediaPreviewSource({ value: '4::aW1hZ2UvcG5n', url: '4::aW1hZ2UvcG5n' }), '',
   'header_handle من Meta ليس رابطاً — لا صورة مكسورة')
is(mediaPreviewSource({ value: null, url: null }), '', 'لا قيمة')
is(mediaPreviewSource({ value: { name: 'a.png' } }), '', 'كائن File وحده')
is(mediaPreviewSource(null), '', 'معامل غائب')
is(mediaPreviewSource(undefined), '', 'معامل undefined')
is(mediaPreviewSource({ url: '   https://x.test/a.png  ' }), 'https://x.test/a.png', 'يُشذَّب البياض')
is(mediaPreviewSource({ url: '/media/public/a.png' }), '/media/public/a.png', 'مسار محلّي مطلق مقبول')
is(mediaPreviewSource({ url: 'blob:http://localhost/abc' }), 'blob:http://localhost/abc', 'blob مقبول')
is(mediaPreviewSource({ url: 'javascript:alert(1)' }), '', 'javascript: مرفوض')
is(mediaPreviewSource({ url: 'a.png' }), '', 'اسم ملف مجرّد ليس مصدراً')

// ----------------------------------------- الترويسة كاملة

is(headerPreviewSource({ format: 'IMAGE', parameters: [{ url: 'https://x.test/a.png' }] }),
   'https://x.test/a.png', 'ترويسة صورة')
is(headerPreviewSource({ format: 'TEXT', parameters: [{ value: 'https://x.test/a.png' }] }), '',
   'ترويسة نصّية لا معاينة وسائط لها')
is(headerPreviewSource({ format: null, parameters: [] }), '', 'بلا صيغة')
is(headerPreviewSource({ format: 'IMAGE', parameters: [] }), '', 'بلا معاملات')
is(headerPreviewSource({ format: 'VIDEO', parameters: [{ url: 'https://x.test/a.mp4' }] }),
   'https://x.test/a.mp4', 'ترويسة فيديو')
is(headerPreviewSource(null), '', 'ترويسة غائبة')

// ----------------------------------------- قرار العنصر النائب

is(shouldShowPlaceholder({ format: 'IMAGE', parameters: [{ url: 'https://x.test/a.png' }] }), false,
   'وُجدت وسائط ⇒ لا عنصر نائب')
is(shouldShowPlaceholder({ format: 'IMAGE', parameters: [{ value: null }] }), true,
   'لا وسائط ⇒ عنصر نائب')
is(shouldShowPlaceholder({ format: 'IMAGE', parameters: [{ url: '4::aW1hZ2U=' }] }), true,
   'header_handle ⇒ عنصر نائب لا صورة مكسورة')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
