/**
 * عدّاد غير المقروءة عند وصول رسالة عبر البثّ.
 *
 * العطل الذي وقع: الموظّف يفتح محادثة عميل، تصل رسالة منه، فتُعلَّم مقروءة على
 * الخادم فوراً — بينما الشارة بجانب «المحادثات» تزيد واحداً ولا يصحّحها شيء
 * حتى إعادة تحميل الصفحة. السبب أن التعويض كان عبر inject من App.vue، وApp.vue
 * يُستعمل داخل قالب صفحة الشات فهو ابنٌ لا سلف — وinject لا يرى ابناً.
 */
import { openConversationUuid, shouldCountAsUnread, shouldPlaySound } from '../../resources/js/Composables/unreadBadge.js';

const UUID = '49d45ef4-a5d4-4391-8141-75e806602375';
const OTHER = '11111111-2222-3333-4444-555555555555';

let checks = 0;
const fail = [];
const is = (actual, expected, msg) => {
    checks++;
    if (actual !== expected) fail.push(`${msg} — توقّعنا ${expected} ووجدنا ${actual}`);
};

const inbound = (uuid = UUID, extra = {}) => [{
    contact_uuid: uuid,
    value: { type: 'inbound', deleted_at: null, ...extra },
}];

// ------------------------------------------------ استخراج المعرّف

is(openConversationUuid(`/chats/${UUID}`), UUID, 'المسار المباشر');
is(openConversationUuid(`/chats/${UUID}?status=all`), UUID, 'مع معاملات استعلام');
is(openConversationUuid(`/chats/${UUID}#top`), UUID, 'مع مرساة');
is(openConversationUuid('/chats'), null, 'قائمة المحادثات بلا محادثة مفتوحة');
is(openConversationUuid('/contacts'), null, 'صفحة أخرى');
is(openConversationUuid(''), null, 'مسار فارغ');
is(openConversationUuid(undefined), null, 'مسار غير معرَّف');

// ----------------------------------------------------- الاحتساب

is(shouldCountAsUnread(inbound(), `/chats/${UUID}`), false,
   'محادثتها مفتوحة: لا تُحتسب — تُقرأ فور وصولها');

is(shouldCountAsUnread(inbound(), `/chats/${OTHER}`), true,
   'محادثة أخرى مفتوحة: تُحتسب');

is(shouldCountAsUnread(inbound(), '/chats'), true,
   'قائمة المحادثات بلا محادثة مفتوحة: تُحتسب');

is(shouldCountAsUnread(inbound(), '/dashboard'), true,
   'صفحة أخرى تماماً: تُحتسب');

// الصادر لا يُحتسب مهما كان المسار.
is(shouldCountAsUnread([{ contact_uuid: UUID, value: { type: 'outbound', deleted_at: null } }], '/chats'),
   false, 'الصادر لا يُحتسب');

is(shouldCountAsUnread([{ contact_uuid: UUID, value: { type: 'inbound', deleted_at: '2026-08-22' } }], '/chats'),
   false, 'المحذوف لا يُحتسب');

// حمولات ناقصة لا تُسقط المعالج.
is(shouldCountAsUnread([], '/chats'), false, 'حمولة فارغة');
is(shouldCountAsUnread(null, '/chats'), false, 'حمولة معدومة');
is(shouldCountAsUnread([{ value: null }], '/chats'), false, 'قيمة معدومة');
is(shouldCountAsUnread([{ value: { type: 'inbound', deleted_at: null } }], `/chats/${UUID}`), true,
   'بلا contact_uuid لا نستطيع الجزم بأنها المفتوحة — نحتسبها');

// ------------------------------------------------------- الصوت

is(shouldPlaySound(inbound()), true, 'الوارد يُصدر صوتاً');
is(shouldPlaySound([{ value: { type: 'outbound', deleted_at: null } }]), false, 'الصادر لا صوت له');
is(shouldPlaySound([{ value: { type: 'inbound', deleted_at: 'x' } }]), false, 'المحذوف لا صوت له');
is(shouldPlaySound(null), false, 'حمولة معدومة لا صوت لها');

// الصوت مستقلّ عن الاحتساب: رسالة في محادثة مفتوحة تُسمَع ولا تُعَدّ.
is(shouldPlaySound(inbound()) && !shouldCountAsUnread(inbound(), `/chats/${UUID}`), true,
   'محادثة مفتوحة: صوت بلا زيادة في العدّاد');

// ------------------------------------------------------ الأسلاك

import fs from 'fs';

const strip = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '')
    .split('\n').filter(l => !l.trim().startsWith('//')).join('\n');
const read = (f) => strip(fs.readFileSync(f, 'utf8'));

const app = read('resources/js/Pages/User/Layout/App.vue');
const chatIndex = read('resources/js/Pages/User/Chat/Index.vue');
const chatTable = read('resources/js/Components/ChatComponents/ChatTable.vue');

is(/shouldCountAsUnread\(chat, window\.location\.pathname\)/.test(app), true,
   'App.vue يجب أن يقرّر الاحتساب من المسار لا أن يزيد دائماً');

is(/unreadMessages\.value \+= 1/.test(app) && !/if \(shouldCountAsUnread/.test(app), false,
   'App.vue يزيد العدّاد بلا شرط');

// السلك الذي انكسر: App.vue يُستعمل داخل قالب صفحة الشات، فهو ابنٌ لا سلف —
// وinject في الصفحة يرجع null صامتاً. الاعتماد عليه هناك عطلٌ لا يُرى.
is(/inject\(\s*['"]updateTotalUnreadMessages['"]/.test(chatIndex), false,
   'Chat/Index.vue يعتمد على inject من ابنه — سيرجع null دائماً');

is(/<AppLayout/.test(chatIndex), true,
   'الافتراض قائم: صفحة الشات تُغلّف نفسها بـAppLayout (فهو ابن)');

// أمّا ChatTable فداخل الـslot: سلفه AppLayout فعلاً، وinject يصله.
is(/inject\(\s*['"]updateTotalUnreadMessages['"]/.test(chatTable), true,
   'ChatTable داخل الـslot ويصله inject — لا يجب أن يُمَسّ');

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach(f => console.error('   - ' + f));
    process.exit(1);
}
console.log('✅ ' + checks + ' حالة — العدّاد يتبع ما هو مفتوح فعلاً');
