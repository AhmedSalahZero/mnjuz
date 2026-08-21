/**
 * اختبار سلوكي لبنّاء اتصال البثّ المشحون نفسه.
 *
 * الفرق بين المزوّدَين كلّه في هذه الخيارات: السحابة تشتقّ عنوانها من التجميعة
 * وReverb يحتاج عنواناً صريحاً. تمرير عنوان مُخترَع للسحابة يكسر الاتصال،
 * وإغفاله مع Reverb يجعل التبديل مستحيلاً من الواجهة.
 *
 * نستخرج المنطق من الملف نفسه ونشغّله ببديل عن Echo يلتقط الخيارات.
 */
import { readFileSync } from 'node:fs'

const src = readFileSync('resources/js/echo.js', 'utf8')
const start = src.indexOf('export function getEchoInstance')
const end = src.indexOf('\n}', src.indexOf('return echoInstance;')) + 2
const body = src.slice(start, end)
	.replace('export function', 'function')
	.replace('window.Pusher = Pusher;', '')
	.replace(/document\.querySelector\([^)]*\)\?\.content/, "'csrf'")

let captured = null
const mod = `
let echoInstance = null
class Echo { constructor(o) { captured = o } }
${body}
export { getEchoInstance }
export function reset() { echoInstance = null }
export function seen() { return captured }
export function setSink(fn) { globalThis.__sink = fn }
`.replace(/captured = o/, 'globalThis.__captured = o')
 .replace('export function seen() { return captured }', 'export function seen() { return globalThis.__captured }')

const { getEchoInstance, reset, seen } = await import(
	'data:text/javascript;base64,' + Buffer.from(mod).toString('base64'))

const failures = []
const check = (name, actual, expected) => {
	const a = JSON.stringify(actual), e = JSON.stringify(expected)
	if (a !== e) failures.push(`${name}: توقّعنا ${e} فجاء ${a}`)
}

// (١) السحابة: تجميعة بلا عنوان
reset()
getEchoInstance({ provider: 'pusher', key: 'cloud-key', cluster: 'us2', host: null, port: 443, force_tls: true })
let o = seen()
check('السحابة · السائق', o.broadcaster, 'pusher')
check('السحابة · المفتاح', o.key, 'cloud-key')
check('السحابة · التجميعة', o.cluster, 'us2')
check('السحابة · بلا wsHost', o.wsHost, undefined)
check('السحابة · بلا forceTLS', o.forceTLS, undefined)
check('السحابة · بلا نواقل مقيّدة', o.enabledTransports, undefined)

// (٢) Reverb: عنوان صريح
reset()
getEchoInstance({ provider: 'reverb', key: 'zsgyjtc10xgndtlt5mdj', cluster: 'mt1',
	host: 'reverb.mnjz.net', port: 443, force_tls: true })
o = seen()
check('Reverb · السائق يبقى pusher', o.broadcaster, 'pusher')
check('Reverb · العنوان', o.wsHost, 'reverb.mnjz.net')
check('Reverb · المنفذ', o.wsPort, 443)
check('Reverb · المنفذ المؤمّن', o.wssPort, 443)
check('Reverb · TLS', o.forceTLS, true)
check('Reverb · النواقل', o.enabledTransports, ['ws', 'wss'])
check('Reverb · مسار المصادقة', o.authEndpoint, '/broadcasting/auth')

// (٣) خادم محلّي بلا TLS
reset()
getEchoInstance({ key: 'k', host: 'localhost', port: 8080, force_tls: false })
o = seen()
check('محلّي · المنفذ', o.wsPort, 8080)
check('محلّي · بلا TLS', o.forceTLS, false)

// (٤) إعداد ناقص لا يُسقط الصفحة
for (const bad of [null, undefined, {}, { key: 'k' }]) {
	reset()
	try { getEchoInstance(bad) } catch (e) { failures.push('إعداد ناقص أسقط البنّاء: ' + e.message) }
}
reset(); getEchoInstance({ key: 'k' })
check('بلا تجميعة · قيمة بديلة', seen().cluster, 'mt1')

// (٥) اتصال واحد لا يُعاد بناؤه
reset()
getEchoInstance({ key: 'first', host: 'a.test' })
const first = seen()
getEchoInstance({ key: 'second', host: 'b.test' })
check('اتصال واحد مشترك', seen().key, first.key)

if (failures.length) { failures.forEach(f => console.error('❌ ' + f)); process.exit(1) }
console.log('✅ 18 فحصاً — السحابة بلا عنوان، وReverb بعنوان صريح')
