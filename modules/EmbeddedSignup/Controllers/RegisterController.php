<?php

namespace Modules\EmbeddedSignup\Controllers;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class RegisterController extends BaseController
{
    private $apiVersion;

    public function __construct(){
        $this->apiVersion = config('graph.api_version');
    }

    public function handleSignup(Request $request){
        $organizationId = session()->get('current_organization');

        $accessTokenResponse = $this->getAccessToken($request->token);

        if(!$accessTokenResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $accessTokenResponse->data->error->message
                ]
            );
        }
        
        // Access token
        $accessToken = $accessTokenResponse->data->access_token;

        //Get DebugToken
        $debugTokenResponse = $this->debugToken($accessToken);

        if(!$debugTokenResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $debugTokenResponse->data->error->message
                ]
            );
        }

        //Get Phone Number Id
        $phoneNumberResponse = $this->getPhoneNumberId($accessToken, $debugTokenResponse->data->waba_id);
        
        if(!$phoneNumberResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $phoneNumberResponse->data->error->message
                ]
            );
        }

        //Get Phone Number Status
        $phoneNumberStatusResponse = $this->getPhoneNumberStatus($accessToken, $phoneNumberResponse->data->id); 

        if(!$phoneNumberStatusResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $phoneNumberStatusResponse->data->error->message
                ]
            );
        }

        //Get Account Review Status
        $accountReviewStatusResponse = $this->getAccountReviewStatus($accessToken, $debugTokenResponse->data->waba_id);

        if(!$accountReviewStatusResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $accountReviewStatusResponse->data->error->message
                ]
            );
        }

        //Register Number
        $registerNumber = $this->registerNumber($accessToken, $phoneNumberResponse->data->id);

        if(!$registerNumber->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $registerNumber->data->error->message
                ]
            );
        }

        //Get business profile
        $businessProfileResponse = $this->getBusinessProfile($accessToken, $phoneNumberResponse->data->id);  
        
        if(!$businessProfileResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $businessProfileResponse->data->error->message
                ]
            );
        }

        $organizationConfig = Organization::where('id', $organizationId)->first();
        $callbackUrl = URL::to('/') . '/webhook/whatsapp/' . $organizationConfig->identifier;
        $token = $organizationConfig->identifier;

        //Subscribe to Waba
        $subscribeToWabaResponse = $this->subscribeToWaba($accessToken, $debugTokenResponse->data->waba_id);

        if(!$subscribeToWabaResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $subscribeToWabaResponse->data->error->message
                ]
            );
        } 

        //Set Callback Url
        $overridecallbackResponse = $this->overrideWabaCallbackUrl($accessToken, $debugTokenResponse->data->waba_id, $callbackUrl, $token);
                    
        if(!$overridecallbackResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $overridecallbackResponse->data->error->message
                ]
            );
        }

        //Set Callback Url
        /*$overridecallbackResponse = $this->overridePhoneCallbackUrl($accessToken, $phoneNumberResponse->data->id, $callbackUrl, $token);
                    
        if(!$overridecallbackResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $overridecallbackResponse->data->error->message
                ]
            );
        }*/

        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['whatsapp']['is_embedded_signup'] = 1;
        $metadataArray['whatsapp']['access_token'] = $accessToken;
        $metadataArray['whatsapp']['app_id'] = $debugTokenResponse->data->app_id;
        $metadataArray['whatsapp']['waba_id'] = $debugTokenResponse->data->waba_id;
        $metadataArray['whatsapp']['phone_number_id'] = $phoneNumberResponse->data->id;
        $metadataArray['whatsapp']['display_phone_number'] = $phoneNumberResponse->data->display_phone_number;
        $metadataArray['whatsapp']['verified_name'] = $phoneNumberResponse->data->verified_name;
        $metadataArray['whatsapp']['quality_rating'] = $phoneNumberResponse->data->quality_rating;
        $metadataArray['whatsapp']['name_status'] = $phoneNumberResponse->data->name_status;
        $metadataArray['whatsapp']['messaging_limit_tier'] = $phoneNumberResponse->data->messaging_limit_tier ?? NULL;
        $metadataArray['whatsapp']['max_daily_conversation_per_phone'] = NULL;
        $metadataArray['whatsapp']['max_phone_numbers_per_business'] = NULL;
        $metadataArray['whatsapp']['number_status'] = $phoneNumberStatusResponse->data->status;
        $metadataArray['whatsapp']['code_verification_status'] = isset($phoneNumberStatusResponse->data->code_verification_status) ? $phoneNumberStatusResponse->data->code_verification_status : NULL;
        $metadataArray['whatsapp']['business_verification'] = '';
        $metadataArray['whatsapp']['account_review_status'] = $accountReviewStatusResponse->data->account_review_status;
        $metadataArray['whatsapp']['business_profile']['about'] = $businessProfileResponse->data->about ?? NULL;
        $metadataArray['whatsapp']['business_profile']['address'] = $businessProfileResponse->data->address ?? NULL;
        $metadataArray['whatsapp']['business_profile']['description'] = $businessProfileResponse->data->description ?? NULL;
        $metadataArray['whatsapp']['business_profile']['industry'] = $businessProfileResponse->data->vertical ?? NULL;
        $metadataArray['whatsapp']['business_profile']['email'] = $businessProfileResponse->data->email ?? NULL;

        $updatedMetadataJson = json_encode($metadataArray);

        $organizationConfig->metadata = $updatedMetadataJson;
        $organizationConfig->save();

        //Sync templates
        $this->syncTemplates($accessToken, $debugTokenResponse->data->waba_id);

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('You\'ve successfully connected your account to whatsapp!')
            ]
        );
    }

    public function getAccessToken($token){
        $settings = Setting::whereIn('key', ['whatsapp_client_id', 'whatsapp_client_secret'])->pluck('value', 'key');
        $clientId = $settings->get('whatsapp_client_id', null);
        $clientSecret = $settings->get('whatsapp_client_secret', null);

        $responseObject = new \stdClass();

        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $token,
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['error']['code'];
                $responseObject->data->error->message = $response['error']['message'];
            } else {
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    public function debugToken($token){
        $accessToken = Setting::where('key', 'whatsapp_access_token')->value('value');
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/debug_token", [
            'input_token' => $token
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {
                $appId = null;
                $firstWabaId = null;
    
                $appId = $response['data']['app_id'] ?? null;
    
                // Check if the response data is structured as expected
                if (isset($response['data']['granular_scopes'])) {
                    foreach ($response['data']['granular_scopes'] as $scope) {
                        if ($scope['scope'] === 'whatsapp_business_management' && isset($scope['target_ids'][0])) {
                            $firstWabaId = $scope['target_ids'][0];
                            break;
                        }
                    }
                }
    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data->app_id = $appId;
                $responseObject->data->waba_id = $firstWabaId;
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    public function getPhoneNumberId($accessToken, $wabaId){
        $responseObject = new \stdClass();

        $fields = 'display_phone_number,certificate,name_status,new_certificate,new_name_status,verified_name,quality_rating,messaging_limit_tier';

        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/phone_numbers", [
            'fields' => $fields,
            'access_token' => $accessToken,
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    public function getPhoneNumberStatus($accessToken, $phoneNumberId){
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}", [
            'fields' => 'status',
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    public function getAccountReviewStatus($accessToken, $wabaId){
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}", [
            'fields' => 'account_review_status',
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    public function getBusinessProfile($accessToken, $phoneNumberId){
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}/whatsapp_business_profile", [
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
        ]);

        if ($response->successful()) {
            $response = $response->json();
            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
            }
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    function subscribeToWaba($accessToken, $wabaId)
    {
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps");

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    function getWabaSubscriptions($accessToken, $wabaId)
    {
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps");

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    public function overrideWabaCallbackUrl($accessToken, $wabaId, $callbackUrl, $verifyToken)
    {
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps", [
            'override_callback_uri' => $callbackUrl,
            'verify_token' => $verifyToken
        ]);

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    function overridePhoneCallbackUrl($accessToken, $phoneId, $callbackUrl, $verifyToken)
    {
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/{$phoneId}", [
            'webhook_configuration' => [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken
            ]
        ]);

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    function unSubscribeToWaba($accessToken, $wabaId)
    {
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->delete("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps");

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    function registerNumber($accessToken, $phoneNumberID)
    {
        $responseObject = new \stdClass();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken
        ])->post("https://graph.facebook.com/{$this->apiVersion}/".$phoneNumberID."/register", [
            'messaging_product' => "whatsapp",
            'pin' => "123456",
        ]);

        if ($response->successful()) {
            $response = $response->json();
            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } else {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $response->body();
            Log::info($response->body());
        }

        return $responseObject;
    }

    function syncTemplates($accessToken, $wabaId)
    {
        $responseObject = new \stdClass();

        try {
            do {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken
                ])->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/message_templates")->throw()->json();

                foreach($response['data'] as $templateData){
                    $template = Template::where('organization_id', session()->get('current_organization'))
                        ->where('meta_id', $templateData['id'])->first();

                    if($template){
                        $template->metadata = json_encode($templateData);
                        $template->status = $templateData['status'];
                        $template->updated_at = now();
                        $template->deleted_at = NULL;
                        $template->save();
                    } else {
                        $template = new Template();
                        $template->organization_id = session()->get('current_organization');
                        $template->meta_id = $templateData['id'];
                        $template->name = $templateData['name'];
                        $template->category = $templateData['category'];
                        $template->language = $templateData['language'];
                        $template->metadata = json_encode($templateData);
                        $template->status = $templateData['status'];
                        $template->created_by = auth()->user()->id;
                        $template->created_at = now();
                        $template->updated_at = now();
                        $template->save();
                    }
                };

                if(isset($response['paging']) && isset($response['paging']['next'])) {
                    $url = $response['paging']['next'];
                } else {
                    $url = null; // Break the loop if no next page URL is available
                }
            } while($url);

            $responseObject->success = true;
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }
}