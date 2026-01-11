<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use dacoto\EnvSet\Facades\EnvSet;
use DB;

class SettingService
{
    /**
     * Update the settings based on the request data.
     *
     * @param array $request The data from the request.
     * @return bool Indicates whether the operation was successful.
     */
    public function updateSettings(Request $request)
    {
        $this->updateSettingEntries($request);
        $this->updateSocials($request);

        return true;
    }

    /**
     * Update individual setting entries based on the request data.
     *
     * @param array $request The data from the request.
     * @return void
     */
    private function updateSettingEntries(Request $request)
    {
        foreach ($request->all() as $key => $value) {
            if ($key !== 'socials') {
                if($key == 'logo' || $key == 'favicon'){
                    if ($value != null) {
                        if($request->hasFile($key)){
                            $filePath = $request->file($key)->store('public');
                        } else {
                            $filePath = $value;
                        }

                        try {
                            DB::table('settings')
                                ->updateOrInsert([
                                    'key' => $key
                                ], [
                                    'value' =>$filePath,
                                ]);
                        } catch (\Exception $e) {
                            Log::error($e->getMessage());
                        }
                    }
                } else if($key == 'app_environment') {
                    /*Artisan::call('config:clear');
                    Artisan::call('cache:clear');
                    Cache::flush();

                    EnvSet::setKey('APP_ENV', $value);
                    EnvSet::save();

                    try {
                        DB::table('settings')
                            ->updateOrInsert([
                                'key' => $key
                            ],[
                                'value' => $value,
                            ]);
                    } catch (\Exception $e) {
                        //dd($e->getMessage());
                        Log::error($e->getMessage());
                    }*/
                } else if($key == 'trial_limits') { 
                    $trial_limits = $request->all()['trial_limits'];

                    try {
                        DB::table('settings')
                            ->updateOrInsert([
                                'key' => 'trial_limits'
                            ],[
                                'value' => json_encode($trial_limits),
                            ]);
                    } catch (\Exception $e) {
                        //dd($e->getMessage());
                        Log::error($e->getMessage());
                    }
                } else if($key == 'aws'){
                    Artisan::call('config:clear');
                    Artisan::call('cache:clear');
                    Cache::flush();

                    if (isset($value['access_key'])) {
                        EnvSet::setKey('AWS_ACCESS_KEY_ID', $value['access_key']);
                    }
                    if (isset($value['secret_key'])) {
                        EnvSet::setKey('AWS_SECRET_ACCESS_KEY', $value['secret_key']);
                    }
                    if (isset($value['default_region'])) {
                        EnvSet::setKey('AWS_DEFAULT_REGION', $value['default_region']);
                    }
                    if (isset($value['bucket'])) {
                        EnvSet::setKey('AWS_BUCKET', $value['bucket']);
                    }
                    EnvSet::save();

                    $value = json_encode($value);

                    DB::table('settings')
                        ->updateOrInsert([
                            'key' => $key
                        ],[
                            'value' => $value,
                        ]);
                } else {
                    if($key == 'mail_config'){
                        if($value['driver'] == 'smtp'){
                            Artisan::call('config:clear');
                            Artisan::call('cache:clear');
                            Cache::flush();

                            EnvSet::setKey('MAIL_MAILER', $value['driver']);
                            EnvSet::setKey('MAIL_HOST', $value['host']);
                            EnvSet::setKey('MAIL_PORT', $value['port']);
                            EnvSet::setKey('MAIL_USERNAME', $value['username']);
                            EnvSet::setKey('MAIL_PASSWORD', $value['password']);
                            EnvSet::setKey('MAIL_FROM_ADDRESS', $value['from_address']);
                            EnvSet::setKey('MAIL_FROM_NAME', $value['from_name']);
                            EnvSet::save();
                        } else if($value['driver'] == 'ses'){
                            Artisan::call('config:clear');
                            Artisan::call('cache:clear');
                            Cache::flush();

                            EnvSet::setKey('MAIL_MAILER', $value['driver']);
                            EnvSet::setKey('MAIL_HOST', null);
                            EnvSet::setKey('MAIL_PORT', null);
                            EnvSet::setKey('MAIL_USERNAME', null);
                            EnvSet::setKey('MAIL_PASSWORD', null);
                            EnvSet::setKey('SES_KEY', $value['ses_key']);
                            EnvSet::setKey('SES_KEY_SECRET', $value['ses_secret']);
                            EnvSet::setKey('SES_REGION', $value['ses_region']);
                            EnvSet::setKey('MAIL_FROM_ADDRESS', $value['from_address']);
                            EnvSet::setKey('MAIL_FROM_NAME', $value['from_name']);
                            EnvSet::save();
                        } else if($value['driver'] == 'mailgun'){
                            Artisan::call('config:clear');
                            Artisan::call('cache:clear');
                            Cache::flush();

                            EnvSet::setKey('MAIL_MAILER', $value['driver']);
                            EnvSet::setKey('MAIL_HOST', null);
                            EnvSet::setKey('MAIL_PORT', null);
                            EnvSet::setKey('MAIL_USERNAME', null);
                            EnvSet::setKey('MAIL_PASSWORD', null);
                            EnvSet::setKey('MAILGUN_DOMAIN', $value['mg_domain']);
                            EnvSet::setKey('MAILGUN_SECRET', $value['mg_secret']);
                            EnvSet::setKey('MAIL_FROM_ADDRESS', $value['from_address']);
                            EnvSet::setKey('MAIL_FROM_NAME', $value['from_name']);
                            EnvSet::save();
                        }

                        $value = json_encode($value);

                        DB::table('settings')
                            ->updateOrInsert([
                                'key' => $key
                            ],[
                                'value' => $value,
                            ]);
                    } else if($key == 'is_tax_inclusive'){
                        try {
                            DB::table('settings')->updateOrInsert(['key' => $key],['value' => $value,]);
                        } catch (\Exception $e) {
                            Log::error($e->getMessage());
                        }

                        $stripe = PaymentGateway::where('name', 'Stripe')->first();

                        if($stripe->is_active == '1'){
                            (new StripeService)->updateProductPrices();
                        }
                    } else {
                        if($key != 'logo' && $key != 'favicon'){
                            try {
                                DB::table('settings')
                                    ->updateOrInsert([
                                        'key' => $key
                                    ],[
                                        'value' => $value,
                                    ]);
                            } catch (\Exception $e) {
                                //dd($e->getMessage());
                                Log::error($e->getMessage());
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Update the 'socials' setting based on the request data.
     *
     * @param array $request The data from the request.
     * @return void
     */
    private function updateSocials(Request $request)
    {
        if (isset($request->all()['socials'])) {
            $socials = $request->all()['socials'];
            try {
                DB::table('settings')
                    ->updateOrInsert([
                        'key' => 'socials'
                    ],[
                        'value' => json_encode($socials),
                    ]);
            } catch (\Exception $e) {
                //dd($e->getMessage());
                Log::error($e->getMessage());
            }
        }
    }

    /**
     * Retrieve all settings from the database.
     *
     * @return \Illuminate\Database\Eloquent\Collection The collection of settings.
     */
    public function getSettings()
    {
        return Setting::get();
    }
}