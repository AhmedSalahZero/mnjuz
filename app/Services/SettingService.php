<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Services\StripeService;
use dacoto\EnvSet\Facades\EnvSet;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SettingService
{
    /**
     * Top-level setting keys whose values must never be sent to the browser.
     * These are redacted on read and preserved on blank save.
     */
    public const SECRET_KEYS = [
        'recaptcha_secret_key',
        'pusher_app_secret',
        'pusher_app_id',
        'whatsapp_callback_token',
        'razorpay_secret_key',
        'razorpay_webhook_secret',
        'pabbly_api_key',
        'pabbly_secret_key',
        'whatsapp_client_secret',
        'whatsapp_access_token',
        'aws_access_key',
        'aws_secret_key',
    ];

    /**
     * JSON setting blobs that contain secret sub-fields. Only the listed
     * sub-fields are redacted/preserved; the rest of the JSON is kept.
     */
    public const SECRET_JSON_FIELDS = [
        'aws' => ['access_key', 'secret_key'],
        'mail_config' => ['password', 'mg_secret', 'ses_secret', 'ses_key'],
        'facebook_login' => ['client_secret'],
        'google_login' => ['client_secret'],
    ];

    /**
     * Return a settings collection as a client-safe array of {key, value}.
     * Secret values are blanked and a companion "<key>_is_set" flag is added
     * so the UI can indicate whether a value is already configured.
     *
     * @param  iterable  $settings  Collection/array of Setting rows.
     * @return array<int, array{key: string, value: mixed}>
     */
    public static function redactForClient($settings): array
    {
        $result = [];

        foreach ($settings as $setting) {
            $key = is_array($setting) ? ($setting['key'] ?? null) : ($setting->key ?? null);
            $value = is_array($setting) ? ($setting['value'] ?? null) : ($setting->value ?? null);

            if ($key === null) {
                continue;
            }

            if (in_array($key, self::SECRET_KEYS, true)) {
                $result[] = ['key' => $key, 'value' => ''];
                $result[] = ['key' => $key . '_is_set', 'value' => filled($value)];
                continue;
            }

            if (array_key_exists($key, self::SECRET_JSON_FIELDS)) {
                $decoded = json_decode((string) $value, true);
                if (is_array($decoded)) {
                    foreach (self::SECRET_JSON_FIELDS[$key] as $field) {
                        if (array_key_exists($field, $decoded)) {
                            $result[] = ['key' => $key . '_' . $field . '_is_set', 'value' => filled($decoded[$field])];
                            $decoded[$field] = '';
                        }
                    }
                    $result[] = ['key' => $key, 'value' => json_encode($decoded)];
                    continue;
                }
            }

            $result[] = ['key' => $key, 'value' => $value];
        }

        return $result;
    }

    /**
     * Merge blank secret sub-fields of a JSON setting blob with the values
     * currently stored in the database, so a blank submission keeps the
     * existing secret instead of erasing it.
     *
     * @param  string  $key
     * @param  array   $incoming
     * @return array
     */
    public static function preserveSecretJsonFields(string $key, array $incoming): array
    {
        if (!array_key_exists($key, self::SECRET_JSON_FIELDS)) {
            return $incoming;
        }

        $existingRow = Setting::where('key', $key)->first();
        $existing = $existingRow ? json_decode((string) $existingRow->value, true) : null;
        if (!is_array($existing)) {
            return $incoming;
        }

        foreach (self::SECRET_JSON_FIELDS[$key] as $field) {
            if (blank($incoming[$field] ?? null) && filled($existing[$field] ?? null)) {
                $incoming[$field] = $existing[$field];
            }
        }

        return $incoming;
    }

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
                // Never overwrite a stored secret with a blank submission: the UI
                // no longer receives secret values, so an empty field means "unchanged".
                if (in_array($key, self::SECRET_KEYS, true) && blank($value)) {
                    continue;
                }

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
                        Log::error($e->getMessage());
                    }
                } else if($key == 'aws'){
                    $value = self::preserveSecretJsonFields('aws', is_array($value) ? $value : []);

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
                } else if($key == 'facebook_login' || $key == 'google_login'){
                    $value = self::preserveSecretJsonFields($key, is_array($value) ? $value : []);

                    try {
                        DB::table('settings')
                            ->updateOrInsert([
                                'key' => $key
                            ],[
                                'value' => json_encode($value),
                            ]);
                    } catch (\Exception $e) {
                        Log::error($e->getMessage());
                    }
                } else {
                    if($key == 'mail_config'){
                        $value = self::preserveSecretJsonFields('mail_config', is_array($value) ? $value : []);

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
