<?php

namespace App\Jobs;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\Support\MediaStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * تنزيل ملف وارد من قناة غير واتساب وربطه بالرسالة.
 *
 * نظيرة ProcessMediaDownloadJob لواتساب، بنفس السلوك: نفس المحاولات،
 * ونفس البثّ بعد النجاح، ونفس failed() التي تُظهر الرسالة بلا ملف بدل
 * أن تختفي صامتة.
 *
 * روابط ميتا المؤقّتة تنتهي بسرعة، ولذلك المحاولات قليلة ومتقاربة: إعادة
 * المحاولة بعد ساعة على رابط منتهٍ لا تُفيد.
 */
class ProcessChannelMediaDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;
    public $tries = 3;
    public $backoff = [5, 20];

    public function __construct(
        protected int $chatId,
        protected int $channelAccountId,
        protected array $media,
        protected bool $isNewContact = false,
        protected ?string $contactUuid = null,
    ) {
    }

    public function handle(ChannelManager $channels): void
    {
        $chat = Chat::find($this->chatId);

        if (!$chat || $chat->media_id) {
            return;
        }

        $account = ChannelAccount::find($this->channelAccountId);

        if (!$account) {
            Log::warning('ProcessChannelMediaDownloadJob: account not found', [
                'chat_id' => $this->chatId,
                'channel_account_id' => $this->channelAccountId,
            ]);

            return;
        }

        $driver = $channels->driver($account->channel);
        $downloaded = $driver->downloadMedia($account, $this->toInboundMedia());

        if (!$downloaded) {
            throw new \RuntimeException(
                'Unable to download channel media for chat ' . $this->chatId
            );
        }

        $stored = MediaStorage::store($downloaded, (int) $account->organization_id);

        if (!$stored) {
            throw new \RuntimeException('Unable to store channel media for chat ' . $this->chatId);
        }

        $chatMedia = ChatMedia::create([
            'name' => $this->media['file_name'] ?? 'N/A',
            'path' => $stored['path'],
            'type' => $downloaded->mimeType,
            'size' => $downloaded->size(),
            'location' => $stored['location'],
            'created_at' => now(),
        ]);

        $chat->update(['media_id' => $chatMedia->id]);

        $this->broadcast($chat, (int) $account->organization_id);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessChannelMediaDownloadJob permanently failed', [
            'chat_id' => $this->chatId,
            'channel_account_id' => $this->channelAccountId,
            'media_type' => $this->media['type'] ?? null,
            'error' => $exception->getMessage(),
        ]);

        $chat = Chat::find($this->chatId);

        if (!$chat || $chat->media_id) {
            return;
        }

        try {
            $this->broadcast($chat, (int) $chat->organization_id);
        } catch (\Throwable $e) {
            Log::error('ProcessChannelMediaDownloadJob: failed() broadcast error', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function broadcast(Chat $chat, int $organizationId): void
    {
        $chatLog = ChatLog::where('entity_id', $chat->id)
            ->where('entity_type', 'chat')
            ->first();

        event(new NewChatEvent(
            [[
                'is_new_contact' => $this->isNewContact,
                'contact_uuid' => $this->contactUuid,
                'type' => 'chat',
                'value' => $chatLog->relatedEntities ?? $chat,
            ]],
            $organizationId,
            $this->isNewContact,
            false,
            true
        ));

        WebhookHelper::triggerWebhookEvent(
            'message.received',
            ['data' => $this->media['raw_message'] ?? []],
            $organizationId
        );
    }

    private function toInboundMedia(): InboundMedia
    {
        return new InboundMedia(
            type: $this->media['type'] ?? 'document',
            providerId: $this->media['provider_id'] ?? null,
            url: $this->media['url'] ?? null,
            mimeType: $this->media['mime_type'] ?? null,
            fileName: $this->media['file_name'] ?? null,
            size: $this->media['size'] ?? null,
            caption: $this->media['caption'] ?? null,
            isVoice: (bool) ($this->media['is_voice'] ?? false),
            extra: $this->media['extra'] ?? [],
        );
    }
}
