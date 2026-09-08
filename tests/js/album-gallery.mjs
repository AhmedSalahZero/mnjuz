/**
 * معرض الألبوم.
 *
 * العطل: الشبكة تعرض أربع بلاطات و«N+»، وكانت البلاطة الرابعة تفتح صورتها
 * وحدها — فالصورة الخامسة فما فوق لا سبيل إليها. شكا العملاء أنهم يرون أربع
 * صور من عشر أُرسلت إليهم.
 */
import { galleryItems, galleryIndexOf, stepIndex } from '../../resources/js/Composables/albumGallery.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const image = (id, path = `https://cdn.test/${id}.jpg`) => ({
    id,
    metadata: JSON.stringify({ type: 'image', image: { caption: null } }),
    media: path === null ? null : { path, type: 'image/jpeg' },
})

const video = (id) => ({
    id,
    metadata: JSON.stringify({ type: 'video', video: { caption: null } }),
    media: { path: `https://cdn.test/${id}.mp4`, type: 'video/mp4' },
})

// ---------------------------------------------------------- العناصر

{
    const items = galleryItems([image(1), image(2), image(3), image(4), image(5), image(6)])
    is(items.length, 6, 'المعرض يحمل كل الصور لا الأربع الظاهرة')
    is(items[5].src, 'https://cdn.test/6.jpg', 'السادسة موجودة ويمكن بلوغها')
}

{
    const items = galleryItems([image(1), video(2), image(3)])
    is(items.length, 3, 'الفيديو داخل المعرض لا يُستبعد')
    is(items[1].type, 'video', 'نوع الفيديو محفوظ كي يُعرض مشغّلاً لا صورة')
    is(items[0].type, 'image', 'والصورة تبقى صورة')
}

{
    // بلا ملف: لا يدخل المعرض — السهم يقف على فراغ لا يُفهم
    const items = galleryItems([image(1), image(2, null), image(3)])
    is(items.length, 2, 'الرسالة بلا media لا تدخل المعرض')
    is(items[1].id, 3, 'والترتيب يبقى كما هو بعد استبعادها')
}

is(galleryItems(null).length, 0, 'مدخل غير مصفوفة لا يُسقط شيئاً')
is(galleryItems([{ id: 9, media: { path: '' } }]).length, 0, 'مسار فارغ لا يدخل المعرض')

// ---------------------------------------------------------- الموضع

{
    const items = galleryItems([image(1), image(2), image(3), image(4), image(5)])
    is(galleryIndexOf(items, 5), 4, 'الضغط على بلاطة يفتح المعرض عندها')
    is(galleryIndexOf(items, 1), 0, 'الأولى موضعها صفر')
    is(galleryIndexOf(items, 99), 0, 'رسالة غير موجودة تفتح من الأول لا من فراغ')
    is(galleryIndexOf(null, 1), 0, 'مدخل غير مصفوفة يفتح من الأول')
}

// ---------------------------------------------------------- التنقّل

is(stepIndex(0, 5, 1), 1, 'التالي يتقدّم')
is(stepIndex(1, 5, -1), 0, 'السابق يرجع')
is(stepIndex(4, 5, 1), 0, 'آخر صورة ثم التالي يدور إلى الأولى')
is(stepIndex(0, 5, -1), 4, 'أول صورة ثم السابق يدور إلى الأخيرة')
is(stepIndex(0, 0, 1), 0, 'مجموعة فارغة لا تُخرج موضعاً سالباً')
is(stepIndex(7, 5, 0), 2, 'موضع بدء خارج المدى يُردّ إلى داخله')
is(stepIndex(-1, 5, 0), 4, 'وموضع سالب كذلك')

// ---------------------------------------------------------- الحالة التي شكا منها العملاء

{
    const messages = Array.from({ length: 10 }, (_, i) => image(i + 1))
    const items = galleryItems(messages)
    is(items.length, 10, 'عشر صور تصل المعرض كلها')

    // من البلاطة الرابعة (التي تحمل «6+») نتنقّل حتى العاشرة
    let cursor = galleryIndexOf(items, 4)
    is(cursor, 3, 'يبدأ من البلاطة المضغوطة')

    const visited = [items[cursor].id]
    for (let i = 0; i < 6; i++) {
        cursor = stepIndex(cursor, items.length, 1)
        visited.push(items[cursor].id)
    }
    is(visited.join(','), '4,5,6,7,8,9,10', 'ستّ ضغطات تبلغ العاشرة')
    ok(visited.includes(10), 'الصورة العاشرة صار يمكن رؤيتها')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
