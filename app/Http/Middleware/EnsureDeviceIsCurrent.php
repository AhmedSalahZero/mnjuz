<?php

namespace App\Http\Middleware;

use App\Services\UserDeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceIsCurrent
{
    public function __construct(protected UserDeviceService $deviceService) {}

    /**
     * Evict a web session once its account has been logged in on another device
     * of the same category. Newer logins overwrite the stored device_identifier;
     * any session whose identifier no longer matches is signed out.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$request->hasSession()) {
            return $next($request);
        }

        $sessionIdentifier = $request->session()->get('device_identifier');

        // Grandfather in sessions created before this feature (no stored id yet).
        if (!$sessionIdentifier) {
            return $next($request);
        }

        $deviceData = $this->deviceService->extractDeviceData($request);
        $category   = $this->deviceService->resolveCategory($request, $deviceData);
        $device     = $user->deviceForCategory($category);

        if ($device && $device->device_identifier && $device->device_identifier !== $sessionIdentifier) {
            Auth::guard('user')->logout();
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', [
                'type' => 'info',
                'message' => __('You were signed out because your account was accessed from another device.'),
            ]);
        }

        return $next($request);
    }
}
