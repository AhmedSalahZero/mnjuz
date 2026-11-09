<?php

namespace App\Jobs;

use App\Services\AgentPresence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * تأكيد مغادرة الموظف بعد مهلة قصيرة من إشارتها.
 *
 * إعادة تحميل الصفحة تُرسل «غادرتُ» ثم تنبض بعد لحظة، فبثّ «غير متصل» فوراً
 * كان سيومض عند المدير. بعد المهلة: إن لم يبقَ له عميل حيّ يُبثّ، وإلا فلا.
 */
class ConfirmAgentOffline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public int $organizationId,
        public int $userId,
    ) {
    }

    public function handle(): void
    {
        AgentPresence::confirmOffline($this->organizationId, $this->userId);
    }
}
