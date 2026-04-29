<?php

namespace App\Services;

use App\Http\Controllers\ApiController;
use App\Jobs\SendUserVerificationEmailJob;
use App\Jobs\SendUserVerificationWhatsappJob;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use App\Models\UserVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

class UserVerificationService
{
    public const SESSION_KEY = 'pending_user_verification';
    private const CODE_LENGTH = 6;
    private const EXPIRY_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function verificationIsRequired(User $user): bool
    {
        return (bool) $user->verification_enabled && !(bool) $user->is_verified;
    }

    public function createAndSend(User $user, string $method): UserVerification
    {
        $this->ensureMethodSupported($user, $method);
        $this->ensureResendAllowed($user, $method);

        UserVerification::where('user_id', $user->id)
            ->whereNull('verified_at')
            ->update(['verified_at' => now()]);

        $code = $this->generateCode();
        $verification = UserVerification::create([
            'user_id' => $user->id,
            'code' => Hash::make($code),
            'method' => $method,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'attempts' => 0,
        ]);

        if ($method === 'email') {
            SendUserVerificationEmailJob::dispatch($verification->id, $code, self::EXPIRY_MINUTES);
        } else {
            SendUserVerificationWhatsappJob::dispatch($verification->id, $code);
        }

        return $verification;
    }

    public function verifyCode(User $user, string $code): array
    {
        $verification = UserVerification::where('user_id', $user->id)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (!$verification) {
            return ['ok' => false, 'message' => __('verification.no_active_request')];
        }

        if ($verification->expires_at->isPast()) {
            return ['ok' => false, 'message' => __('verification.expired')];
        }

        if ($verification->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => __('verification.max_attempts')];
        }

        if (!Hash::check($code, $verification->code)) {
            $verification->increment('attempts');
            return ['ok' => false, 'message' => __('verification.invalid')];
        }

        $verification->update([
            'verified_at' => now(),
            'attempts' => $verification->attempts + 1,
        ]);

        $user->forceFill(['is_verified' => true])->save();

        return ['ok' => true, 'message' => __('verification.success')];
    }

    public function resendCooldownSeconds(User $user, string $method): int
    {
        $key = $this->cooldownKey($user, $method);
        return RateLimiter::availableIn($key);
    }

    public function sendWhatsappCode(User $user, string $code): void
    {
        $organizationId = $this->resolveOrganizationId($user);
        if (!$organizationId) {
            throw new RuntimeException(__('verification.organization_missing'));
        }

        $template = Template::where('organization_id', $organizationId)->where('id', 4)->first();
        if (!$template) {
            throw new RuntimeException(__('verification.whatsapp_template_missing'));
        }

        $formattedPhone = $this->normalizeWhatsappPhone((string) $user->phone);
        if (!$formattedPhone) {
            throw new RuntimeException(__('verification.phone_invalid'));
        }

        $templatePayload = [
            'name' => $template->name,
            'language' => ['code' => $template->language],
            'components' => $this->buildVerificationTemplateComponents($template, $code),
        ];

        $apiRequest = Request::create('/api/send/template', 'POST', [
            'organization' => $organizationId,
            'phone' => $formattedPhone,
            'template' => $templatePayload,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ]);

        $response = app(ApiController::class)->sendTemplateMessage($apiRequest);
        $responseBody = json_decode($response->getContent(), true);
        $whatsappAccepted = (bool) data_get($responseBody, 'data.success', false);

        if ($response->getStatusCode() >= 400 || !$whatsappAccepted) {
            Log::warning('Failed to send verification WhatsApp message.', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'status' => $response->getStatusCode(),
                'response' => $responseBody ?? $response->getContent(),
            ]);
            throw new RuntimeException(__('verification.whatsapp_send_failed'));
        }
    }

    public function createApiVerificationToken(User $user): string
    {
        return encrypt($user->id . '|' . now()->timestamp . '|' . Str::random(16));
    }

    public function userFromApiVerificationToken(string $token): ?User
    {
        try {
            $decoded = decrypt($token);
            [$userId] = explode('|', $decoded);
            return User::find($userId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function ensureMethodSupported(User $user, string $method): void
    {
        if (!in_array($method, ['email', 'whatsapp'], true)) {
            throw new RuntimeException(__('verification.method_invalid'));
        }

        if ($method === 'whatsapp' && empty($user->phone)) {
            throw new RuntimeException(__('verification.phone_required'));
        }

        if ($method === 'email' && empty($user->email)) {
            throw new RuntimeException(__('verification.email_required'));
        }
    }

    private function ensureResendAllowed(User $user, string $method): void
    {
        $key = $this->cooldownKey($user, $method);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            throw new RuntimeException(__('verification.wait_seconds', ['seconds' => $seconds]));
        }

        RateLimiter::hit($key, self::RESEND_COOLDOWN_SECONDS);
    }

    private function cooldownKey(User $user, string $method): string
    {
        return "verification:resend:{$user->id}:{$method}";
    }

    private function resolveOrganizationId(User $user): ?int
    {
        if (!empty($user->current_organization_id)) {
            return (int) $user->current_organization_id;
        }

        $team = $user->teams()->whereNull('deleted_at')->first();
        if (!$team) {
            return null;
        }

        $organization = Organization::find($team->organization_id);
        return $organization ? (int) $organization->id : null;
    }

    private function normalizeWhatsappPhone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        $direct = PhoneService::getE164Format($phone);
        if ($direct) {
            return $direct;
        }

        // Common bad format in stored numbers: +0<country><number> (e.g. +020...)
        if (str_starts_with($phone, '+0')) {
            $candidate = '+' . ltrim(substr($phone, 1), '0');
            $formatted = PhoneService::getE164Format($candidate);
            if ($formatted) {
                return $formatted;
            }
        }

        // International dialing prefix 00 -> +
        if (str_starts_with($phone, '00')) {
            $candidate = '+' . substr($phone, 2);
            $formatted = PhoneService::getE164Format($candidate);
            if ($formatted) {
                return $formatted;
            }
        }

        return null;
    }

    private function buildVerificationTemplateComponents(Template $template, string $code): array
    {
        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $code],
                ],
            ],
        ];

        $metadata = json_decode((string) $template->metadata, true);
        $templateComponents = is_array($metadata['components'] ?? null) ? $metadata['components'] : [];

        foreach ($templateComponents as $component) {
            if (($component['type'] ?? null) !== 'BUTTONS') {
                continue;
            }

            $buttons = is_array($component['buttons'] ?? null) ? $component['buttons'] : [];
            foreach ($buttons as $index => $button) {
                if (($button['type'] ?? null) !== 'URL') {
                    continue;
                }

                $url = (string) ($button['url'] ?? '');
                if (!str_contains($url, '{{1}}')) {
                    continue;
                }

                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => (string) $index,
                    'parameters' => [
                        ['type' => 'text', 'text' => $code],
                    ],
                ];
            }
        }

        return $components;
    }
}
