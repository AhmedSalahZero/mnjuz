<?php

namespace App\Jobs;

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
			foreach($this->statuses as $status) {
				logger('inside first job');
                $chatWamId = $status['id'];
                $statusValue = $status['status'];

                // ✅ البحث عن الـ chat
                $chat = Chat::where('wam_id', $chatWamId)
                    ->where('organization_id', $this->organizationId)
                    ->first();

                if($chat) {
                    // ✅ تحديث الحالة
                    $chat->update(['status' => $statusValue]);

                    // ✅ حفظ Log
                    ChatStatusLog::create([
                        'chat_id' => $chat->id,
                        'metadata' => json_encode($status),
                        'created_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(),$this->organizationId)
                    ]);

                    Log::info("Message status updated", [
                        'chat_id' => $chat->id,
                        'status' => $statusValue,
                        'wam_id' => $chatWamId
                    ]);
                } else {
                    Log::warning("Chat not found for status update", [
                        'wam_id' => $chatWamId,
                        'organization_id' => $this->organizationId
                    ]);
                }
            }

            // ✅ Trigger webhook
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
