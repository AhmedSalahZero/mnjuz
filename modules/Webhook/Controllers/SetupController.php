<?php

namespace Modules\Webhook\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Addon;
use Illuminate\Http\Request;
use Modules\Webhook\Requests\StoreSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SetupController extends BaseController
{
    public function store(Request $request){
        $settings = $request->settings;

        foreach ($settings as $key => $value) {
            DB::table('settings')->updateOrInsert([
                'key' => $key
            ],[
                'value' => $value,
            ]);
        }

        if(isset($request->is_active)){
            Addon::where('uuid', $request->uuid)->update(['is_active' => $request->is_active]);
        }

        return redirect('/admin/addons')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Webhook settings updated successfully!')
            ]
        );
    }
}