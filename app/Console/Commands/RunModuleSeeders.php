<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunModuleSeeders extends Command
{
    protected $signature = 'module:seed {module}';
    protected $description = 'Run seeders for a specific module';

    public function handle()
    {
        $module = $this->argument('module');
        $moduleSeederClass = "Modules\\$module\\Database\\Seeders\\{$module}Seeder";

        if (class_exists($moduleSeederClass)) {
            $this->call('db:seed', ['--class' => $moduleSeederClass]);
            $this->info("Seeders for module '$module' have been run.");
        } else {
            $this->error("Seeder class for module '$module' does not exist.");
        }
    }
}