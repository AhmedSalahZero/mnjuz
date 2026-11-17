import type { WidgetMessage } from './types.ts'

/**
 * نقاط الكتابة قبل الفقاعة الآلية التالية (أسئلة البداية، ورسالة ما بعدها،
 * والقائمة والأسئلة الشائعة): بعد أن يضغط الزائر أو يجيب، تظهر النقاط ثانيتين
 * إلى ثلاث ثم الفقاعة — فيرى الزائر من يكتب له لا رسائل تنهال فوراً.
 *
 * طلبها مالك Waz (2026-11-17). تعمل في كل widget بلا إعداد، بقرار المالك.
 */

/** أقصر مدّة وأطولها، ونصيب الحرف بينهما. */
export const TYPING_MIN_MS = 2000
export const TYPING_MAX_MS = 3000
const MS_PER_CHARACTER = 15

/** مدّة النقاط قبل نصٍّ ما: الأطول يُكتب في وقتٍ أطول، بين الحدّين. */
export function typingDelay(text: string | null): number {
    const length = (text ?? '').trim().length

    return Math.min(TYPING_MAX_MS, Math.max(TYPING_MIN_MS, TYPING_MIN_MS + length * MS_PER_CHARACTER))
}

/**
 * أوّل فقاعة آلية (out) من الموضع before فما بعده، أو -1. فقاعات الزائر
 * قبلها (إجابته) تظهر فوراً، والنقاط تسبق هذه وما بعدها.
 */
export function firstNewBotBubble(messages: WidgetMessage[], before: number): number {
    for (let i = Math.max(0, before); i < messages.length; i++) {
        if (messages[i].direction === 'out') {
            return i
        }
    }

    return -1
}
