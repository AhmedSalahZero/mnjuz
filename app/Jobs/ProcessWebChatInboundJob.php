<?php

namespace App\Jobs;

use App\Models\ChannelAccount;
use App\Models\WebChatVisitor;
use App\Services\Messaging\InboundMessageRecorder;
use App\Services\WebChat\WebChatInboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * رسالة من زائر المحادثة المباشرة إلى صندوق المحادثات.
 *
 * InboundMessageRecorder يفعل ما يفعله مسار واتساب خطوةً بخطوة: جهة
 * الاتصال، وصفّ المحادثة، والتذكرة والإسناد، والردّ الآلي، وساعات العمل،
 * والبثّ للموظّفين وإشعار الجوال، و webhook التاجر. ونحن نضيف ربط الزائر
 * بجهة اتصاله بعد أول رسالة.
 *
 * إعادة المحاولة آمنة: معرّف الرسالة مشتقّ من معرّف العميل، والمسجِّل يتخطّى
 * المكرّر بالذاكرة المؤقّتة ثم بالفهرس الفريد على wam_id.
 */
class ProcessWebChatInboundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [2, 10];

    public function __construct(
        protected int $channelAccountId,
        protected int $visitorId,
        protected array $payload,
    ) {
    }

    public function handle(InboundMessageRecorder $recorder): void
    {
        $account = ChannelAccount::find($this->channelAccountId);
        $visitor = WebChatVisitor::find($this->visitorId);

        if (!$account || !$visitor) {
            Log::warning('Web chat inbound: widget or visitor missing', [
                'account_id' => $this->channelAccountId,
                'visitor_id' => $this->visitorId,
            ]);

            return;
        }

        $chat = $recorder->record($account, WebChatInboundService::messageFromPayload($this->payload));

        if ($chat && (int) $visitor->contact_id !== (int) $chat->contact_id) {
            $visitor->forceFill(['contact_id' => $chat->contact_id])->save();
        }
    }
}
