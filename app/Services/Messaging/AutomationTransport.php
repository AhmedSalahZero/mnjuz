<?php

namespace App\Services\Messaging;

use App\Models\Contact;
use App\Services\Messaging\Contracts\AutomationSender;
use Closure;

/**
 * بماذا يُرسل الردّ الآلي لهذه الجهة؟
 *
 * لواتساب: الكائن الذي يبنيه المُستدعي اليوم، كما يبنيه — فلا يتغيّر في
 * مسار واتساب شيء (المساعد الذكي ما زال على v18.0، والـ flow يقرأ
 * data->chat كما كان). ولغيرها: ناقل القنوات.
 */
final class AutomationTransport
{
    /** @param  Closure(): AutomationSender  $whatsapp */
    public static function for(?Contact $contact, Closure $whatsapp): AutomationSender
    {
        if ($contact === null || self::isWhatsapp($contact)) {
            return $whatsapp();
        }

        return app(ChannelAutomationTransport::class);
    }

    public static function isWhatsapp(Contact $contact): bool
    {
        return ($contact->channel ?: Channel::DEFAULT) === Channel::WHATSAPP;
    }

    /**
     * هل تصل رسالة آلية هذه الجهة على قناتها غير واتساب الآن؟ يسأله الـ flow
     * قبل أن يرسل عقدته — نظير شرط «للجهة رقم» في واتساب.
     */
    public static function canReach(Contact $contact): bool
    {
        if (!AutomationChannels::allows($contact)) {
            return false;
        }

        $channels = app(ChannelManager::class);
        $account = $channels->accountForContact($contact);

        return $account !== null
            && $account->isActive()
            && $channels->driver($contact->channel ?: Channel::DEFAULT)->canAddress($contact);
    }
}
