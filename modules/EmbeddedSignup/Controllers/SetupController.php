<?php

namespace Modules\EmbeddedSignup\Controllers;

use App\Http\Controllers\Controller as BaseController;

use App\Models\Addon;
use App\Models\Setting;
use Modules\EmbeddedSignup\Requests\StoreEmbeddedSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Str;

class SetupController extends BaseController
{
    public function index(Request $request){
        //
    }

    public function store(StoreEmbeddedSettings $request){
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

        //Add token
        $wToken = Setting::where('key', 'whatsapp_callback_token')->first();
        if(!$wToken){
            Setting::create([
                'key' => 'whatsapp_callback_token',
                'value' => now()->format('YmdHis') . Str::random(4)
            ]);
        }

        return redirect('/admin/addons')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Whatsapp settings updated successfully!')
            ]
        );
    }

    public function update(Request $request){
        $metadata = [
            "name" => "EmbeddedSignup",
            "input_fields" => [
                [
                    "element" => "input",
                    "type" => "text",
                    "name" => "whatsapp_app_id",
                    "label" => "App ID",
                    "class" => "col-span-2"
                ],
                [
                    "element" => "input",
                    "type" => "text",
                    "name" => "whatsapp_client_id",
                    "label" => "Client ID",
                    "class" => "col-span-1"
                ],
                [
                    "element" => "input",
                    "type" => "text",
                    "name" => "whatsapp_config_id",
                    "label" => "Config ID",
                    "class" => "col-span-1"
                ],
                [
                    "element" => "input",
                    "type" => "password",
                    "name" => "whatsapp_access_token",
                    "label" => "Access Token",
                    "class" => "col-span-2"
                ],
                [
                    "element" => "input",
                    "type" => "password",
                    "name" => "whatsapp_client_secret",
                    "label" => "Client Secret",
                    "class" => "col-span-2"
                ]
            ]
        ];

        // Assuming YourModel is the model you want to update
        Addon::where('uuid', $request->uuid)->update(['metadata' => json_encode($metadata)]);
    }
}