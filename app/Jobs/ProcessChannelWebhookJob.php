<?php

namespace App\Jobs;

use App\Models\ChannelAccount;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\InboundMessageRecorder;
use App\Services\Messaging\InboundStatus;
use App\Services\Messaging\StatusRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * معالجة حمولة webhook خارج دورة الطلب.
 *
 * نُعيد بناء Request من الحمولة المحفوظة لأن عقد السائق يأخذ Request —
 * وهو ما يجعل السائق قابلاً للاختبار بطلب مُركَّب بلا شبكة ولا طابور.
 */
class ProcessChannelWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [2, 10];

    public function __construct(
        protected int $channelAccountId,
        protected array $payload,
        protected array $headers = [],
        protected ?string $rawBody = null,
    ) {
    }

    public function handle(
        ChannelManager $channels,
        InboundMessageRecorder $messages,
        StatusRecorder $statuses
    ): void {
        $account = ChannelAccount::find($this->channelAccountId);

        if (!$account) {
            return;
        }

        $driver = $channels->driver($account->channel);
        $events = $driver->parseWebhook($this->rebuildRequest(), $account);

        foreach ($events as $event) {
            try {
                if ($event instanceof InboundMessage) {
                    $messages->record($account, $event);

                    continue;
                }

                if ($event instanceof InboundStatus) {
                    $statuses->record($account, $event);
                }
            } catch (\Throwable $e) {
                // حدث واحد فاسد لا يُسقط بقيّة الدفعة: حمولة ميتا قد تحمل
                // عشرات الأحداث، وإفشال الوظيفة يُعيد معالجتها كلّها
                // فتتكرّر الرسائل الناجحة.
                Log::error('Channel webhook event failed', [
                    'channel' => $account->channel,
                    'account_id' => $account->id,
                    'event' => $event::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function rebuildRequest(): Request
    {
        $request = Request::create(
            '/webhook/channel/' . $this->channelAccountId,
            'POST',
            $this->payload,
            [],
            [],
            [],
            $this->rawBody
        );

        foreach ($this->headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
