<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncTemplateData extends Command
{
    protected $signature = 'sync:data';
    protected $description = 'Sync template data from WhatsApp API to the database';

    public function handle()
    {
        // Add your synchronization logic here
        // Fetch data from the WhatsApp API and update the database
    }
}
