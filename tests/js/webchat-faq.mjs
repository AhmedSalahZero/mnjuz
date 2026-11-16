/**
 * القائمة والأسئلة الشائعة في الـ widget (core/faq.ts): الرحلة، وما يُرسل
 * مع أوّل رسالة، والفقاعات.
 */
import { chooseJourney, faqTranscript, journeyContext, journeyStage, newJourney, parseJourney } from '../../resources/widget/src/core/faq.ts'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

const texts = {
    menuPrompt: 'كيف نقدر نساعدك؟', menuQuestion: 'عندي سؤال', menuProblem: 'عندي مشكلة',
    faqPrompt: 'اختر سؤالك:', faqList: 'الأسئلة الشائعة', morePrompt: 'هل تريد شيئاً آخر؟', more: 'أسئلة أخرى', none: 'لم أجد إجابتي',
    askQuestion: 'اكتب سؤالك', describeProblem: 'صف مشكلتك',
}
const faqs = [
    { id: 7, question_ar: 'كم مدة التوصيل؟', question_en: 'Delivery time?', answer_ar: 'يومان', answer_en: null, image_url: null },
    { id: 9, question_ar: 'طرق الدفع؟', question_en: null, answer_ar: null, answer_en: null, image_url: 'https://app.test/media/cards.png' },
]
const press = (journey, ...ids) => ids.reduce((j, id) => chooseJourney(j, id), journey)
// «نصّ[الأزرار]» لكل فقاعة: يكفي لقراءة السجلّ كما يراه الزائر.
const read = (messages) => messages.map((m) => {
    const options = m.interactive ? (m.interactive.buttons ?? m.interactive.sections[0].rows).map((b) => b.id).join(',') : null
    return `${m.direction}:${m.type === 'image' ? '🖼' : ''}${m.text ?? ''}${options ? `[${options}]` : ''}`
})

// ── الرحلة ──
{
    is(parseJourney(null), null, 'زائر قديم بلا رحلة')
    is(parseJourney('{"v":2,"steps":[]}'), null, 'نسخة أخرى')
    is(parseJourney('{"v":1,"steps":[1]}'), null, 'خطوات تالفة')
    is(parseJourney(JSON.stringify(press(newJourney(), 'menu:question'))).steps, ['menu:question'], 'المحفوظ يُكمل')

    const j = newJourney()
    is(journeyStage(j), 'menu', 'تبدأ بالقائمة')
    is(journeyContext(j), null, 'لم يضغط شيئاً: لا شيء يُرسل')
    is(journeyStage(press(j, 'menu:question')), 'faqs', 'عندي سؤال: الأسئلة')
    is(journeyStage(press(j, 'menu:question', 'faq:7')), 'answered', 'قرأ إجابة')
    is(journeyStage(press(j, 'menu:question', 'faq:7', 'faq:more')), 'faqs', 'أسئلة أخرى')
    is(journeyStage(press(j, 'menu:question', 'faq:none')), 'chat', 'لم يجد سؤاله في القائمة: المحادثة')
    is(journeyStage(press(j, 'menu:problem')), 'chat', 'عندي مشكلة: المحادثة مباشرة')

    const done = press(j, 'menu:question', 'faq:7', 'faq:more', 'faq:9', 'faq:more', 'faq:7', 'faq:none')
    is(journeyContext(done), { menu: 'question', faqs: [7, 9], not_found: true }, 'ما يُرسل: كل سؤال مرّة')
    is(journeyContext({ ...done, sent: true }), null, 'مرّة واحدة')
    is(journeyContext(press(j, 'menu:problem')), { menu: 'problem', faqs: [], not_found: false }, 'عندي مشكلة وحدها')

    is(chooseJourney(j, 'faq:7'), null, 'سؤالٌ قبل «عندي سؤال»')
    is(chooseJourney(press(j, 'menu:question'), 'faq:more'), null, '«أسئلة أخرى» وهو في القائمة')
    is(chooseJourney(press(j, 'menu:problem'), 'menu:question'), null, 'لا رجوع بعد بدء المحادثة')
    is(chooseJourney(j, 'other'), null, 'زرٌّ ليس لها')
}

