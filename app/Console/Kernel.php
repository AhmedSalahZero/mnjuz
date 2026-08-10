<?php

namespace App\Console;

use App\Jobs\CreateCampaignLogsJob;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\CampaignLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new CreateCampaignLogsJob(), 'campaign-logs')
            ->everyMinute()
            ->withoutOverlapping();

        // الرصيد المشحون قبل انتهاء الاشتراك لا يستهلكه شيء: الخصم يجري لحظة
        // الدفع فقط وبشرط أن يكون منتهياً وقتها. كل ساعة كي لا يتوقّف حساب
        // رصيده كافٍ لأكثر من ساعة بعد انتهائه.
        $schedule->command('subscriptions:renew-from-credits')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $campaignSendInterval = max(1, (int) config('campaigns.send_interval_seconds', 5));
        $overlapSeconds = max(1, $campaignSendInterval - 1);

        $campaignMessageSchedule = $schedule->job(new ProcessCampaignMessagesJob(), 'campaign-messages');

        if ($campaignSendInterval === 5) {
            $campaignMessageSchedule->everyFiveSeconds();
        } elseif ($campaignSendInterval === 10) {
            $campaignMessageSchedule->everyTenSeconds();
        } elseif ($campaignSendInterval === 30) {
            $campaignMessageSchedule->everyThirtySeconds();
        } else {
            $campaignMessageSchedule->cron("*/{$campaignSendInterval} * * * * *");
        }

        $campaignMessageSchedule->withoutOverlapping($overlapSeconds);

        /*$schedule->command('queue:work --queue=campaign-messages,campaign-logs --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping();*/
        
        // Monitor queue health
        // $schedule->command('queue:restart')
        //     ->hourly()
        //     ->evenInMaintenanceMode();
        
        // Clean failed jobs table
        $schedule->command('queue:prune-failed --hours=24')
            ->daily()
            ->evenInMaintenanceMode();

        $schedule->command('queue:prune-batches --hours=48 --unfinished=72')
            ->daily();

        // سجلّ النشاط يُحفظ سبعة أيام ثم يُحذف — والصفحة تُعلن ذلك للمستخدم.
        $schedule->command('activity:prune')
            ->dailyAt('03:30')
            ->withoutOverlapping();
        
        // $schedule->command('model:prune', [
        //     '--model' => [CampaignLog::class],
        // ])->daily();
		
		// logger('inside schedule');

        // Monitor queue size
        //$schedule->command('monitor:queue-size')->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
