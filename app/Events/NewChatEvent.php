<?php

namespace App\Events;

use App\Jobs\SendNewChatPushNotificationJob;
use App\Services\Chat\ChatBroadcastPayloadBuilder;
use App\Services\Chat\ChatChannelRecipients;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Realtime broadcast for "a chat row changed" — fired on inbound messages,
 * outbound replies, and status updates. Subscribers are presence channels
 * `chats.ch{organizationId}.{userId}` listened to by the web dashboard
 * (see {@see resources/js/echo.js}).
 *
 * This class is intentionally thin: every responsibility that requires
 * database access or business rules has been delegated to a service so
 * the event remains cheap to dispatch and the queue payload stays small.
 *
 *   - {@see ChatBroadcastPayloadBuilder}: builds the minimal Pusher
 *     payload, including 10KB-fit shrinking.
 *   - {@see ChatChannelRecipients}     : computes which user ids should
 *     receive the broadcast (and the FCM push).
 *   - {@see SendNewChatPushNotificationJob}: delivers WhatsApp-style
 *     mobile push notifications independently of the broadcast.
 */
class NewChatEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $queue = 'high';

    public int $organizationId;
    public bool $isNewContact;
    public bool $statusChanged;

    /**
     * Pre-built minimal chat array — already trimmed to the keys we care
     * about — kept on the event instance so the queued payload stays
     * small and the broadcast worker does not need to refetch the chat
     * row from the database.
     *
     * @var array<int, array<string, mixed>>|array<string, mixed>
     */
    public array $chat;

    /**
     * Cached contact id pulled out of the chat for use by
     * {@see broadcastOn()} without re-walking the chat array.
     */
    public ?int $contactId;

    public function __construct(
        $chat,
        int $organizationId,
        bool $isNewContact = false,
        bool $statusChanged = false,
        bool $sendToFirestore = false
    ) {
        $this->organizationId = $organizationId;
        $this->isNewContact = $isNewContact;
        $this->statusChanged = $statusChanged;

        // Build the minimal payload once at dispatch time. Doing it here
        // (rather than lazily in broadcastWith) keeps the queued event
        // payload bounded — without this the entire raw Chat model graph
        // would be serialised into the queue.
        $this->chat = $this->payloadBuilder()
            ->buildWrappedChat($chat, $organizationId, $isNewContact);

        $this->contactId = $this->extractContactId($this->chat);

        if ($sendToFirestore) {
            $this->dispatchPushNotification();
        }
    }

    /**
     * Channels the event broadcasts on. Pure resolution — no side effects,
     * no notifications. The push notification has already been dispatched
     * as its own job by the constructor when needed.
     *
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        try {
            $userIds = app(ChatChannelRecipients::class)
                ->resolveUserIds($this->organizationId, $this->contactId);

            return array_map(
                fn (int $userId) => new PresenceChannel(
                    'chats.ch' . $this->organizationId . '.' . $userId
                ),
                $userIds
            );
        } catch (\Throwable $e) {
            Log::error('NewChatEvent broadcastOn failed', [
                'organization_id' => $this->organizationId,
                'contact_id'      => $this->contactId,
                'exception'       => $e,
            ]);

            return [];
        }
    }

    /**
     * Data sent to subscribed clients. Built lazily so heavy work happens
     * on the broadcast queue worker, not in the request thread.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = ['chat' => $this->chat];
        if ($this->statusChanged) {
            $payload['statusChanged'] = true;
        }

        return $this->payloadBuilder()->fitToPusherLimit($payload, [
            'organization_id' => $this->organizationId,
            'contact_id'      => $this->contactId,
        ]);
    }

    /**
     * Backwards-compatible passthrough for legacy callers (helpers, API
     * controllers) that still use `(new NewChatEvent(...))->minimalChatValue($value)`.
     *
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    public function minimalChatValue($value): array
    {
        return $this->payloadBuilder()
            ->buildMinimalValue($value, $this->organizationId, $this->isNewContact);
    }

    /**
     * Pull the contact id out of either wrapper shape produced by the
     * dispatchers (`[[type=chat, value=...]]` or `[type=chat, value=...]`).
     *
     * @param  array<int|string, mixed>  $chat
     */
    private function extractContactId(array $chat): ?int
    {
        $value = $chat[0]['value'] ?? $chat['value'] ?? null;
        if (!is_array($value)) {
            return null;
        }

        $contactId = $value['contact_id'] ?? null;
        return $contactId ? (int) $contactId : null;
    }

    /**
     * Snapshot the inner chat-value used purely for building push title /
     * body, then enqueue the FCM job. The job runs independently of the
     * realtime broadcast and uses {@see ChatChannelRecipients} to apply
     * the same visibility rules.
     */
    private function dispatchPushNotification(): void
    {
        $value = $this->chat[0]['value'] ?? $this->chat['value'] ?? null;
        if (!is_array($value)) {
            return;
        }

        SendNewChatPushNotificationJob::dispatch(
            $this->organizationId,
            $this->contactId,
            $value
        );
    }

    private function payloadBuilder(): ChatBroadcastPayloadBuilder
    {
        return app(ChatBroadcastPayloadBuilder::class);
    }
}
