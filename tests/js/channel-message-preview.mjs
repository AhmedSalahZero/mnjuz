/**
 * معاينة القالب المحفوظ بقيم نموذجية مكان المتغيّرات — كما سيراها العميل.
 */
import { previewText, SAMPLE_VALUES } from '../../resources/js/Composables/channelMessagePreview.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا ${JSON.stringify(expected)} ووجدنا ${JSON.stringify(actual)}`)
}

is(previewText('أهلاً {first_name}'), `أهلاً ${SAMPLE_VALUES.first_name}`, 'متغيّر معروف')
is(previewText('{FIRST_NAME}'), SAMPLE_VALUES.first_name, 'بلا حساسية لحالة الأحرف')
is(previewText('https://x.example/?n={url:full_name}'), `https://x.example/?n=${encodeURIComponent(SAMPLE_VALUES.full_name)}`, 'المرمّز للروابط')
is(previewText('{custom_field}'), '{custom_field}', 'المجهول يبقى كما هو')
is(previewText(null), '', 'بلا نصّ')
is(previewText('{first_name}', { first_name: 'X' }), 'X', 'قيم مخصّصة')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`channel-message-preview: ${checks} checks passed`)
