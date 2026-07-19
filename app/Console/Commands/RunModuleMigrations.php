<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RunModuleMigrations extends Command
{
    protected $signature = 'module:migrate {module}';
    protected $description = 'Run migrations for a specific module';

    public function handle()
    {
        $module = $this->argument('module');
        $moduleMigrationPath = base_path("modules/$module/Database/Migrations");

        if (File::isDirectory($moduleMigrationPath)) {
            foreach (File::files($moduleMigrationPath) as $file) {
                require_once $file->getPathname();
            }

            $this->info("Migrations for module '$module' have been loaded and ready to be migrated.");
            
            $this->call('migrate', [
                '--path' => "modules/$module/Database/Migrations",
            ]);

            $this->info("Migrations for module '$module' have been successfully migrated.");
        } else {
            $this->error("Module '$module' does not exist or has no migrations.");
        }
    }
}