<?php

namespace App\Http\Requests\WebChat;

/**
 * رسالة من الزائر: نصّ، أو ضغطة على زرّ/قائمة أرسلها البوت.
 *
 * client_message_id يولّده المتصفّح لكل رسالة: به تُعرف إعادة الإرسال
 * (شبكة متقطّعة، ضغطة مزدوجة) فلا تُسجَّل الرسالة مرّتين.
 */
class SendWebChatMessageRequest extends WebChatRequest
{
    public const MAX_TEXT = 4096;

    public function rules(): array
    {
        return [
            'client_message_id' => ['required', 'uuid'],
            'type' => ['required', 'in:text,interactive'],
            'text' => ['required_if:type,text', 'nullable', 'string', 'max:' . self::MAX_TEXT],
            'reply.message_id' => ['required_if:type,interactive', 'nullable', 'uuid'],
            'reply.option_id' => ['required_if:type,interactive', 'nullable', 'string', 'max:200'],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messageText(): string
    {
        return self::cleanText($this->input('text'), self::MAX_TEXT);
    }

    public function clientMessageId(): string
    {
        return strtolower((string) $this->input('client_message_id'));
    }
}
