/**
 * نقاط استدعاء Echo تمرّر كائن الإعداد — لا مفتاحاً ولا prop غير موجودة.
 *
 * عطلٌ وقع فعلاً: غُيّر توقيع getEchoInstance ليقبل {key, host, port} بدل
 * (key, cluster)، وبقي نداءان على التوقيع القديم. النتيجة broadcast?.key ===
 * undefined، فيُبنى اتصال بلا مفتاح ولا يصرخ أحد: تُحفظ الرسائل ولا تصل
 * لحظياً. وهذا أسوأ عطل لأنه يبدو بطئاً لا عطلاً.
 *
 * ومصدر الإعداد الوحيد الصحيح هو ما يشاركه HandleInertiaRequests باسم
 * broadcast — أي prop صفحة أخرى قد لا يمرّرها متحكّمها أصلاً.
 */
import fs from 'fs';
import path from 'path';

let checks = 0;
const fail = [];
const ok = (cond, msg) => { checks++; if (!cond) fail.push(msg); };

const strip = (s) => s
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .split('\n').filter(l => !l.trim().startsWith('//')).join('\n');

const read = (p) => strip(fs.readFileSync(path.resolve(p), 'utf8'));

// ---------------------------------------------------------- التوقيع

const echo = read('resources/js/echo.js');

ok(/export function getEchoInstance\(\s*broadcast\s*\)/.test(echo),
   'getEchoInstance يجب أن يقبل كائن الإعداد باسم broadcast');

ok(/export function getOrJoinChatChannel\(\s*organizationId,\s*userId,\s*broadcast\s*\)/.test(echo),
   'getOrJoinChatChannel يجب أن يقبل (organizationId, userId, broadcast)');

ok(/console\.error\(/.test(echo) && /broadcast\?\.key/.test(echo),
   'غياب المفتاح يجب أن يُعلَن لا أن يمرّ صامتاً');

ok(/if \(broadcast\?\.host\)/.test(echo),
   'العنوان الصريح يفرّق Reverb عن السحابة — بدونه لا تبديل من الواجهة');

// -------------------------------------------------- نقاط الاستدعاء

const SITES = [
    'resources/js/Pages/User/Layout/App.vue',
    'resources/js/Pages/User/Chat/Index.vue',
    'resources/js/Pages/User/Billing/Index.vue',
];

for (const file of SITES) {
    const src = read(file);
    const calls = [...src.matchAll(/get(?:EchoInstance|OrJoinChatChannel)\(([\s\S]*?)\)\s*$/gm)];

    ok(calls.length > 0, `${file}: لم يُعثر على نداء Echo`);

    for (const [, argsRaw] of calls) {
        const args = argsRaw.split(',').map(a => a.trim()).filter(Boolean);
        const last = args[args.length - 1];

        ok(!/^['"`]/.test(last),
           `${file}: الوسيط الأخير نصّ حرفي (${last}) — المتوقَّع كائن الإعداد`);

        ok(!/getValueByKey\(/.test(argsRaw),
           `${file}: ما زال يمرّر getValueByKey — هذا التوقيع القديم`);

        ok(/broadcast|pusherSettings/.test(last),
           `${file}: الوسيط الأخير (${last}) ليس إعداد البثّ`);
    }
}

// prop صفحة تُقرأ فقط إن كان متحكّمها يمرّرها فعلاً.
const chat = read('resources/js/Pages/User/Chat/Index.vue');
ok(!/getOrJoinChatChannel\([\s\S]*?props\.pusherSettings[\s\S]*?\)/.test(chat),
   'Chat/Index.vue يقرأ props.pusherSettings وChatController لا يمرّرها — undefined');

const billing = read('resources/js/Pages/User/Billing/Index.vue');
ok(/props\.pusherSettings/.test(billing),
   'Billing/Index.vue يستعمل pusherSettings — وBillingController يمرّرها فعلاً');

// ----------------------------------------------------------- النتيجة

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach(f => console.error('   - ' + f));
    process.exit(1);
}
console.log('✅ ' + checks + ' فحصاً — كل نقاط الاتصال على كائن الإعداد المشترك');
