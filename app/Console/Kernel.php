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

        $schedule->job(new ProcessCampaignMessagesJob(), 'campaign-messages')
            ->everyMinute()
            ->withoutOverlapping();

        /*$schedule->command('queue:work --queue=campaign-messages,campaign-logs --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping();*/
        
        // Monitor queue health
        $schedule->command('queue:restart')
            ->hourly()
            ->evenInMaintenanceMode();
        
        // Clean failed jobs table
        $schedule->command('queue:prune-failed --hours=24')
            ->daily()
            ->evenInMaintenanceMode();

        $schedule->command('queue:prune-batches --hours=48 --unfinished=72')
            ->daily();
        
        $schedule->command('model:prune', [
            '--model' => [CampaignLog::class],
            '--days' => 3,
        ])->daily();

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
