/**
 * اسم المُرسِل في الفقاعة.
 *
 * العطل: أثناء رفع ملفات أرسل الموظّف رسالة نصّية، فظهرت «Sent By: undefined
 * undefined». والسبب أن ثلاثة مصادر تُغذّي الفقاعة بأشكال مختلفة — تحميل
 * الصفحة يرسل first_name و last_name و full_name، والبثّ الحيّ يرسل full_name
 * وحده — والفقاعة كانت تجمع first_name و last_name مباشرةً.
 *
 * فلا يظهر العطل عند تحميل الصفحة ويظهر في الرسالة التي أُرسلت للتوّ، فيبدو
 * عشوائياً. وكلمة «undefined» في وجه المستخدم أسوأ من غياب الاسم.
 */
import { senderName } from '../../resources/js/Composables/senderName.js';

let checks = 0;
const fail = [];
const is = (actual, expected, msg) => {
    checks++;
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`);
};

// ------------------------------------------------ المصادر الثلاثة

is(senderName({ full_name: 'أحمد صلاح' }), 'أحمد صلاح',
   'البثّ الحيّ: full_name وحده');

is(senderName({ first_name: 'أحمد', last_name: 'صلاح' }), 'أحمد صلاح',
   'الفقاعة التفاؤلية: الحقلان المنفصلان');

is(senderName({ first_name: 'أحمد', last_name: 'صلاح', full_name: 'أحمد صلاح' }), 'أحمد صلاح',
   'تحميل الصفحة: الثلاثة معاً');

// full_name يسبق: هو ما يعرضه الخادم فعلاً.
is(senderName({ first_name: 'ا', last_name: 'ب', full_name: 'أحمد صلاح' }), 'أحمد صلاح',
   'full_name مقدَّم على تركيب الحقلين');

// ----------------------------------------- لا «undefined» أبداً

is(senderName({}), '', 'كائن فارغ');
is(senderName(null), '', 'مستخدم معدوم');
is(senderName(undefined), '', 'مستخدم غير معرَّف');
is(senderName({ first_name: undefined, last_name: undefined }), '',
   'الحقلان غير معرَّفين — هذا هو العطل بعينه');
is(senderName({ full_name: undefined }), '', 'full_name غير معرَّف');
is(senderName({ full_name: null, first_name: null, last_name: null }), '', 'الكلّ معدوم');
is(senderName({ full_name: '   ' }), '', 'مسافات ليست اسماً');
is(senderName('أحمد'), '', 'نصّ مكان كائن');

// ------------------------------------------------- أسماء ناقصة

is(senderName({ first_name: 'أحمد' }), 'أحمد', 'الاسم الأوّل وحده — بلا مسافة زائدة');
is(senderName({ last_name: 'صلاح' }), 'صلاح', 'اسم العائلة وحده');
is(senderName({ first_name: 'أحمد', last_name: '' }), 'أحمد', 'عائلة فارغة');
is(senderName({ first_name: '  أحمد  ', last_name: ' صلاح ' }), 'أحمد صلاح', 'تشذيب الأطراف');

// نصّ "undefined" حرفياً — يصل أحياناً من تسلسل خاطئ.
is(senderName({ first_name: 'undefined', last_name: 'undefined' }), '',
   'كلمة undefined النصّية لا تُعرض اسماً');
is(senderName({ first_name: 'null', last_name: 'null' }), '', 'وكذلك null النصّية');

// ------------------------------------------------------ الأسلاك

import fs from 'fs';

const strip = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '')
    .split('\n').filter((l) => !l.trim().startsWith('//') && !l.trim().startsWith('<!--')).join('\n');

const bubble = strip(fs.readFileSync('resources/js/Components/ChatComponents/ChatBubble.vue', 'utf8'));
const page = strip(fs.readFileSync('resources/js/Pages/User/Chat/Index.vue', 'utf8'));

const ok = (cond, msg) => { checks++; if (!cond) fail.push(msg); };

ok(/senderName\(content\.user\)/.test(bubble),
   'الفقاعة لا تستعمل الدالّة المشتركة');

ok(!/content\.user\?\.first_name \+ ' ' \+ content\.user\?\.last_name/.test(bubble),
   'الجمع المباشر عاد — سيُعيد «undefined undefined» لرسائل البثّ');

// الغياب يُخفي السطر كلّه بدل أن يعرض «Sent By:» بلا اسم.
ok(/v-if="props\.type === 'outbound' && senderName\(content\.user\)"/.test(bubble),
   'سطر المُرسِل يظهر ولو بلا اسم');

ok(/full_name: \[props\.user\.first_name, props\.user\.last_name\]/.test(page),
   'الفقاعة التفاؤلية بلا full_name — سيختلف شكلها عن حمولة البثّ');

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach((f) => console.error('   - ' + f));
    process.exit(1);
}
console.log('✅ ' + checks + ' حالة — لا «undefined» في وجه المستخدم');
