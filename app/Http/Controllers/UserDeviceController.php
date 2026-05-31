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

        // حذف الجهاز الخاص بالـ category الحالي فقط (web أو mobile)
        $deviceData = $this->deviceService->extractDeviceData($request);
        $category   = $this->deviceService->getDeviceCategory($deviceData);
        $device     = $user->deviceForCategory($category);

        if ($device) {
            $device->delete();
        }

        if ($request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('user')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => __('Device removed successfully'),
        ]);
    }
}
