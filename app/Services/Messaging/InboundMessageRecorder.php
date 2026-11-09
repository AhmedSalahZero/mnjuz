<?php

namespace App\Services\Messaging;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessChannelMediaDownloadJob;
use App\Jobs\ProcessTicketAssignmentJob;
use App\Jobs\SendTextMessageJob;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Services\ContactPlaceholderService;
use App\Services\SubscriptionService;
use App\Services\WorkingHoursService;
use App\Support\JsonText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * استقبال رسالة من أي قناة غير واتساب وتحويلها إلى نفس الصفوف والأحداث
 * التي يُنتجها ProcessIncomingMessageJob لواتساب.
 *
 * لماذا لا نُعيد استعمال ProcessIncomingMessageJob نفسه؟ لأنه مبنيّ على
 * حمولة واتساب الخام: يقرأ message['from'] ويحوّله رقم هاتف، ويبني
 * metadata من ChatMetadataHelper. توسيعه ليقبل الشكلين يجعله شرطياً في كل
 * سطر. هذه نسخة تعمل على InboundMessage المطبَّعة، والسلوك الجانبي
 * (التذاكر، الردّ الآلي، ساعات العمل، البثّ، webhook) مطابق خطوةً بخطوة.
 */
class InboundMessageRecorder
{
    public function __construct(
        private readonly ContactResolver $contacts,
    ) {
    }

    /**
     * @return Chat|null الصفّ المُنشأ، أو null إن كانت الرسالة مكرّرة
     */
    public function record(ChannelAccount $account, InboundMessage $message): ?Chat
    {
        $organizationId = (int) $account->organization_id;

        // رسالة صادرة منّا عادت صداها: نُسجّلها إن لم تكن مسجّلة (أرسلها
        // الموظّف من تطبيق المنصّة نفسها لا من عندنا)، ولا نُشغّل عليها
        // الردّ الآلي ولا التذاكر.
        if ($message->isEcho) {
            return $this->recordEcho($account, $message);
        }

        [$contact, $isNewContact] = $this->contacts->resolve($account, $message);

        if (!$contact) {
            return null;
        }

        $chat = $this->createChat($account, $contact, $message);

        if (!$chat) {
            return null;
        }

        $this->createChatLog($contact->id, $chat->id);

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $organizationId, true);

        if ($message->hasMedia()) {
            ProcessChannelMediaDownloadJob::dispatch(
                $chat->id,
                $account->id,
                $this->serializeMedia($message),
                $isNewContact,
                $contact->uuid
            )->onQueue('media');
        }

        $limitReached = SubscriptionService::isSubscriptionFeatureLimitReached($organizationId, 'message_limit');

        if (!$limitReached) {
            $this->maybeSendWorkingHoursNotice($organizationId, $contact);
        }

        if (!$limitReached
            && $this->shouldCheckAutoReply($message)
            && !WorkingHoursService::isOutsideConfiguredHours($organizationId)
        ) {
            ProcessAutoReplyJob::dispatch($chat->id, $organizationId, $isNewContact)->onQueue('autoreplies');
        }

        // الوسائط تبثّ بعد التنزيل (وإلّا ظهرت فقاعة بلا ملف)، والنصّ فوراً.
        if (!$message->hasMedia()) {
            event(new NewChatEvent(
                $this->eventPayload($chat, $isNewContact, $contact->uuid),
                $organizationId,
                $isNewContact,
                false,
                true
            ));

            WebhookHelper::triggerWebhookEvent(
                'message.received',
                ['data' => $message->raw],
                $organizationId
            );
        }

