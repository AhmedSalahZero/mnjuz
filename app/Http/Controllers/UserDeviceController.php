<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserDeviceController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $user?->device,
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

        if ($user->device) {
            $user->device->delete();
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
