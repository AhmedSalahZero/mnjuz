<?php

namespace App\Jobs;

use App\Events\NewChatEvent;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\VideoTranscodeService;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RetryMediaWithTranscodeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RETRYABLE_ERROR_CODES = [131053, 131052];

    public $timeout = 600;
    public $tries = 1;
    public $uniqueFor = 300;

    public function __construct(
        public int $chatId,
        public int $organizationId,
    ) {}

    public function uniqueId(): string
    {
        return 'retry-media-transcode-' . $this->chatId;
    }

    public static function shouldRetryForChat(Chat $chat, array $errors): bool
    {
        if ($chat->type !== 'outbound' || !$chat->media_id) {
            return false;
        }

        $metadata = json_decode($chat->metadata ?? '{}', true);
        if (!is_array($metadata) || ($metadata['type'] ?? '') !== 'video') {
            return false;
        }

        if ((int) ($metadata['transcode_retry_count'] ?? 0) >= 1) {
            return false;
        }

        if (($metadata['transcode_retry_status'] ?? '') === 'retrying') {
            return false;
        }

        foreach ($errors as $error) {
            $code = (int) ($error['code'] ?? 0);
            if (in_array($code, self::RETRYABLE_ERROR_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    public function handle(VideoTranscodeService $transcoder): void
    {
        $chat = Chat::with(['media', 'contact'])->find($this->chatId);
        if (!$chat || !$chat->media || !$chat->contact) {
            return;
        }

        $metadata = json_decode($chat->metadata ?? '{}', true);
        if (!is_array($metadata) || ($metadata['type'] ?? '') !== 'video') {
            return;
        }

        if ((int) ($metadata['transcode_retry_count'] ?? 0) >= 1) {
            return;
        }

        if (!$transcoder->isAvailable()) {
            Log::warning('RetryMediaWithTranscodeJob: ffmpeg unavailable', [
                'chat_id' => $this->chatId,
            ]);

            return;
        }

        $metadata['transcode_retry_count'] = 1;
        $metadata['transcode_retry_status'] = 'retrying';
        $chat->update(['metadata' => json_encode($metadata)]);
        $this->broadcastChatUpdate($chat->fresh(['media', 'contact', 'logs']));

        $source = $this->resolveSourceFile($chat);
        if ($source === null) {
            $this->markRetryFailed($chat, $metadata);
            return;
        }

        $transcodedPath = null;

        try {
            // Meta 131053 needs re-encode; remux (-c copy) keeps the same rejected container.
            $transcodedPath = $transcoder->transcodeForWhatsapp($source['path'], true);
            if ($transcodedPath === null) {
                $this->markRetryFailed($chat, $metadata);
                return;
            }

            $stored = $this->storeTranscodedFile($transcodedPath, $chat->media->name);
            if ($stored === null) {
                $this->markRetryFailed($chat, $metadata);
                return;
            }

            $organization = Organization::find($this->organizationId);
            if (!$organization) {
                $this->markRetryFailed($chat, $metadata);
                return;
            }

            $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
            $whatsappService = new WhatsappService(
                $config['whatsapp']['access_token'] ?? null,
                config('graph.api_version'),
                $config['whatsapp']['app_id'] ?? null,
                $config['whatsapp']['phone_number_id'] ?? null,
                $config['whatsapp']['waba_id'] ?? null,
                $this->organizationId
            );

            $caption = $metadata['video']['caption'] ?? null;
            $response = $whatsappService->sendMedia(
                $chat->contact->uuid,
                'video',
                $stored['file_name'],
                $stored['media_file_path'],
                $stored['media_url'],
                $stored['location'],
                $caption,
                null,
                $chat->user_id,
                null,
                null,
                $chat->id
            );

            if (($response->success ?? false) !== true) {
                $this->markRetryFailed($chat, $metadata);
            }
        } finally {
            if ($source['is_temp'] && is_file($source['path'])) {
                @unlink($source['path']);
            }
            if ($transcodedPath !== null && is_file($transcodedPath)) {
                @unlink($transcodedPath);
            }
        }
    }

    private function markRetryFailed(Chat $chat, array $metadata): void
    {
        $metadata['transcode_retry_status'] = 'failed';
        $chat->update([
            'metadata' => json_encode($metadata),
            'status' => 'failed',
        ]);
        $this->broadcastChatUpdate($chat->fresh(['media', 'contact', 'logs']));
    }

    private function broadcastChatUpdate(Chat $chat): void
    {
        $chatLog = ChatLog::where('entity_id', $chat->id)
            ->where('entity_type', 'chat')
            ->whereNull('deleted_at')
            ->first();

        if (!$chatLog) {
            return;
        }

        $chat->loadMissing(['media', 'contact', 'logs']);

        event(new NewChatEvent(
            [[
                'type' => 'chat',
                'value' => $chatLog->relatedEntities,
            ]],
            $this->organizationId,
            false,
            true
        ));
    }

    /**
     * @return array{path: string, is_temp: bool}|null
     */
    private function resolveSourceFile(Chat $chat): ?array
    {
        $media = $chat->media;
        if (!$media) {
            return null;
        }

        if ($media->location === 'local') {
            $storageRelative = $this->localStorageRelativePath($media->path);
            if ($storageRelative !== null) {
                $absolute = storage_path('app/' . $storageRelative);
                if (is_readable($absolute)) {
                    return ['path' => $absolute, 'is_temp' => false];
                }
            }
        }

        $url = $media->path;
        if (!is_string($url) || !str_starts_with($url, 'http')) {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'wa_src_') . '.mp4';
        $content = @file_get_contents($url);
        if ($content === false) {
            @unlink($tempPath);
            return null;
        }

        file_put_contents($tempPath, $content);

        return ['path' => $tempPath, 'is_temp' => true];
    }

    private function localStorageRelativePath(string $path): ?string
    {
        if (preg_match('#/media/(.+)$#', $path, $matches)) {
            return $matches[1];
        }

        $trimmed = ltrim($path, '/');
        if ($trimmed !== '' && Storage::disk('local')->exists($trimmed)) {
            return $trimmed;
        }

        return null;
    }

    /**
     * @return array{file_name: string, media_file_path: string, media_url: string, location: string}|null
     */
    private function storeTranscodedFile(string $transcodedPath, string $originalName): ?array
    {
        $fileContent = @file_get_contents($transcodedPath);
        if ($fileContent === false) {
            return null;
        }

        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeFileName = sanitize_filename_for_storage($baseName . '.mp4');
        $storage = Setting::where('key', 'storage_system')->first()?->value;

        if ($storage === 'local') {
            $relativePath = 'public/' . uniqid() . '_' . $safeFileName;
            Storage::disk('local')->put($relativePath, $fileContent);

            return [
                'file_name' => $safeFileName,
                'media_file_path' => $relativePath,
                'media_url' => rtrim(config('app.url'), '/') . '/media/' . ltrim($relativePath, '/'),
                'location' => 'local',
            ];
        }

        if ($storage === 'aws') {
            $s3Path = 'uploads/media/sent/' . $this->organizationId . '/' . uniqid() . '_' . $safeFileName;
            Storage::disk('s3')->put($s3Path, $fileContent, ['ContentType' => 'video/mp4']);
            $mediaUrl = Storage::disk('s3')->url($s3Path);

            return [
                'file_name' => $safeFileName,
                'media_file_path' => $mediaUrl,
                'media_url' => $mediaUrl,
                'location' => 'amazon',
            ];
        }

        return null;
    }
}
