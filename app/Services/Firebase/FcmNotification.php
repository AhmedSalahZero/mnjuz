<?php

namespace App\Services\Firebase;

use Google\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmNotification
{
    private ?string $accessToken = null;

    private ?string $lastAuthError = null;

    public function isConfigured(): bool
    {
        return $this->getAccessToken() !== null;
    }

    public function getLastAuthError(): ?string
    {
        return $this->lastAuthError;
    }

    private function credentialsPath(): string
    {
        return (string) config('services.firebase.credentials');
    }

    private function getAccessToken(): ?string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $path = $this->credentialsPath();
        if (! is_readable($path)) {
            $this->lastAuthError = 'Firebase service account JSON file is missing or unreadable.';
            Log::warning('FCM credentials file missing', ['path' => $path]);

            return null;
        }

        try {
            $client = new Client();
            $client->setAuthConfig($path);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $client->fetchAccessTokenWithAssertion();
            $this->accessToken = $client->getAccessToken()['access_token'] ?? null;

            if ($this->accessToken === null) {
                $this->lastAuthError = 'Firebase OAuth returned no access token.';
            }

            return $this->accessToken;
        } catch (\Throwable $e) {
            $this->lastAuthError = $e->getMessage();
            Log::warning('FCM OAuth token fetch failed', [
                'error' => $e->getMessage(),
                'hint' => 'Regenerate the Firebase service account key in Google Cloud Console and redeploy the JSON file.',
            ]);

            return null;
        }
    }

    public function send(string $title, string $body, string $fcmToken, array $additionalData = []): array
    {
        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return [
                'status' => false,
                'message' => $this->lastAuthError ?? 'FCM credentials unavailable',
            ];
        }

        $projectId = config('services.firebase.project_id', env('FIREBASE_PROJECT_ID'));
        if (empty($projectId)) {
            Log::warning('FCM project id is not configured');

            return [
                'status' => false,
                'message' => 'FIREBASE_PROJECT_ID is not configured',
            ];
        }

        $fcmUrl = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'android' => [
                    'notification' => [
                        'sound' => 'on_notification.wav',
                        'color' => '#0A0A0A',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'on_notification.wav',
                        ],
                    ],
                ],
                'data' => $additionalData,
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ])->post($fcmUrl, $payload);

        if ($response->ok()) {
            return [
                'status' => true,
            ];
        }

        Log::warning('FCM message delivery failed', [
            'fcm_token_prefix' => substr($fcmToken, 0, 12) . '...',
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return [
            'status' => false,
            'message' => $response->body(),
        ];
    }
}
