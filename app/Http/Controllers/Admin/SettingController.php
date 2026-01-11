<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\Setting;
use App\Models\UserAdmin;
use App\Services\SettingService;
use Hash;
use Helper;
use Session;
use Validator;

class SettingController extends BaseController
{
    private $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    public function index()
    {
        $data['title'] = __('Settings');

        return Inertia::render('Admin/Setting/Main', $data);
    }

    public function update(StoreConfig $request)
    {
        if (env('APP_ENV') === 'demo') {
            // Return a response indicating that the function is not allowed in demo environment
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Updating settings is not allowed in demo.')
                ]
            );
        }

        $settings = $this->settingService->updateSettings($request);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('You\'ve updated your settings successfully')
            ]
        );
    }

    public function general(Request $request)
    {
        $data['config'] = Setting::get();
        return Inertia::render('Admin/Setting/General', $data);
    }

    public function email(Request $request){
        $data['config'] = Setting::get();
        return Inertia::render('Admin/Setting/Email', $data);
    }

    public function billing(Request $request){
        $data['config'] = Setting::get();
        return Inertia::render('Admin/Setting/Billing', $data);
    }

    public function broadcast_driver(Request $request){
        $data['config'] = Setting::get();
        return Inertia::render('Admin/Setting/Broadcast', $data);
    }

    public function seo(Request $request){
        return Inertia::render('Admin/Setting/Seo');
    }

    public function subscription(Request $request){
        $data['config'] = Setting::get();

        return Inertia::render('Admin/Setting/Subscription', $data);
    }

    public function timezone(Request $request){
        $data['timezones'] = config('formats.timezones');
        $data['date_formats'] = config('formats.date_formats');
        $data['time_formats'] = config('formats.time_formats');
        $data['currencies'] = config('currencies');
        $data['config'] = Setting::get();

        return Inertia::render('Admin/Setting/Timezone', $data);
    }

    public function storage(Request $request){
        $data['config'] = Setting::get();
        return Inertia::render('Admin/Setting/Storage', $data);
    }

    public function socials(Request $request){
        $data['config'] = Setting::get();
        return Inertia::render('Admin/Setting/Socials', $data);
    }
}