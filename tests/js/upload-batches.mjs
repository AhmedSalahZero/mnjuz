/**
 * تقسيم المرفقات على سقف الطلب الواحد.
 *
 * عطلٌ وقع على الإنتاج: ثلاثة ملفات PDF (١٩ و٢٧ و٤٦ ميغابايت) تُرفع كلٌّ على
 * حدة بلا مشكلة، وترفع مجتمعةً فتقف عند ٢٪ بلا رسالة ولا خطأ.
 *
 * السبب أن الملفات تُرسل في طلب HTTP واحد، و`post_max_size` يحكم الحمولة كلّها
 * لا كل ملف. وكان الفحص لكل ملف على حدة، فمرّ المجموع (٩٢ ميغابايت) بلا فحص
 * ورفضه الخادم قبل أن يبلغ PHP — ورفضٌ بهذا الموضع لا يُنتج رسالة يراها أحد.
 *
 * والتقسيم أولى من الرفض: المستخدم أراد إرسال ملفاته ولا شأن له بحدود الخادم.
 */
import { OVERHEAD_BYTES, splitIntoBatches, totalBytes } from '../../resources/js/Composables/uploadBatches.js';

const MB = 1024 * 1024;

let checks = 0;
const fail = [];
const is = (actual, expected, msg) => {
    checks++;
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        fail.push(`${msg} — توقّعنا ${JSON.stringify(expected)} ووجدنا ${JSON.stringify(actual)}`);
    }
};

const f = (name, mb) => ({ file: { name, size: mb * MB }, type: 'document' });
const names = (batches) => batches.map((b) => b.map((i) => i.file.name));

// --------------------------------------------- الحالة التي وقعت

/**
 * الملفات الثلاثة الحقيقية مع سقف ٥٠ ميغابايت: تُقسَّم بدل أن تقف.
 */
{
    const files = [f('a', 19), f('b', 27), f('c', 46)];

    is(Math.round(totalBytes(files) / MB), 92, 'المجموع ٩٢ ميغابايت');
    is(names(splitIntoBatches(files, 50 * MB)), [['a', 'b'], ['c']],
       'لم تُقسَّم على سقف الطلب');
}

/** والترتيب محفوظ داخل الدفعات وبينها — العميل يرى ما أُرسل بترتيبه. */
{
    const files = [f('1', 10), f('2', 10), f('3', 10), f('4', 10)];
    is(names(splitIntoBatches(files, 25 * MB)), [['1', '2'], ['3', '4']], 'الترتيب اختلّ');
}

// ------------------------------------------------- ما لا يُقسَّم

{
    const files = [f('a', 5), f('b', 5)];
    is(names(splitIntoBatches(files, 50 * MB)), [['a', 'b']],
       'دفعة تسع في طلب واحد قُسّمت بلا داعٍ');
}

is(splitIntoBatches([], 50 * MB), [], 'لا مرفقات');
is(splitIntoBatches(null, 50 * MB), [], 'مرفقات معدومة');

/** بلا سقف معروف نُبقي الدفعة كما هي بدل إكثار الطلبات. */
{
    const files = [f('a', 900), f('b', 900)];
    is(names(splitIntoBatches(files, Infinity)), [['a', 'b']], 'سقف غير محدود');
    is(names(splitIntoBatches(files, 0)), [['a', 'b']], 'سقف صفر يُعامَل كغير معروف');
    is(names(splitIntoBatches(files, undefined)), [['a', 'b']], 'سقف غير معرَّف');
}

// ------------------------------------------- الملف الأكبر من السقف

/**
 * ملفٌ لا يسع وحده يُرسَل منفرداً: يفشل بخطأ صريح من الخادم بدل أن يجرّ معه
 * ملفات كانت ستنجح.
 */
{
    const files = [f('small', 5), f('huge', 200), f('other', 5)];
    is(names(splitIntoBatches(files, 50 * MB)), [['small'], ['huge'], ['other']],
       'الملف الضخم جرّ معه غيره');
}

{
    is(names(splitIntoBatches([f('huge', 200)], 50 * MB)), [['huge']], 'ملف ضخم وحده');
}

// ------------------------------------------------------- الحدود

/** الهامش محسوب: دفعة تساوي السقف تماماً لا تُرسَل كما هي. */
{
    const files = [f('exact', 50)];
    is(splitIntoBatches(files, 50 * MB).length, 1, 'ملف بحجم السقف');
}

