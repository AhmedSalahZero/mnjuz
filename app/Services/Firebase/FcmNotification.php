<?php 
namespace App\Services\Firebase;
use Google\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FcmNotification
{
	private string $access_token ;
	public function __construct()
	{
		 $client = new Client();
        $client->setAuthConfig(resource_path('firebase/wazz-chat-firebase-adminsdk-fbsvc-bd4e1c725b.json'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->fetchAccessTokenWithAssertion();
        $this->access_token = $client->getAccessToken()['access_token'];
	}
	public function send(string $title , string $body , string $fcmToken , array $additionalData = [])
	{
		
		 $fcmUrl = 'https://fcm.googleapis.com/v1/projects/' . env('FIREBASE_PROJECT_ID') . '/messages:send';
            // FCM v1 payload
            $payload = [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                   
                    'android' => [
                        'notification' => [
                            'sound' => 'default',
                            'color' => '#0A0A0A',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ];
			if(count($additionalData)){
				$payload['message']['data'] = $additionalData;
			}

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->post($fcmUrl, $payload);
			
		
		
			if($response->ok()){
				return [
					'status'=>true 
				];
			}
			logger('fail to send to firebase ');
			logger($response->body());
			return [
				'status'=>false ,
				'message'=>$response->body()
			];
	}
}
