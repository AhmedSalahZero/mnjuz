<?php

namespace App\Services\WebChat;

use App\Events\WebChatVisitorEvent;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Log;

/**
 * يُبلغ زائر المحادثة المباشرة بما جدّ في محادثته.
 *
 * يُنادى من نقاط عامّة يمرّ بها كل القنوات (مسجِّل الصادر، مهمّة تنزيل
 * الوسائط، مسح المحادثة)، فأوّل ما يفعله الخروج إن لم تكن القناة webchat —
 * قبل أي استعلام. ولا يرمي أبداً: فشل البثّ للزائر لا يُفشل ردّ الموظّف،
 * والـ widget يستدرك بالمزامنة.
 *
 * القناة تُبنى من channel_identifier (معرّف الزائر نفسه) لا بالبحث عن
 * الزائر بجهة الاتصال: ردّ الترحيب الآلي قد يُسجَّل قبل أن يُربط الزائر
 * بجهة اتصاله.
 */
class WebChatVisitorNotifier
{
    public function message(?Chat $chat, ?Contact $contact = null): void
    {
        if (!$chat || ($chat->channel ?? null) !== Channel::WEBCHAT) {
            return;
        }

        try {
            $contact ??= $chat->contact()->first();

            if (!$this->addressable($contact)) {
                return;
            }

            $row = $this->presenter($contact)->present($chat);

            if ($row === null) {
                return;
            }

            event(WebChatVisitorEvent::message((int) $contact->organization_id, (string) $contact->channel_identifier, $row));
        } catch (\Throwable $e) {
            Log::warning('Web chat: visitor notification failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function refresh(?Contact $contact): void
    {
        if (!$contact || ($contact->channel ?? null) !== Channel::WEBCHAT || !$this->addressable($contact)) {
            return;
        }

        try {
            event(WebChatVisitorEvent::refresh((int) $contact->organization_id, (string) $contact->channel_identifier));
        } catch (\Throwable $e) {
            Log::warning('Web chat: visitor refresh failed', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function addressable(?Contact $contact): bool
    {
        return $contact !== null
            && $contact->channel === Channel::WEBCHAT
            && trim((string) $contact->channel_identifier) !== '';
    }

    /** إعدادات الـ widget الذي جاء منه الزائر (إظهار اسم الموظّف مثلاً). */
    private function presenter(Contact $contact): WebChatMessagePresenter
    {
        $account = $contact->channel_account_id ? ChannelAccount::find($contact->channel_account_id) : null;

        return new WebChatMessagePresenter($account ? WebChatSettings::for($account) : WebChatSettings::merge([]));
    }
}
