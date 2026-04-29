<?php

namespace App\Http\Controllers;

use App\Http\Requests\Verification\ConfirmVerificationCodeRequest;
use App\Http\Requests\Verification\RequestVerificationCodeRequest;
use App\Models\User;
use App\Services\UserVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class VerificationController extends Controller
{
    public function __construct(private readonly UserVerificationService $verificationService)
    {
    }

    public function showRequired(Request $request)
    {
        $pending = $request->session()->get(UserVerificationService::SESSION_KEY);
        if (!$pending || empty($pending['user_id'])) {
            return redirect('/login');
        }

        $user = User::find($pending['user_id']);
        if (!$user) {
            $request->session()->forget(UserVerificationService::SESSION_KEY);
            return redirect('/login');
        }

        return Inertia::render('Auth/VerificationRequired', [
            'canUseWhatsapp' => !empty($user->phone),
            'maskedEmail' => $this->maskEmail((string) $user->email),
            'maskedPhone' => $this->maskPhone((string) $user->phone),
            'email' => (string) $user->email,
            'phone' => (string) $user->phone,
        ]);
    }

    public function requestCode(RequestVerificationCodeRequest $request)
    {
        $user = $this->resolveUserForRequest($request);
        if (!$user) {
            return $this->errorResponse($request, __('verification.session_invalid'), 422);
        }

        try {
            $this->verificationService->createAndSend($user, $request->string('method')->toString());
        } catch (\Throwable $e) {
            return $this->errorResponse($request, $e->getMessage(), 422);
        }

        return $this->okResponse($request, __('verification.sent'));
    }

    public function confirmCode(ConfirmVerificationCodeRequest $request)
    {
        $user = $this->resolveUserForRequest($request);
        if (!$user) {
            return $this->errorResponse($request, __('verification.session_invalid'), 422);
        }

        $result = $this->verificationService->verifyCode($user, $request->string('code')->toString());
        if (!$result['ok']) {
            return $this->errorResponse($request, $result['message'], 422);
        }

        if ($request->is('api/*')) {
            $tokenName = $request->input('device_name', 'mobile-app-' . now()->timestamp);
            $token = $user->createToken($tokenName)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => __('verification.success'),
                'token' => $token,
            ]);
        }

        $pending = $request->session()->get(UserVerificationService::SESSION_KEY);
        $request->session()->forget(UserVerificationService::SESSION_KEY);

        $guard = ($pending['guard'] ?? 'user') === 'admin' ? 'admin' : 'user';
        Auth::guard($guard)->login($user, (bool) ($pending['remember'] ?? false));

        return redirect('/dashboard');
    }

    private function resolveUserForRequest(Request $request): ?User
    {
        if ($request->is('api/*')) {
            $token = (string) $request->input('verification_token');
            if ($token) {
                return $this->verificationService->userFromApiVerificationToken($token);
            }

            if ($request->filled('email')) {
                return User::where('email', $request->input('email'))->first();
            }

            if ($request->filled('phone')) {
                return User::where('phone', $request->input('phone'))->first();
            }

            return null;
        }

        $pending = $request->session()->get(UserVerificationService::SESSION_KEY);
        if (!$pending || empty($pending['user_id'])) {
            return null;
        }

        return User::find($pending['user_id']);
    }

    private function okResponse(Request $request, string $message)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('status', ['type' => 'success', 'message' => $message]);
    }

    private function errorResponse(Request $request, string $message, int $statusCode)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => ['verification' => [$message]],
            ], $statusCode);
        }

        return back()->withErrors(['code' => $message]);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }

        [$name, $domain] = explode('@', $email, 2);
        $prefix = substr($name, 0, 2);
        return $prefix . str_repeat('*', max(strlen($name) - 2, 1)) . '@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        if ($phone === '') {
            return '***';
        }

        $visible = substr($phone, -3);
        return str_repeat('*', max(strlen($phone) - 3, 3)) . $visible;
    }
}
