/**
 * نقاط الكتابة في الـ widget (core/typing.ts): مدّتها، وأيّ فقاعة تسبقها.
 * سلوكها في النافذة نفسها (ما يُخفى ومتى يظهر): webchat-widget-render.mjs.
 *
 * يُشغَّل من جذر المشروع: node tests/js/webchat-typing.mjs
 */
import { firstNewBotBubble, typingDelay, TYPING_MAX_MS, TYPING_MIN_MS } from '../../resources/widget/src/core/typing.ts'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

// ── المدّة: ثانيتان إلى ثلاث كما طلب التاجر، والأطول أطول ──
{
    is([TYPING_MIN_MS, TYPING_MAX_MS], [2000, 3000], 'الحدّان')
    is(typingDelay(''), TYPING_MIN_MS, 'نصّ فارغ: أقصر مدّة')
    is(typingDelay(null), TYPING_MIN_MS, 'بلا نصّ (صورة): أقصر مدّة')
    is(typingDelay('   '), TYPING_MIN_MS, 'المسافات لا تُحسب')
    is(typingDelay('ادخل أسمك') > TYPING_MIN_MS, true, 'سؤالٌ قصير فوق الأدنى قليلاً')
    is(typingDelay('ادخل أسمك') < typingDelay('سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.'), true, 'الأطول أطول')
    is(typingDelay('ب'.repeat(5000)), TYPING_MAX_MS, 'نصّ طويل جداً: لا يتجاوز الأقصى')

    for (const text of ['', 'هلا', 'ما اسمك؟', 'x'.repeat(66), 'x'.repeat(67), 'x'.repeat(500)]) {
        const ms = typingDelay(text)
        is(ms >= TYPING_MIN_MS && ms <= TYPING_MAX_MS, true, `بين الحدّين (${text.length} حرفاً)`)
    }
}

// ── أيّ فقاعة تُمسك: أوّل فقاعة آلية بعد لحظة الضغط ──
{
    const bubble = (id, direction) => ({ id, direction })
    // بعد «أوافق»: نصّ الموافقة، ثم ضغطة الزائر، ثم سؤال الاسم.
    const afterConsent = [bubble('l:consent', 'out'), bubble('l:consented', 'in'), bubble('l:q:name', 'out')]
    is(firstNewBotBubble(afterConsent, 1), 2, 'الموافقة: فقاعة الزائر تظهر، والسؤال بعدها يُمسك')

    // بعد آخر إجابة وقبل بدء الجلسة: لا فقاعة آلية بعد ⇐ لا نقاط بعد.
    const lastAnswer = [bubble('l:q:name', 'out'), bubble('l:a:name', 'in')]
    is(firstNewBotBubble(lastAnswer, 1), -1, 'إجابة الزائر وحدها: لا شيء يُمسك')

    // بدأت الجلسة: رسالة ما بعد الأسئلة.
    is(firstNewBotBubble(lastAnswer.concat(bubble('l:handoff', 'out')), 1), 2, 'رسالة ما بعد الأسئلة تُمسك')

    // ما قبل لحظة الضغط لا يُمسك ولو كان آلياً.
    is(firstNewBotBubble(afterConsent, 3), -1, 'لا جديد بعد لحظة الضغط')
    is(firstNewBotBubble([], 0), -1, 'سجلّ فارغ')
    is(firstNewBotBubble(afterConsent, -4), 0, 'موضع سالب يُعامل صفراً')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`✓ webchat-typing: ${checks} checks`)
