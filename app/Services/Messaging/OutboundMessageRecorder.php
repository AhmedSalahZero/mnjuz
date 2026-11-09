<?php

namespace App\Services\Messaging;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Support\JsonText;
use Illuminate\Support\Facades\Log;

/**
 * تسجيل رسالة صادرة: صفّ في chats، وسطر في chat_logs، وبثّ، و webhook.
 *
 * هذه الكتلة مكرّرة اليوم ثلاث مرّات داخل WhatsappService (نصّ، وسائط،
 * قالب) ومرّة رابعة في RetryMediaWithTranscodeJob. التكرار هو سبب اختلاف
 * السلوك بين المسارات: مسار النصّ يحفظ status=delivered ومسار الوسائط
 * يحفظ sent، ومسار واحد يمرّر tempMessageId والآخر لا.
 *
 * جمعها هنا يجعل كل قناة جديدة تُنتج الصفوف نفسها بالضبط، فتعمل الفقاعة
 * وتطبيق الجوال والتقارير بلا تعديل. ولا نغيّر سلوك واتساب: القيم
 * الافتراضية هنا هي ما تفعله اليوم حرفياً.
 */
class OutboundMessageRecorder
{
    /**
     * تسجيل رسالة نصّية (أو أي رسالة بلا ملف: موقع، تفاعلية، قالب).
     *
     * @param  array  $metadata  بشكل ChatMetadataHelper — العقد مع العارضين
     */
    public function recordText(
        Contact $contact,
        string $providerMessageId,
        array $metadata,
        array $options = []
    ): ?Chat {
        return $this->record($contact, $providerMessageId, $metadata, null, $options + [
            'status' => 'delivered',
        ]);
    }

    /** تسجيل رسالة وسائط — تُنشئ صفّ chat_media وتربطه. */
    public function recordMedia(
        Contact $contact,
        string $providerMessageId,
        array $metadata,
        array $media,
        array $options = []
    ): ?Chat {
        return $this->record($contact, $providerMessageId, $metadata, $media, $options + [
            'status' => 'sent',
        ]);
    }

    /**
     * @param  array|null  $media  ['name','path','type','size','location']
     * @param  array  $options     ['user_id','status','temp_message_id','message_uuid','channel','broadcast','webhook']
     */
    private function record(
        Contact $contact,
        string $providerMessageId,
        array $metadata,
        ?array $media,
        array $options
    ): ?Chat {
        $channel = $options['channel'] ?? $contact->channel ?? Channel::DEFAULT;

        try {
            $chat = Chat::create([
                'organization_id' => $contact->organization_id,
                'wam_id' => $providerMessageId,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'user_id' => $options['user_id'] ?? null,
                'metadata' => JsonText::encode($metadata),
                'status' => $options['status'] ?? 'sent',
                'channel' => $channel,
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // معرّف مكرّر: الصدى (echo) سبق الردّ، أو أُعيدت المحاولة على
            // رسالة وصلت فعلاً. الصفّ موجود — نُرجعه بدل أن نُفشل الإرسال.
            if (($e->errorInfo[1] ?? null) === 1062) {
                Log::info('Outbound message already recorded, reusing row', [
                    'provider_message_id' => $providerMessageId,
                    'channel' => $channel,
                ]);

                return Chat::where('wam_id', $providerMessageId)->first();
            }

            throw $e;
        }

        $this->assignClientUuid($chat, $options['message_uuid'] ?? null);

        $chatLogId = ChatLog::insertGetId([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);

        if ($media) {
            $this->attachMedia($chat, $media);
        }

        $this->broadcast($chat, $chatLogId, $contact, $options);

        return $chat->fresh(['contact', 'media']);
    }

    /**
     * معرّف الرسالة الآتي من التطبيق. عمود uuid فريد، وتكراره يرمي خطأً
     * بعد أن تكون الرسالة وصلت العميل فعلاً — فنتخطّاه ونُبقي المُولَّد.
     */
    private function assignClientUuid(Chat $chat, ?string $messageUuid): void
    {
        if (!$messageUuid) {
            return;
        }

        $taken = Chat::where('uuid', $messageUuid)->where('id', '!=', $chat->id)->exists();

        if ($taken) {
            Log::warning('Duplicate msg_uuid from client, keeping generated uuid', [
                'msg_uuid' => $messageUuid,
                'chat_id' => $chat->id,
            ]);

            return;
        }

        $chat->uuid = $messageUuid;
        $chat->save();
    }

    private function attachMedia(Chat $chat, array $media): void
    {
        $row = ChatMedia::create([
            'name' => $media['name'] ?? 'N/A',
            'path' => $media['path'] ?? null,
            'location' => $media['location'] ?? 'local',
            'type' => $media['type'] ?? null,
            'size' => $media['size'] ?? null,
            'created_at' => now(),
        ]);

        Chat::where('id', $chat->id)->update(['media_id' => $row->id]);
        $chat->media_id = $row->id;
    }

    /**
     * البثّ بنفس الغلاف الذي تنتظره الواجهة اليوم:
     * [[ 'type' => 'chat', 'value' => relatedEntities, 'tempMessageId' => … ]]
     */
    private function broadcast(Chat $chat, int $chatLogId, Contact $contact, array $options): void
    {
        if (($options['broadcast'] ?? true) === false) {
            return;
        }

        $chatLog = ChatLog::where('id', $chatLogId)->whereNull('deleted_at')->first();

        if (!$chatLog) {
            return;
        }

        $payload = [[
            'type' => 'chat',
            'value' => $chatLog->relatedEntities,
            'tempMessageId' => $options['temp_message_id'] ?? null,
        ]];

        event(new NewChatEvent($payload, $contact->organization_id));
    }

    /**
     * إطلاق webhook 'message.sent' للعميل.
     *
     * مفصولة عن التسجيل عمداً: المُستدعي يملك الردّ الخام بالشكل القديم
     * الذي يتوقّعه عملاؤه، ونحن لا نعرفه هنا.
     */
    public function fireSentWebhook(int $organizationId, mixed $responsePayload): void
    {
        WebhookHelper::triggerWebhookEvent('message.sent', ['data' => $responsePayload], $organizationId);
    }
}