// ── الفقاعات ──
{
    const j = newJourney()
    is(read(faqTranscript(faqs, j, 'ar', texts)), ['out:كيف نقدر نساعدك؟[menu:question,menu:problem]'], 'القائمة بزرّيها')

    is(read(faqTranscript(faqs, press(j, 'menu:question'), 'ar', texts)), [
        'out:كيف نقدر نساعدك؟', 'in:عندي سؤال', 'out:اختر سؤالك:[faq:7,faq:9,faq:none]',
    ], 'الأسئلة أزراراً، وآخرها «لم أجد إجابتي»')
    is(faqTranscript(faqs, press(j, 'menu:question'), 'en', texts)[2].interactive.buttons.map((b) => b.title),
        ['Delivery time?', 'طرق الدفع؟', 'لم أجد إجابتي'], 'بالإنجليزية إن وُجدت')
    is(read(faqTranscript(null, press(j, 'menu:question'), 'ar', texts)).slice(-1), ['out:اختر سؤالك:'], 'لم تصل الأسئلة بعد')

    is(read(faqTranscript(faqs, press(j, 'menu:question', 'faq:9'), 'ar', texts)), [
        'out:كيف نقدر نساعدك؟', 'in:عندي سؤال', 'out:اختر سؤالك:', 'in:طرق الدفع؟', 'out:🖼', 'out:هل تريد شيئاً آخر؟[faq:more,faq:none]',
    ], 'إجابة بصورة، ثم أسئلة أخرى أو لم أجد')
    const image = faqTranscript(faqs, press(j, 'menu:question', 'faq:9'), 'ar', texts)[4]
    is([image.media.url, image.media.name, image.text], ['https://app.test/media/cards.png', 'طرق الدفع؟', null], 'رابط الصورة، ووصفها السؤال')

    is(read(faqTranscript(faqs, press(j, 'menu:question', 'faq:7', 'faq:more', 'faq:none'), 'ar', texts)).slice(3), [
        'in:كم مدة التوصيل؟', 'out:يومان', 'out:هل تريد شيئاً آخر؟', 'in:أسئلة أخرى', 'out:اختر سؤالك:', 'in:لم أجد إجابتي', 'out:اكتب سؤالك',
    ], 'السجلّ كما جرى')
    is(read(faqTranscript(faqs, press(j, 'menu:problem'), 'ar', texts)), ['out:كيف نقدر نساعدك؟', 'in:عندي مشكلة', 'out:صف مشكلتك'], 'عندي مشكلة')

    // سؤالٌ حُذف بعد قراءته: يسقط، ويبقى للزائر ما يضغطه.
    is(read(faqTranscript(faqs.slice(1), press(j, 'menu:question', 'faq:7'), 'ar', texts)).slice(-1), ['out:هل تريد شيئاً آخر؟[faq:more,faq:none]'], 'سؤال محذوف')

    // سؤالان + «لم أجد» أزرار، وثلاثة فأكثر قائمة.
    const three = [1, 2, 3].map((id) => ({ id, question_ar: `س${id}`, question_en: null, answer_ar: 'ج', answer_en: null, image_url: null }))
    is(faqTranscript(three, press(j, 'menu:question'), 'ar', texts)[2].interactive.kind, 'list', 'ثلاثة أسئلة: قائمة')
    is(faqTranscript(three, press(j, 'menu:question'), 'ar', texts)[2].interactive.button, 'الأسئلة الشائعة', 'زرّ القائمة')
    is(read(faqTranscript([], press(j, 'menu:question'), 'ar', texts)).slice(-1), ['out:اختر سؤالك:[faq:none]'], 'بلا أسئلة: لا يعلق الزائر')

    const ids = faqTranscript(faqs, press(j, 'menu:question', 'faq:7', 'faq:more'), 'ar', texts).map((m) => m.id)
    is(new Set(ids).size === ids.length && ids.every((id) => id.startsWith('l:j:')), true, 'معرّفات محلّية فريدة')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`✓ webchat-faq: ${checks} checks`)
