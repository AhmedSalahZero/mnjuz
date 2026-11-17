/**
 * إدراج رمز (متغيّر مثل {first_name}) مكان المؤشّر في حقل نصّ، أو مكان
 * المحدد منه؛ وبلا حقل (لم يُركَّز بعد) في الآخر.
 *
 * @returns {{text: string, caret: number}} النصّ الجديد وموضع المؤشّر بعد الرمز.
 */
export const insertAtCursor = (text, token, field = null) => {
    const value = String(text ?? '')

    if (!field || typeof field.selectionStart !== 'number') {
        return { text: value + token, caret: value.length + token.length }
    }

    const start = field.selectionStart
    const end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start

    return { text: value.slice(0, start) + token + value.slice(end), caret: start + token.length }
}