        return $chat;
    }

    /**
     * صدى رسالة صادرة. إن كانت مسجّلة عندنا (أرسلناها نحن) نتركها،
     * وإلّا نُنشئ صفّاً صادراً بلا user_id — أرسلها الموظّف من المنصّة.
     */
    private function recordEcho(ChannelAccount $account, InboundMessage $message): ?Chat
    {
        if (Chat::where('wam_id', $message->providerMessageId)->exists()) {
            return null;
        }

        [$contact] = $this->contacts->resolve($account, $message);

        if (!$contact) {
            return null;
        }

        try {
            $chat = Chat::create([
                'organization_id' => $account->organization_id,
                'wam_id' => $message->providerMessageId,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'user_id' => null,
                'metadata' => JsonText::encode($message->metadata),
                'status' => 'sent',
                'channel' => $account->channel,
                'is_read' => 1,
                'created_at' => $message->sentAt ?? now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return null;
            }

            throw $e;
        }

        $this->createChatLog($contact->id, $chat->id);

        event(new NewChatEvent(
            $this->eventPayload($chat, false, $contact->uuid),
            (int) $account->organization_id,
            false,
            false,
            false
        ));

        return $chat;
    }

    private function createChat(ChannelAccount $account, Contact $contact, InboundMessage $message): ?Chat
    {
        $providerId = $message->providerMessageId;
        $cacheKey = 'wam_processed_' . $providerId;

        if (Cache::has($cacheKey)) {
            Log::info('Inbound message skipped: duplicate (cache)', ['provider_message_id' => $providerId]);

            return null;
        }

        try {
            $chat = Chat::create([
                'organization_id' => $account->organization_id,
                'wam_id' => $providerId,
                'contact_id' => $contact->id,
                'type' => 'inbound',
                'metadata' => JsonText::encode($message->metadata),
                'status' => 'delivered',
                'channel' => $account->channel,
                'is_read' => 0,
                'created_at' => $message->sentAt ?? now(),
            ]);

            Cache::put($cacheKey, true, 3600);

            return $chat;
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                Log::info('Inbound message skipped: duplicate (db)', ['provider_message_id' => $providerId]);
                Cache::put($cacheKey, true, 3600);

                return null;
            }

            throw $e;
        }
    }

    private function createChatLog(int $contactId, int $chatId): void
    {
        ChatLog::insertGetId([
            'contact_id' => $contactId,
            'entity_type' => 'chat',
            'entity_id' => $chatId,
            'created_at' => now(),
        ]);
    }

    /** الأنواع التي يُشغّل عليها الردّ الآلي — نفس قائمة واتساب. */
    private function shouldCheckAutoReply(InboundMessage $message): bool
    {
        return in_array($message->type(), ['text', 'button', 'audio', 'interactive'], true);
    }

    private function maybeSendWorkingHoursNotice(int $organizationId, Contact $contact): void
    {
        if (!WorkingHoursService::isOutsideConfiguredHours($organizationId)) {
            return;
        }

        $cacheKey = 'working_hours_away_' . $organizationId . '_' . $contact->id;

        if (Cache::has($cacheKey)) {
            return;
        }

        $body = WorkingHoursService::resolveAwayNoticeBody($organizationId);
        $body = trim(ContactPlaceholderService::replace($organizationId, $contact->uuid, $body));

        if ($body === '') {
            return;
        }

        SendTextMessageJob::dispatch($organizationId, $contact->uuid, $body)->onQueue('high');
        Cache::put($cacheKey, 1, now()->addHour());
    }

    private function eventPayload(Chat $chat, bool $isNewContact, string $contactUuid): array
    {
        $chatLog = ChatLog::where('entity_id', $chat->id)
            ->where('entity_type', 'chat')
            ->first();

        return [[
            'is_new_contact' => $isNewContact,
            'contact_uuid' => $contactUuid,
            'type' => 'chat',
            'value' => $chatLog->relatedEntities ?? $chat,
        ]];
    }

    /** InboundMedia إلى مصفوفة قابلة للتسلسل داخل طابور المهام. */
    private function serializeMedia(InboundMessage $message): array
    {
        $media = $message->media;

        return [
            'type' => $media->type,
            'provider_id' => $media->providerId,
            'url' => $media->url,
            'mime_type' => $media->mimeType,
            'file_name' => $media->fileName,
            'size' => $media->size,
            'caption' => $media->caption,
            'is_voice' => $media->isVoice,
            'extra' => $media->extra,
            'raw_message' => $message->raw,
        ];
    }
}
