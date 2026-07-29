<?php

namespace App\Jobs;

use App\Events\NewChatEvent;
use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatStatusLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMessageStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Keep below the queue retry_after (360s) so the job is never re-queued mid-run.
    public $timeout = 60;
    // One retry with backoff helps ride out short table locks without stacking status jobs.
    public $tries = 2;
    public $backoff = [5, 10, 20];

    protected $statuses;
    protected $organizationId;

    public function __construct($statuses, $organizationId)
    {
        $this->statuses = $statuses;
        $this->organizationId = $organizationId;
    }

    public function handle()
    {
        try {
            $now = DateTimeHelper::convertToOrganizationTimezone(now(), null);

            foreach ($this->statuses as $status) {
                $chatWamId = $status['id'];
                $statusValue = $status['status'];

                // Avoid loading the full row (metadata can be large) unless failed status
                // needs type/media_id/metadata for transcode retry.
                $columns = $statusValue === 'failed'
                    ? ['id', 'type', 'media_id', 'metadata']
                    : ['id'];

                $chat = Chat::where('wam_id', $chatWamId)
                    ->where('organization_id', $this->organizationId)
                    ->first($columns);

                if ($chat) {
                    Chat::whereKey($chat->id)->update(['status' => $statusValue]);

                    ChatStatusLog::create([
                        'chat_id' => $chat->id,
                        'metadata' => json_encode($status),
                        'created_at' => $now,
                    ]);

                    $chatLog = $chat->chatLog;
                    if ($chatLog) {
                        $chatArray = [[
                            'type' => 'chat',
                            'value' => $chatLog->relatedEntities,
                            'tempMessageId' => $status['id'],
                        ]];
                        event(new NewChatEvent($chatArray, $this->organizationId, false, true));
                    }

                    if (
                        $statusValue === 'failed'
                        && RetryMediaWithTranscodeJob::shouldRetryForChat($chat, $status['errors'] ?? [])
                    ) {
                        RetryMediaWithTranscodeJob::dispatch($chat->id, $this->organizationId)
                            ->onQueue('high');
                    }
                }
            }

            WebhookHelper::triggerWebhookEvent(
                'message.status.update',
                ['data' => $this->statuses],
                $this->organizationId
            );

        } catch (\Exception $e) {
            Log::error('ProcessMessageStatusJob failed', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}
