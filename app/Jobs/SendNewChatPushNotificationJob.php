<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Chat\ChatChannelRecipients;
use App\Services\Chat\ChatNotificationContentBuilder;
use App\Services\Firebase\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronously deliver the WhatsApp-style mobile push notification for a
 * newly broadcast chat. Decoupled from {@see \App\Events\NewChatEvent} so
 * that:
 *
 *  - Push delivery cannot be re-fired multiple times when Pusher retries
 *    a broadcast (each job is dispatched exactly once per chat).
 *  - A failure inside FCM does not abort the realtime broadcast.
 *  - Recipients and the payload can be resolved on the queue worker
 *    instead of in the request thread.
 */
class SendNewChatPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * `$chatValueSnapshot` is the minimal chat-value array used purely to
     * derive the title and body of the notification (contact name/phone,
     * metadata, media). It is NOT broadcast over Pusher — that is the
     * realtime event's responsibility.
     *
     * @param  array<string, mixed>  $chatValueSnapshot
     */
    public function __construct(
        public int $organizationId,
        public ?int $contactId,
        public array $chatValueSnapshot
    ) {
        $this->onQueue('high');
    }

    public function handle(
        ChatChannelRecipients $recipients,
        ChatNotificationContentBuilder $content
    ): void {
        try {
            $userIds = $recipients->resolveUserIds($this->organizationId, $this->contactId);
            if (empty($userIds)) {
                return;
            }

            $users = User::query()
                ->whereIn('id', $userIds)
                ->whereNotNull('current_mobile_organization_id')
                ->where('current_mobile_organization_id', $this->organizationId)
                ->with('deviceTokens')
                ->get();

            if ($users->isEmpty()) {
                return;
            }

            $title = $content->buildTitle($this->chatValueSnapshot);
            $bodyEn = $content->buildBody($this->chatValueSnapshot, 'en');
            $bodyAr = $content->buildBody($this->chatValueSnapshot, 'ar');

            $additionalData = [
                'contactFullName' => (string) ($this->chatValueSnapshot['contact_full_name'] ?? ''),
                'phone'           => (string) ($this->chatValueSnapshot['phone'] ?? ''),
                'chatId'          => (string) ($this->contactId ?? ''),
                'organizationId'  => (string) $this->organizationId,
                'createdAt'       => now()->format('Y-m-d H:i:s'),
            ];

            // Single FCM client serves every recipient. Auth is lazy so a
            // bad/missing service account does not throw during construction.
            $fcm = new FcmNotification();
            if (! $fcm->isConfigured()) {
                Log::warning('SendNewChatPushNotificationJob skipped: FCM not configured', [
                    'organization_id' => $this->organizationId,
                    'contact_id' => $this->contactId,
                    'error' => $fcm->getLastAuthError(),
                ]);

                return;
            }

            foreach ($users as $user) {
                $tokens = $user->getDeviceTokens();
                if (empty($tokens)) {
                    continue;
                }

                $userAdditionalData = $additionalData + [
                    'userId' => (string) $user->id,
                ];

                foreach ($tokens as $token) {
                    try {
                        $fcm->send(
                            $title,
                            $this->resolveBodyForUser($user, $bodyEn, $bodyAr),
                            $token,
                            $userAdditionalData
                        );
                    } catch (\Throwable $e) {
                        Log::warning('SendNewChatPushNotificationJob: FCM delivery failed', [
                            'user_id'         => $user->id,
                            'organization_id' => $this->organizationId,
                            'error'           => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('SendNewChatPushNotificationJob failed', [
                'organization_id' => $this->organizationId,
                'contact_id'      => $this->contactId,
                'exception'       => $e,
            ]);
        }
    }

    /**
     * Pick the localised body that matches the user's preferred language.
     * Falls back to the API default ({@see getApiLang()}) when the user has
     * no explicit language set.
     */
    private function resolveBodyForUser(User $user, string $bodyEn, string $bodyAr): string
    {
        $locale = strtolower((string) ($user->language ?? ''));
        if ($locale === 'ar') {
            return $bodyAr;
        }
        if ($locale === 'en') {
            return $bodyEn;
        }
        return getApiLang() === 'ar' ? $bodyAr : $bodyEn;
    }
}
