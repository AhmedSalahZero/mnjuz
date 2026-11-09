<?php

namespace App\Services\Messaging;

use App\Models\Contact;

/**
 * هل يصل الردّ الآلي هذه القناة؟
 *
 * واتساب دائماً. وغيرها بحسب config/messaging.php — المحادثة المباشرة وحدها
 * الآن. يُسأل قبل أن يبدأ أي شيء: قبل إطلاق مهمّة الردّ الآلي، وقبل أن
 * يدفع المساعد الذكي لـ OpenAI ثمن ردٍّ لن يُرسَل.
 */
final class AutomationChannels
{
    public static function allows(?Contact $contact): bool
    {
        return self::allowsChannel($contact?->channel);
    }

    public static function allowsChannel(?string $channel): bool
    {
        $channel = $channel ?: Channel::DEFAULT;

        if ($channel === Channel::WHATSAPP) {
            return true;
        }

        return in_array($channel, (array) config('messaging.automation_channels', [Channel::WEBCHAT]), true);
    }
}
