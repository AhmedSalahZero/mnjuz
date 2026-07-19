<?php

namespace App\Rules;

use App\Models\Setting;
use Illuminate\Contracts\Validation\Rule;
use GuzzleHttp\Client;

class Recaptcha implements Rule
{
    public function passes($attribute, $value)
    {
        $secret = Setting::where('key', 'recaptcha_secret_key')->first()->value;
        $client = new Client();

        $verifyResponse = $client->post('https://www.google.com/recaptcha/api/siteverify', [
            'form_params' => [
                'secret' => $secret,
                'response' => $value,
            ],
        ]);

        $body = json_decode((string) $verifyResponse->getBody());

        return $body->success;
    }

    public function message()
    {
        return __('The reCAPTCHA verification failed.');
    }
}
