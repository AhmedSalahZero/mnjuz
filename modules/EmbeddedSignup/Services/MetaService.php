<?php

namespace Modules\EmbeddedSignup\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class MetaService
{
    public function overrideWabaCallbackUrl($organizationId)
    {
        $config = Organization::findOrFail($organizationId)->metadata;
        $config = $config ? json_decode($config, true) : [];
        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $organizationConfig = Organization::where('id', $organizationId)->first();
        $callbackUrl = URL::to('/') . '/webhook/whatsapp/' . $organizationConfig->identifier;
        $verifyToken = $organizationConfig->identifier;

        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->post("https://graph.facebook.com/v20.0/{$wabaId}/subscribed_apps", [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken
            ])->throw()->json();

            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }
}