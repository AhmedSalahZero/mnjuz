<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\ProcessTicketAssignmentJob;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'run:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // اختبار incoming message
        ProcessIncomingMessageJob::dispatchSync(['id'=>'test'.time(),'from'=>'01025894984','timestamp'=>time(),'type'=>'text','text'=>['body'=>'test']], ['profile'=>['name'=>'Test']], 1);

        // اختبار ticket
        ProcessTicketAssignmentJob::dispatchSync(1, 1, true);

        // اختبار autoreply
        ProcessAutoReplyJob::dispatchSync(1, 1, false);
    }
}
