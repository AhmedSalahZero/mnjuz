import type { FieldMode, WidgetConfig } from './types.ts'

type PrechatSettings = WidgetConfig['widget']['prechat']

export interface PrechatValues {
    name: string
    phone: string
    country: string
    email: string
    consent: boolean
    website: string
    elapsed_ms: number
}

/**
 * حقل الجوال كما ضبطه التاجر. خادمٌ أقدم من الإعداد لا يرسله، وكان الرقم
 * عنده إجبارياً — فالغائب إجباري.
 */
export function phoneMode(prechat: PrechatSettings): FieldMode {
    return prechat.phone === 'off' || prechat.phone === 'optional' ? prechat.phone : 'required'
}

/**
 * حقول النموذج كما تُرسل لبدء الجلسة: الجوال ودولته فقط حين يُعرض الحقل
 * ويكتب فيه الزائر شيئاً، والبريد والموافقة بحسب الإعداد.
 */
export function sessionFields(prechat: PrechatSettings, values: PrechatValues): Record<string, unknown> {
    const sendsPhone = phoneMode(prechat) !== 'off' && values.phone.trim() !== ''

    return {
        name: values.name,
        ...(sendsPhone ? { phone: values.phone, country: values.country } : {}),
        ...(prechat.email !== 'off' && values.email ? { email: values.email } : {}),
        ...(prechat.consent_text ? { consent: values.consent } : {}),
        website: values.website,
        elapsed_ms: values.elapsed_ms,
    }
}
