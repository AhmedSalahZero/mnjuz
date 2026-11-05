<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsOnOrganizationProvider;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * موظفٌ صار متصلاً أو غير متصل — يصل مديري المنظمة لحظياً.
 *
 * صفحة الأداء كانت تسأل الخادم كل عشرين ثانية، والحالة نفسها تتأخّر خمس
 * دقائق، فيرى المدير موظفاً غادر «متصلاً» وآخر دخل «غير متصل». الحدث يُطلَق
 * عند تغيّر الحالة وحده (لا مع كل نبضة)، فكلفته على المزوّد ضئيلة.
 *
 * `at` بالمللي ثانية من ساعة الخادم: الحدثان قد يصلان بغير ترتيبهما (طابور،
 * شبكة)، والعميل يتجاهل ما هو أقدم ممّا طبّقه — وإلا قلب حدثٌ متأخّر حالةً
 * أحدث منه.
 */
class AgentPresenceChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use BroadcastsOnOrganizationProvider;

    public string $queue = 'high';

    public function __construct(
        public int $organizationId,
        public int $userId,
        public bool $online,
        public ?string $lastActivityAt,
        public int $at,
    ) {
    }

    public static function channelName(int $organizationId): string
    {
        return 'agents.presence.ch' . $organizationId;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->organizationId))];
    }

    /** اسم ثابت بلا مساحة أسماء: التطبيق يستمع إليه باسمه هذا. */
    public function broadcastAs(): string
    {
        return 'AgentPresenceChanged';
    }

    /** @return array{user_id: int, online: bool, last_activity_at: ?string, at: int} */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'online' => $this->online,
            'last_activity_at' => $this->lastActivityAt,
            'at' => $this->at,
        ];
    }
}
