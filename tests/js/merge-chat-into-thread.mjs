/**
 * اختبار سلوكي للدالّة المشحونة نفسها: يستوردها من مصدرها لا من نسخة.
 * يُشغَّل من ChatThreadMergeTest عبر node.
 */
import { mergeChatIntoThread } from '../../resources/js/Composables/mergeChatIntoThread.js'

const failures = []
const check = (name, actual, expected) => {
	const a = JSON.stringify(actual), e = JSON.stringify(expected)
	if (a !== e) failures.push(`${name}: توقّعنا ${e} فجاء ${a}`)
}

const bubble = (wam) => [{ type: 'chat', value: { wam_id: wam, deleted_at: null } }]
const bcast = (wam, temp) => [{ type: 'chat', value: { wam_id: wam, deleted_at: null }, tempMessageId: temp }]
const ids = (t) => t.map((e) => e[0].value.wam_id)

// ملفّان مرفوعان: بثّ إرسال وبثّ حالة لكلٍّ، بكل تراتيب الوصول الممكنة
const events = {
	send1: () => bcast('wamid.1', 'uuid-A'),
	send2: () => bcast('wamid.2', 'uuid-B'),
	stat1: () => bcast('wamid.1', 'wamid.1'),
	stat2: () => bcast('wamid.2', 'wamid.2'),
}
const permutations = (arr) => arr.length <= 1 ? [arr]
	: arr.flatMap((x, i) => permutations([...arr.slice(0, i), ...arr.slice(i + 1)]).map((p) => [x, ...p]))

for (const order of permutations(['send1', 'send2', 'stat1', 'stat2'])) {
	const thread = [bubble('uuid-A'), bubble('uuid-B')]
	order.forEach((k) => mergeChatIntoThread(thread, events[k]()))
	check(`ترتيب ${order.join('→')}`, ids(thread).sort(), ['wamid.1', 'wamid.2'])
}

// رسالة جديدة واردة بلا معرّف مؤقّت تُضاف
{
	const thread = []
	const r = mergeChatIntoThread(thread, [{ type: 'chat', value: { wam_id: 'wamid.X', deleted_at: null } }])
	check('واردة جديدة', [ids(thread), r.appended], [['wamid.X'], true])
}

// نفس الرسالة مرّتين لا تتكرّر
{
	const thread = []
	const msg = () => [{ type: 'chat', value: { wam_id: 'wamid.Y', deleted_at: null } }]
	mergeChatIntoThread(thread, msg()); mergeChatIntoThread(thread, msg())
	check('تكرار نفس الرسالة', ids(thread), ['wamid.Y'])
}

// المحذوفة تُتجاهل
{
	const thread = [bubble('uuid-A')]
	mergeChatIntoThread(thread, [{ type: 'chat', value: { wam_id: 'wamid.Z', deleted_at: '2026-01-01' }, tempMessageId: 'uuid-A' }])
	check('رسالة محذوفة', ids(thread), ['uuid-A'])
}

// حمولة مشوّهة لا تُسقط الشريط
{
	const thread = [bubble('uuid-A')]
	for (const bad of [null, [], [{}], [{ value: null }], undefined]) mergeChatIntoThread(thread, bad)
	check('حمولة مشوّهة', ids(thread), ['uuid-A'])
}

// ثلاثة ملفات
{
	const thread = ['A', 'B', 'C'].map((x) => bubble(`uuid-${x}`))
	const order = [bcast('w1', 'uuid-A'), bcast('w3', 'wamid.3'), bcast('w2', 'uuid-B'),
		bcast('w3', 'uuid-C'), bcast('w1', 'w1'), bcast('w2', 'w2')]
	order.forEach((c) => mergeChatIntoThread(thread, c))
	check('ثلاثة ملفات بتشابك', ids(thread).sort(), ['w1', 'w2', 'w3'])
}

if (failures.length) { failures.forEach((f) => console.error('❌ ' + f)); process.exit(1) }
console.log(`✅ ${permutations(['a','b','c','d']).length + 5} حالة، بلا تكرار ولا فقدان`)
