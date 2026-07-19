<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Jobs\ProcessAccountUpdateJob;
use App\Jobs\ProcessContactSyncJob;
use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\ProcessMessageEchoJob;
use App\Jobs\ProcessMessageStatusJob;
use App\Jobs\ProcessTemplateStatusJob;

use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Resolvers\PaymentPlatformResolver;

use App\Services\SubscriptionService;
use Carbon\Carbon;
use GuzzleHttp\Client;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Str;

class WebhookController extends BaseController
{
    protected $paymentPlatformResolver;

    public function __construct()
    {
        $this->paymentPlatformResolver = new PaymentPlatformResolver();

        Config::set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => Setting::where('key', 'pusher_app_key')->value('value'),
            'secret' => Setting::where('key', 'pusher_app_secret')->value('value'),
            'app_id' => Setting::where('key', 'pusher_app_id')->value('value'),
            'options' => [
                'cluster' => Setting::where('key', 'pusher_app_cluster')->value('value'),
            ],
        ]);
    }

    public function whatsappWebhook(Request $request)
    {
        //Log::info($request);
        $verifyToken = Setting::where('key', 'whatsapp_callback_token')->first()->value;

        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return Response::make($challenge, 200);
        } else {
            return Response::json(['error' => 'Forbidden'], 200);
        }
    }

    public function handle(Request $request, $identifier = null)
    {
        //Log::info('Webhook Handler: Start processing for identifier ' . $identifier);
        $organization = $this->getOrganizationByIdentifier($identifier);

        if (!$organization) {
            return $this->forbiddenResponse();
        }

        return $this->handleMethod($request, $organization);
    }

    protected function getOrganizationByIdentifier($identifier)
    {
        return Organization::where('identifier', $identifier)->first();
    }

    protected function handleMethod(Request $request, Organization $organization)
    {
        if ($request->isMethod('get')) {
            return $this->handleGetRequest($request, $organization);
        } elseif ($request->isMethod('post')) {
            $metadata = json_decode($organization->metadata);

            if (empty($metadata)) {
                return $this->forbiddenResponse();
            }

            /*$appSecret = $metadata->whatsapp->app_secret;
            $headerSignature = $request->header('X-Hub-Signature-256');
            $payload = $request->getContent();
            $calculatedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

            if (!$this->isValidSignature($calculatedSignature, $headerSignature)) {
                return $this->invalidSignatureResponse();
            }*/

            return $this->handlePostRequest($request, $organization);
        }

        return Response::json(['error' => 'Method Not Allowed'], 405);
    }

    protected function forbiddenResponse()
    {
        return Response::json(['error' => 'Forbidden'], 403);
    }

    protected function isValidSignature($calculatedSignature, $headerSignature)
    {
        return hash_equals($calculatedSignature, $headerSignature);
    }

    protected function invalidSignatureResponse()
    {
        return Response::json(['status' => 'error', 'message' => __('Invalid payload signature')], 400);
    }

    protected function handleGetRequest(Request $request, Organization $organization)
    {
        try {
            $verifyToken = $organization->identifier;

            $mode = $request->input('hub_mode');
            $token = $request->input('hub_verify_token');
            $challenge = $request->input('hub_challenge');

            if ($mode === 'subscribe' && $token === $verifyToken) {
                return Response::make($challenge, 200);
            } else {
                return Response::json(['error' => 'Forbidden'], 404);
            }
        } catch (\Exception $e) {
            Log::error("Error processing webhook: " . $e->getMessage());
            return Response::json(['error' => $e->getMessage()], 403);
        }
    }
   
	  protected function handleAjaxPostRequest(Request $request, Organization $organization)
    {
        $res = $request->entry[0]['changes'][0]??null;
		// if($organization->id == 134){
		// 	logger('organization id: ' . $organization->id);
		// 	logger('res: ' . json_encode($res));
		// }else{
		// 	logger('organizationdd id: ' . $organization->id);
		// }
		// logger('org id-'.$organization->id);
		if(is_null($res)){
			 return Response::json(['status' => 'success'], 200);
		}
		
  	 if($res['field'] === 'messages'){

        $messages = $res['value']['messages'] ?? null;
        $statuses = $res['value']['statuses'] ?? null;
        if($statuses) {
	
            ProcessMessageStatusJob::dispatch(
                $statuses,
                $organization->id
            )->onQueue('messageStatus');
        }
        
        if($messages && !$this->isLimitReached($organization->id)) {
            foreach($messages as $message){
                ProcessIncomingMessageJob::dispatch(
                    $message,
                    $res['value']['contacts'][0] ?? null,
                    $organization->id
                )->onQueue('high');
            }
        }
    } 
    else if($res['field'] === 'message_template_status_update'){
        ProcessTemplateStatusJob::dispatch(
            $res['value'],
            $organization->id
        )->onQueue('low');
    }
    // Coexistence: messages the business sent from the WhatsApp Business App
    else if($res['field'] === 'smb_message_echoes'){
        $echoes = $res['value']['message_echoes'] ?? null;
        if($echoes){
            foreach($echoes as $echo){
                ProcessMessageEchoJob::dispatch(
                    $echo,
                    $res['value'],
                    $organization->id
                )->onQueue('high');
            }
        }
    }
    // Coexistence: contacts synced from the WhatsApp Business App
    else if($res['field'] === 'smb_app_state_sync'){
        ProcessContactSyncJob::dispatch(
            $res['value']['state_sync'] ?? [],
            $organization->id
        )->onQueue('low');
    }
    // Coexistence: bulk chat history (Phase 1 — acknowledged but not imported yet)
    else if($res['field'] === 'history'){
        Log::info('Coexistence history webhook received (not processed in Phase 1)', [
            'organization_id' => $organization->id,
        ]);
    }
    else {
        ProcessAccountUpdateJob::dispatch(
            $res,
            $organization->id
        )->onQueue('low');
    }
    return Response::json(['status' => 'success'], 200);
    } 
	
    protected function handlePostRequest(Request $request, Organization $organization)
    {
	//	logger('handlePostRequest');
	//	logger(1);
		return $this->handleAjaxPostRequest($request, $organization);
    }

    private function downloadMedia($mediaInfo, Organization $organization)
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return $this->forbiddenResponse();
        }

        try {
            $client = new Client();

            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                    'Content-Type' => 'application/json',
                ],
            ];

            $response = $client->request('GET', $mediaInfo['url'], $requestOptions);

            $fileContent = $response->getBody();
            $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream'; // Default fallback
            $fileName = $this->generateFilename($fileContent, $mediaInfo['mime_type']);

            $storage = Setting::where('key', 'storage_system')->first()->value;

            if ($storage === 'local') {
                $location = 'local';
                $file = Storage::disk('local')->put('public/' . $fileName, $fileContent);
                $mediaFilePath = $file;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . 'public/' . $fileName;
            } elseif ($storage === 'aws') {
                $location = 'amazon';
                $filePath = 'uploads/media/received/'  . $organization->id . '/' . Str::random(40) . time();
                $file = Storage::disk('s3')->put($filePath, $fileContent, [
                    'ContentType' => $mimeType
                ]);
                $mediaUrl = Storage::disk('s3')->url($filePath);
            }

            $mediaData = [
                'media_url' => $mediaUrl,
                'location' => $location,
            ];
    
            return $mediaData;
        } catch (\Exception $e) {
            Log::error("Error processing webhook: " . $e->getMessage());
            return Response::json(['error' => 'Failed to download file'], 403);
        }
    }

    private function generateFilename($fileContent, $mimeType)
    {
        // Generate a unique filename based on the file content
        $hash = sha1($fileContent);

        // Get the file extension from the media type
        $extension = explode('/', $mimeType)[1];

        // Combine the hash, timestamp, and extension to create a unique filename
        $filename = "{$hash}_" . time() . ".{$extension}";

        return $filename;
    }

    private function getMedia($mediaId, Organization $organization)
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return $this->forbiddenResponse();
        }

        $client = new Client();

        try {
            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                    'Content-Type' => 'application/json',
                ],
            ];

            $response = $client->request('GET', "https://graph.facebook.com/v18.0/{$mediaId}", $requestOptions);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            return Response::json(['error' => 'Method Invalid'], 400);
        }
    }

    public function processWebhook(Request $request, $processor)
    {
        $paymentPlatform = $this->paymentPlatformResolver->resolveService($processor);
        session()->put('paymentPlatform', $processor);
        
        return $paymentPlatform->handleWebhook($request);
    }
	public function isLimitReached($organizationId)
	{
		$isLimitReached = SubscriptionService::isSubscriptionLimitReachedForInboundMessages($organizationId);
		return $isLimitReached;
	}
}
