/**
 * عمر قنوات Echo: لا تعيش بعد خروج صاحبها، ولا تبقى قناة هويةٍ سابقة.
 *
 * عطلٌ ثبت في متصفح حقيقي: الخروج رابط Inertia لا يُعيد تحميل الصفحة، والاتصال
 * محفوظ على مستوى الموديول. فبقي التبويب بعد الخروج مشتركاً في قناة المستخدم
 * ويستقبل محادثاته، ومن دخل بعده في التبويب نفسه استقبل محادثات السابق — وعند
 * عودة الاتصال تُرفض قناة السابق بـ403 فيظهر «فشل الاشتراك» باسمها.
 *
 * نشغّل echo.js نفسه ببدائل لـ Echo و Pusher و router تسجّل ما يحدث.
 */
import { readFileSync } from 'node:fs'

const src = readFileSync('resources/js/echo.js', 'utf8')
	.replace(/^import .*$/gm, '')

const harness = `
const calls = []
let navigateHandlers = []
const router = { on(name, cb) { if (name === 'navigate') navigateHandlers.push(cb); return () => {} } }
const Pusher = {}
globalThis.window = globalThis.window ?? {}
globalThis.document = globalThis.document ?? { cookie: '', querySelector: () => null }
const chain = (name) => {
	const ch = { listen: () => ch, here: () => ch, joining: () => ch, leaving: () => ch, error: () => ch, subscribed: () => ch }
	return ch
}
class Echo {
	constructor() { calls.push('new'); this.connector = { pusher: { connection: { bind() {}, unbind() {} } } } }
	join(name) { calls.push('join ' + name); return chain(name) }
	private(name) { calls.push('private ' + name); return chain(name) }
	leave(name) { calls.push('leave ' + name) }
	disconnect() { calls.push('disconnect') }
}
${src}
export const __calls = calls
export function __navigate(props) { navigateHandlers.forEach((cb) => cb({ detail: { page: { props } } })) }
export function __navigateHandlerCount() { return navigateHandlers.length }
`

const mod = await import('data:text/javascript;base64,' + Buffer.from(harness).toString('base64'))
const { getOrJoinChatChannel, subscribeAgentPresence, __calls: calls, __navigate, __navigateHandlerCount } = mod
const disconnectEcho = mod.disconnectEcho ?? (() => { throw new Error('disconnectEcho غير موجودة') })

let checks = 0
const failures = []
const check = (name, actual, expected) => {
	checks++
	const a = JSON.stringify(actual), e = JSON.stringify(expected)
	if (a !== e) failures.push(`${name}: توقّعنا ${e} فجاء ${a}`)
}
const run = (name, fn) => {
	try { fn() } catch (e) { failures.push(`${name}: رمى ${e.message}`) }
}
const take = () => calls.splice(0)
const config = { provider: 'pusher', key: 'k', cluster: 'us2', host: null, port: 443, force_tls: true }
const userPage = (orgId, userId) => ({ auth: { user: { id: userId } }, organization: { id: orgId } })

// (١) الهوية نفسها في الـlayout وصفحة المحادثات: اشتراك واحد
run('الهوية نفسها', () => {
	getOrJoinChatChannel(1, 7, config)
	getOrJoinChatChannel(1, 7, config)
	check('الهوية نفسها · اتصال وانضمام واحد بلا ترك', take(), ['new', 'join chats.ch1.7'])
})

// (٢) التنقّل داخل لوحة المستخدم لا يقطع الاتصال
run('تنقّل عادي', () => {
	check('الخطّاف مسجّل مرّة واحدة مع أوّل اتصال', __navigateHandlerCount(), 1)
	__navigate(userPage(1, 7))
	check('تنقّل عادي · لا قطع', take(), [])
})

// (٣) تبديل المنشأة: تُترك قناة السابقة قبل الانضمام للجديدة
run('تبديل المنشأة', () => {
	getOrJoinChatChannel(2, 7, config)
	check('تبديل المنشأة · ترك القديمة ثم الانضمام', take(), ['leave chats.ch1.7', 'join chats.ch2.7'])
})

// (٤) الخروج: صفحة الدخول بلا مستخدم تقطع الاتصال كلّه
run('الخروج', () => {
	__navigate({ auth: { user: null }, organization: null })
	check('الخروج · قطع الاتصال', take(), ['disconnect'])
	__navigate({ auth: { user: null }, organization: null })
	check('الخروج مرّتين · لا شيء يُقطع', take(), [])
})

// (٥) دخول مستخدم آخر في التبويب نفسه: اتصال جديد بقناته وحده
run('دخول مستخدم آخر', () => {
	getOrJoinChatChannel(3, 9, config)
	check('مستخدم آخر · اتصال جديد بلا ترك قناة السابق', take(), ['new', 'join chats.ch3.9'])
	check('الخطّاف لا يتكرّر مع اتصال جديد', __navigateHandlerCount(), 1)
})

// (٦) مستخدم بلا منشأة (اختيار المنشأة، صفحات الأدمن): لا بثّ
run('بلا منشأة', () => {
	__navigate({ auth: { user: { id: 9 } }, organization: null })
	check('بلا منشأة · قطع', take(), ['disconnect'])
})

// (٧) قناة حالة الموظفين تُنسى مع القطع فتشترك الصفحة من جديد
run('حالة الموظفين بعد القطع', () => {
	const off = subscribeAgentPresence(3, config, () => {})
	take()
	disconnectEcho()
	take()
	subscribeAgentPresence(3, config, () => {})
	check('حالة الموظفين · اشتراك جديد بعد القطع', take(), ['new', 'private agents.presence.ch3'])
	off()
})

// (٨) قطعٌ بلا اتصال وصفحةٌ بلا props لا يرميان
run('بلا اتصال', () => {
	disconnectEcho()
	take()
	disconnectEcho()
	__navigate(undefined)
	check('بلا اتصال · لا شيء', take(), [])
})

if (failures.length) { failures.forEach((f) => console.error('❌ ' + f)); process.exit(1) }
console.log(`✅ ${checks} فحصاً — القنوات لا تعيش بعد الخروج ولا تبقى قناة هويةٍ سابقة`)
