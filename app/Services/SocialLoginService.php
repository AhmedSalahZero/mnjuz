<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DB;
use Socialite;

class SocialLoginService
{
    public static function makeGoogleDriver()
    {
        $metadata = Setting::where('key', 'google_login')->first()->value;
        $metadata = json_decode($metadata);
        $app_id = $metadata !== false ? isset($metadata->client_id) ?  $metadata->client_id : '' : '';
        $app_secret = $metadata !== false ? isset($metadata->client_secret) ? $metadata->client_secret : '' : '';

        $config = [
            'client_id' => $app_id,
            'client_secret' => $app_secret,
            'redirect' => url('google/callback'),
        ];

        return Socialite::buildProvider('\Laravel\Socialite\Two\GoogleProvider', $config);
    }

    public static function makeFacebookDriver()
    {
        $metadata = Setting::where('key', 'facebook_login')->first()->value;
        $metadata = json_decode($metadata);
        $app_id = $metadata !== false ? isset($metadata->client_id) ?  $metadata->client_id : '' : '';
        $app_secret = $metadata !== false ? isset($metadata->client_secret) ? $metadata->client_secret : '' : '';

        $config = [
            'client_id' => $app_id,
            'client_secret' => $app_secret,
            'redirect' => url('facebook/callback'),
        ];

        return Socialite::buildProvider('\Laravel\Socialite\Two\FacebookProvider', $config);
    }
}