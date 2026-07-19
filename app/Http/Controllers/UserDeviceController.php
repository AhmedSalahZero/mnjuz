<?php

namespace App\Http\Controllers;

use App\Services\UserDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserDeviceController extends Controller
{
    public function __construct(protected UserDeviceService $deviceService) {}

    public function show(Request $request)
    {
        $user = $request->user();

        // إرجاع كل الأجهزة المرتبطة (web و mobile منفصلين)
        return response()->json([
            'success' => true,
            'data' => $user?->devices()->orderBy('last_used_at', 'desc')->get(),
        ]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated'),
            ], 401);
        }

        $deviceId = $request->input('device_id');
        $device = $deviceId
            ? $user->devices()->where('id', $deviceId)->first()
            : null;

        if (!$device) {
            $deviceData = $this->deviceService->extractDeviceData($request);
            $category   = $this->deviceService->resolveCategory($request, $deviceData);
            $device     = $user->deviceForCategory($category);
        }

        $removedCategory = $device?->device_category;
        $currentCategory = $this->deviceService->resolveCategory(
            $request,
            $this->deviceService->extractDeviceData($request)
        );

        if ($device) {
            $device->delete();
        }

        $shouldLogout = $removedCategory === null || $removedCategory === $currentCategory;

        if ($shouldLogout && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        if ($shouldLogout && $request->hasSession()) {
            Auth::guard('user')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'logged_out' => $shouldLogout,
            'message' => __('Device removed successfully'),
        ]);
    }
}
