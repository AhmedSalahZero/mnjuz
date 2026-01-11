<?php

namespace Modules\FlowBuilder\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Addon;
use App\Models\Setting;
use Modules\FlowBuilder\Requests\StoreSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Str;

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
                'message' => __('Flow builder settings updated successfully!')
            ]
        );
    }
}