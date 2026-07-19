<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\AddonResource;
use App\Models\Addon;
use App\Models\Setting;
use App\Services\ModuleService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AddonController extends BaseController
{
    public function index(Request $request)
    {
        $rows = (new Addon)->listAll($request->query('search'));

        return Inertia::render('Admin/Addons/Index', [
            'title' => __('Addons'),
            'rows' => AddonResource::collection($rows), 
            'filters' => $request->all(),
            'config' => SettingService::redactForClient(Setting::get()),
            'whatsappCallbackToken' => Setting::where('key', 'whatsapp_callback_token')->value('value'),
        ]);
    }

    public function store(Request $request)
    {
        $settings = $request->settings;

        foreach ($settings as $key => $value) {
            // Secret values are no longer sent to the browser, so a blank submission
            // means "keep the stored value" instead of overwriting it.
            if (in_array($key, SettingService::SECRET_KEYS, true) && blank($value)) {
                continue;
            }

            DB::table('settings')->updateOrInsert(['key' => $key],['value' => $value]);
        }

        if(isset($request->is_active)){
            Addon::where('uuid', $request->uuid)->update(['is_active' => $request->is_active]);
        }

        return redirect('/admin/addons')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Addon updated successfully!')
            ]
        );
    }

    public function install(Request $request)
    {
        $ModuleService = new ModuleService;

        return $ModuleService->install($request);
    }

    public function update(Request $request)
    {
        $ModuleService = new ModuleService;

        return $ModuleService->update($request);
    }
}