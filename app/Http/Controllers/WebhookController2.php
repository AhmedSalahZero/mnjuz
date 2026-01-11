
<?php

namespace App\Http\Controllers;


use App\Http\Controllers\Controller as BaseController;
use App\Jobs\ProcessAccountUpdateJob;
use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\ProcessMessageStatusJob;
use App\Jobs\ProcessTemplateStatusJob;
use App\Models\Organization;
use App\Models\Setting;
use App\Resolvers\PaymentPlatformResolver;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class WebhookController2 extends BaseController
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

    public function whatsappWebhook(Request $request){
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

    protected function handlePostRequest(Request $request, Organization $organization)
    {
		$start =  microtime(true);
        $res = $request->entry[0]['changes'][0]??null;
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
            )->onQueue('high');
        }
        
        if($messages && !$this->isLimitReached($organization->id)) {
            foreach($messages as $message){
                ProcessIncomingMessageJob::dispatch(
                    $message,
                    $res['value']['contacts'][0] ?? null,
                    $organization->id
                )->onQueue('messages');
            }
        }
    } 
    else if($res['field'] === 'message_template_status_update'){
        ProcessTemplateStatusJob::dispatch(
            $res['value'],
            $organization->id
        )->onQueue('low');
    }
    else {
        ProcessAccountUpdateJob::dispatch(
            $res,
            $organization->id
        )->onQueue('low');
    }
    return Response::json(['status' => 'success'], 200);
    }

   

   
    public function processWebhook(Request $request, $processor)
    {
        $paymentPlatform = $this->paymentPlatformResolver->resolveService($processor);
        session()->put('paymentPlatform', $processor);
        
        return $paymentPlatform->handleWebhook($request);
    }
	private function isLimitReached($organizationId)
{
    return Cache::remember(
        "org_limit_{$organizationId}",
        60, // دقيقة واحدة
        fn() => SubscriptionService::isSubscriptionLimitReachedForInboundMessages($organizationId)
    );
}


}
