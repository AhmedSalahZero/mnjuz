<?php

namespace App\Console\Commands;

use App\Models\ChannelAccount;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\Contracts\RefreshesCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * تجديد توكنات القنوات قبل انتهائها.
 *
 * تيك توك وحدها تحتاجه اليوم: توكنها يعيش يوماً واحداً. وبلا هذه المهمّة
 * تنقطع القناة كل أربع وعشرين ساعة، ثم تعود عند أول رسالة يُجدّدها السائق
 * بنفسه — أي أن أول رسالة بعد الانقطاع تتأخّر بقدر رحلة تجديد، وقد تفشل
 * إن كان الخادم تحت ضغط.
 *
 * والأهمّ: refresh token يموت بعد ثلاثين يوماً من الخمول. الحساب الذي لا
 * تصله رسائل شهراً يفقد ربطه نهائياً ويحتاج تدخّل العميل. التجديد الدوري
 * يُبقيه حيّاً بلا أن يشعر أحد.
 */
class RefreshChannelTokens extends Command
{
    protected $signature = 'channels:refresh-tokens
                            {--channel= : قصر التجديد على قناة بعينها}';

    protected $description = 'Refresh channel access tokens that are close to expiring';

    /**
     * الهامش الذي نعدّ التوكن معه موشكاً على الانتهاء.
     *
     * ساعتان والمهمّة تعمل كل ساعة: يضمن تجديد كل توكن قبل انتهائه ولو
     * تعثّرت دورة كاملة.
     */
    private const LEEWAY_MINUTES = 120;

    public function handle(ChannelManager $channels): int
    {
        $query = ChannelAccount::query()
            ->where('status', ChannelAccount::STATUS_ACTIVE)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addMinutes(self::LEEWAY_MINUTES));

        if ($channel = $this->option('channel')) {
            $normalized = Channel::normalize($channel);

            if ($normalized === null) {
                $this->error("Unknown channel [{$channel}].");

                return self::FAILURE;
            }

            $query->where('channel', $normalized);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No tokens need refreshing.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            // refresh token ميّت: لا فائدة من المحاولة، ونُعلّم الحساب
            // ليظهر في الإعدادات بحاجة إعادة ربط.
            if ($account->refreshTokenExpired()) {
                $account->markReauthorizationRequired(
                    __('The :channel authorization expired. Reconnect the account.', [
                        'channel' => Channel::label($account->channel),
                    ])
                );

                $this->warn("Account {$account->id} ({$account->channel}) needs reauthorization.");
                $failed++;

                continue;
            }

            try {
                $driver = $channels->driver($account->channel);

                // القناة التي لا توكن لها ينتهي لا شأن لها بهذه المهمّة.
                if (!$driver instanceof RefreshesCredentials) {
                    continue;
                }

                // الهامش هو نفسه نافذة الاستعلام أعلاه: كل ما اختاره
                // الاستعلام يجب أن يراه السائق موشكاً كذلك، وإلّا اختير
                // الحساب ثم رُدّ «ما زال صالحاً» فلا يُجدَّد أبداً.
                // حسابٌ واحد فاشل لا يوقف البقيّة.
                $driver->refreshCredentials($account, self::LEEWAY_MINUTES) ? $refreshed++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;

                Log::error('Channel token refresh failed', [
                    'account_id' => $account->id,
                    'channel' => $account->channel,
                    'error' => $e->getMessage(),
                ]);

                $this->error("Account {$account->id}: {$e->getMessage()}");
            }
        }

        $this->info("Refreshed {$refreshed}, failed {$failed}.");

        return self::SUCCESS;
    }
}
