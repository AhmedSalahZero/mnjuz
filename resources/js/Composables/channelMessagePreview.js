/**
 * معاينة رسالة قالب محفوظ بقيم نموذجية مكان المتغيّرات ({first_name}…)،
 * كما سيراها العميل تقريباً. نصّ عادي يُعرض بلا v-html.
 */

export const SAMPLE_VALUES = {
    first_name: 'Sara',
    last_name: 'Ahmed',
    full_name: 'Sara Ahmed',
    email: 'sara@example.com',
    phone: '+966500000000',
    organization_name: 'Mnjz',
}

/** يستبدل {key} و {url:key} بالقيمة النموذجية، ويترك المجهول كما هو. */
export const previewText = (text, samples = SAMPLE_VALUES) => String(text ?? '')
    .replace(/\{(url:)?([a-z0-9_]+)\}/gi, (match, url, key) => {
        const value = samples[key.toLowerCase()]

        if (value === undefined) return match

        return url ? encodeURIComponent(value) : value
    })
