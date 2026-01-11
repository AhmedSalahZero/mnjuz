<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Services\AutoReplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessAutoReplyJob implements ShouldQueue
{
   use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 1;
    public $backoff = [5, 15];

    protected $chatId;
    protected $organizationId;
    protected $isNewContact;

    public function __construct($chatId, $organizationId, $isNewContact = false)
    {
        $this->chatId = $chatId;
        $this->organizationId = $organizationId;
        $this->isNewContact = $isNewContact;
    }

    public function handle()
    {
        try {
		
            // ✅ التحقق من subscription limit
            $isLimitReached = Cache::remember(
                "message_limit_{$this->organizationId}",
                60,
                fn() => \App\Services\SubscriptionService::isSubscriptionFeatureLimitReached(
                    $this->organizationId, 
                    'message_limit'
                )
            );

            if($isLimitReached) {
                Log::info("AutoReply skipped - message limit reached", [
                    'organization_id' => $this->organizationId,
                    'chat_id' => $this->chatId
                ]);
                return;
            }

            // ✅ جلب الـ chat
            $chat = Chat::with('contact')->find($this->chatId);
            
            if(!$chat) {
                Log::warning("AutoReply skipped - chat not found", [
                    'chat_id' => $this->chatId
                ]);
                return;
            }

            // ✅ تشغيل AutoReply Service
            $autoReplyService = new AutoReplyService();
            $autoReplyService->checkAutoReply($chat, $this->isNewContact);

            Log::info("AutoReply processed successfully", [
                'organization_id' => $this->organizationId,
                'chat_id' => $this->chatId,
                'contact_id' => $chat->contact_id
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessAutoReplyJob failed', [
                'organization_id' => $this->organizationId,
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ✅ للـ retry
            throw $e;
        }
    }

    /**
     * ✅ Handle job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error('ProcessAutoReplyJob permanently failed', [
            'organization_id' => $this->organizationId,
            'chat_id' => $this->chatId,
            'error' => $exception->getMessage()
        ]);

        // يمكن إرسال إشعار للمسؤولين هنا
    }
}
