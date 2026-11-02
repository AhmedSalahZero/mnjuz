<?php

namespace App\Events;

use App\Services\Chat\ChatChannelRecipients;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * مساعد الذكاء الاصطناعي لمحادثة ما أُشعل أو أُطفئ.
 *
 * مفتاح المساعد في ترويسة المحادثة يُقرأ من الصفحة عند تحميلها، لكن الخادم نفسه
 * يغيّر هذه الحالة دون أن يضغط أحد: كلمة بدء أو إيقاف يكتبها العميل، أو
 * التفعيل التلقائي عند أول رسالة. وبلا إشعار تبقى الشاشة المفتوحة تعرض الحالة
 * القديمة — موظفة ترى «مفعّل» بعد أن أوقفه العميل بنفسه، فتنتظر رداً لن يأتي.
 *
 * يُبثّ على قنوات {@see NewChatEvent} نفسها وللمستلمين أنفسهم
 * ({@see ChatChannelRecipients}): من يرى المحادثة يعرف حالة مساعدها، ومن لا يراها لا.
 * والحمولة ثلاثة حقول فقط، فلا حاجة لتقليصها لحدّ Pusher.
 */
class ContactAiAssistanceUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $queue = 'high';

    public function __construct(
        public int $organizationId,
        public int $contactId,
        public string $contactUuid,
        public bool $enabled,
    ) {
    }

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        try {
            $userIds = app(ChatChannelRecipients::class)
                ->resolveUserIds($this->organizationId, $this->contactId);

            return array_map(
                fn (int $userId) => new PresenceChannel('chats.ch' . $this->organizationId . '.' . $userId),
                $userIds
            );
        } catch (\Throwable $e) {
            // تعذّر تحديد المستلمين لا يجوز أن يُسقط معالجة رسالة العميل التي ولّدت الحدث.
            Log::error('ContactAiAssistanceUpdated broadcastOn failed', [
                'organization_id' => $this->organizationId,
                'contact_id'      => $this->contactId,
                'exception'       => $e,
            ]);

            return [];
        }
    }

    /**
     * @return array{contact_id: int, contact_uuid: string, ai_assistance_enabled: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'contact_id' => $this->contactId,
            'contact_uuid' => $this->contactUuid,
            'ai_assistance_enabled' => $this->enabled,
        ];
    }
}
