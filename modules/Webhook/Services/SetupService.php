<?php

namespace Modules\Webhook\Services;

use Illuminate\Support\Facades\Artisan;

class SetupService
{
    public function index(){
        //Run migrations
        $migrateOutput = Artisan::call('module:migrate', [
            'module' => 'Webhook',  // Specify the module name as an argument
        ]);
    }
}