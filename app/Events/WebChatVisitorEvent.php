<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsOnOrganizationProvider;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ما يصل زائر المحادثة المباشرة لحظياً: رسالة، أو «حدِّث السجلّ».
 *
 * القناة خاصّة بالزائر وحده، ويوقّعها endpoint الزائر بسرّ مزوّد منشأته
 * (WidgetController::broadcastAuth) — لا BroadcastAuthController، فذاك
 * يرفض كل من لا جلسة له.
 *
 * الحمولة صفّ الـ presenter كما يراه الزائر لا غير. وإن جاوزت حدّ المزوّد
 * أُرسل «refresh» فيجلب الـ widget السجلّ بنفسه: Pusher يكتب كل حرف عربي
 * ستّة بايتات (\uXXXX)، فالرسالة العربية الطويلة تبلغ الحدّ قبل أوانها.
 */
class WebChatVisitorEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use BroadcastsOnOrganizationProvider;

    /** دون حدّ Pusher (10KB) بهامش للغلاف. يُقاس كما يُرسَل: json_encode بلا خيارات. */
    public const MAX_BYTES = 9000;

    public string $queue = 'high';

    /**
     * @param  array{kind: string, message?: array, seq?: int|null}  $payload
     */
    public function __construct(
        public int $organizationId,
        public string $visitorUuid,
        public array $payload,
    ) {
    }

    /** رسالة جديدة أو محدَّثة — أو «refresh» إن ثقلت على المزوّد. */
    public static function message(int $organizationId, string $visitorUuid, array $row): self
    {
        $payload = ['kind' => 'message', 'message' => $row];

        if (strlen((string) json_encode($payload)) > self::MAX_BYTES) {
            $payload = ['kind' => 'refresh', 'seq' => $row['seq'] ?? null];
        }

        return new self($organizationId, $visitorUuid, $payload);
    }

    /** تغيّر لا يُختصر في صفّ واحد (حذف رسالة أو مسح المحادثة). */
    public static function refresh(int $organizationId, string $visitorUuid): self
    {
        return new self($organizationId, $visitorUuid, ['kind' => 'refresh', 'seq' => null]);
    }

    /** بلا «private-»: Laravel يضيفها، والـ widget يشترك بالاسم الكامل. */
    public static function channelName(int $organizationId, string $visitorUuid): string
    {
        return 'webchat.ch' . $organizationId . '.' . $visitorUuid;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->organizationId, $this->visitorUuid))];
    }

    public function broadcastAs(): string
    {
        return 'webchat.event';
    }

    /** صريحة: وإلا بثّ Laravel كل خاصية عامّة (المنشأة والمعرّف) للزائر. */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
