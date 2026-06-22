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

    public $timeout = 30;
    public $tries = 1;
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

                $chat = Chat::where('wam_id', $chatWamId)
                    ->where('organization_id', $this->organizationId)
                    ->first();

                if ($chat) {
                    $chat->update(['status' => $statusValue]);

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

                    // Log::info("Message status updated", [
                    //     'chat_id' => $chat->id,
                    //     'status' => $statusValue,
                    //     'wam_id' => $chatWamId
                    // ]);
                } else {
                    // Log::warning("Chat not found for status update", [
                    //     'wam_id' => $chatWamId,
                    //     'organization_id' => $this->organizationId
                    // ]);
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
