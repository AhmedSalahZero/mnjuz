<?php

namespace Modules\FlowBuilder\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Exception;

class SetupService
{
    public function index()
    {
        try {
            // Run migrations and capture output
            $exitCode = Artisan::call('module:migrate', [
                'module' => 'FlowBuilder',  // Specify the module name as an argument
            ]);

            // Capture command output
            $output = Artisan::output();

            // Log success or failure
            if ($exitCode === 0) {
                // Log::info('FlowBuilder module migration ran successfully.', ['output' => $output]);
            } else {
                Log::error('FlowBuilder module migration encountered an issue.', ['output' => $output, 'exitCode' => $exitCode]);
            }

        } catch (Exception $e) {
            Log::error('Error running FlowBuilder module migration.', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}