/**
 * الهامش يُحسب فعلاً: مجموعٌ يساوي السقف بالضبط لا يمرّ، لأن الحمولة تحمل
 * معها حدود multipart والمعرّفات والتعليق.
 */
{
    is(splitIntoBatches([f('a', 25), f('b', 25)], 50 * MB).length, 2,
       'مجموعهما ٥٠ = السقف تماماً — الهامش يجب أن يمنع الجمع');

    is(splitIntoBatches([f('a', 24), f('b', 24)], 50 * MB).length, 1,
       'مجموعهما ٤٨ ويسع داخل الهامش — قُسّم بلا داعٍ');
}

/** ملفات بلا حجم معروف لا تُسقط التقسيم. */
{
    const files = [{ file: { name: 'x' }, type: 'document' }, f('y', 5)];
    is(names(splitIntoBatches(files, 50 * MB)), [['x', 'y']], 'حجم غائب');
    is(totalBytes(files), 5 * MB, 'المجموع يتجاهل ما لا حجم له');
}

is(totalBytes([]), 0, 'مجموع فارغ');
is(totalBytes(null), 0, 'مجموع معدوم');

// ------------------------------------- سقف الوكيل الأمامي

/**
 * الحالة الحقيقية على الإنتاج: Cloudflare يرفض ما تجاوز ١٠٠ ميغابايت ويقطع
 * الاتصال قبل أن تبلغ الحمولة الخادم — فلا 413 في سجلّ nginx بل 499، ولا خطأ
 * يصل المتصفّح. الشريط يتجمّد عند النسبة التي بلغها، وهي 100÷المجموع.
 *
 * ورفع post_max_size لا يُصلح شيئاً: الحدّ خارج PHP ولا سبيل إلى قراءته،
 * فالتقسيم يجب أن يقيس على السقف المُعلَن لا على حدّ الخادم.
 */
{
    const files = [f('a', 45), f('b', 45), f('c', 45)];
    const batches = splitIntoBatches(files, 90 * MB);

    is(names(batches), [['a'], ['b'], ['c']], 'ثلاثة ملفات ٤٥ م.ب لم تُفرَّد على سقف ٩٠');

    for (const batch of batches) {
        const bytes = totalBytes(batch);
        checks++;
        if (bytes > 90 * MB - OVERHEAD_BYTES) {
            fail.push(`دفعة بحجم ${Math.round(bytes / MB)} م.ب تتجاوز السقف`);
        }
    }
}

/** ولا دفعة تتجاوز السقف مهما كان الخليط. */
{
    const mixed = [f('a', 12), f('b', 70), f('c', 3), f('d', 40), f('e', 40)];
    for (const batch of splitIntoBatches(mixed, 90 * MB)) {
        checks++;
        if (batch.length > 1 && totalBytes(batch) > 90 * MB - OVERHEAD_BYTES) {
            fail.push('دفعة مختلطة تجاوزت السقف');
        }
    }
}

/** الهامش مُصدَّر ليستعمله الملحن نفسه — رقمان مختلفان يعنيان تناقضاً. */
is(OVERHEAD_BYTES, 256 * 1024, 'الهامش تغيّر');

// ------------------------------------------------------ الأسلاك

import fs from 'fs';

const strip = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '')
    .split('\n').filter((l) => !l.trim().startsWith('//')).join('\n');

const form = strip(fs.readFileSync('resources/js/Components/ChatComponents/ChatForm.vue', 'utf8'));
const ok = (cond, msg) => { checks++; if (!cond) fail.push(msg); };

ok(/splitIntoBatches\(queue, serverMaxPostBytes\.value\)/.test(form),
   'الملحن لا يُقسّم على سقف الطلب');

ok(/max_post_bytes/.test(form),
   'سقف الطلب غير مقروء من الخادم — سيُقاس المجموع بسقف الملف');

ok(!/uploads\.enqueue\(\{[\s\S]{0,200}files: queue\.map/.test(form),
   'ما زال يُرسل الدفعة كلّها في طلب واحد');

// ملفٌ لا يسع في طلب واحد لا يُنقذه تقسيم: يُردّ مبكّراً برسالة مقروءة بدل
// أن يقطع الوكيل الأمامي الاتصال فيتجمّد الشريط.
ok(/file\.size > requestBudget/.test(form),
   'الملحن لا يردّ الملف الأكبر من سقف الطلب');

ok(/REQUEST_OVERHEAD_BYTES/.test(form) && /uploadBatches/.test(form),
   'الهامش غير مشترك بين الملحن والمقسّم — رقمان مختلفان يعنيان تناقضاً');

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach((x) => console.error('   - ' + x));
    process.exit(1);
}
console.log('✅ ' + checks + ' حالة — الدفعة تُقسَّم على قدر الطلب');
