<?php

namespace App\Services\Messaging\Contracts;

/**
 * ما يُرسل به الردّ الآلي: WhatsappService كما هو، أو ناقل القنوات الأخرى.
 *
 * التواقيع منسوخة من WhatsappService حرفاً بحرف، فكل مُستدعٍ قائم (الردود
 * الآلية، الـ flows، المساعد الذكي، رسالة ساعات العمل، التقييم) يتعامل مع
 * الاثنين بلا فرق — ويبقى مسار واتساب نفس الكائن ونفس النداء.
 *
 * كلاهما يُرجع الشكل القديم: {success, message?, data: {chat?, error?: {message, code}}}.
 */
interface AutomationSender
{
    public function sendMessage($contactUuId, $messageContent, $userId = null, $type = 'text', $buttons = [], $header = [], $footer = null, $buttonLabel = null, $location = null);

    public function executeSendMessage(
        $contactUuId,
        $messageContent,
        $userId = null,
        $type = 'text',
        $buttons = [],
        $header = [],
        $footer = null,
        $buttonLabel = null,
        $messageUUID = null,
        $tempMessageId = null,
        $location = null
    );

    public function sendMedia($contactUuId, $mediaType, $mediaFileName, $mediaFilePath, $mediaUrl, $location, $caption = null, $transcription = null, $userId = null, $tempMessageId = null, $messageUUID = null, $existingChatId = null);
}
