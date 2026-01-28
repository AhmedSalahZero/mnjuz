<?php

namespace App\Http\Middleware;

use App\Helpers\CustomHelper;

use App\Models\Addon;
use Closure;
use Illuminate\Support\Facades\Auth;


class CheckMobileApp
{
    public function handle($request, Closure $next)
    {
        // Check if the user is logged in
		$user = auth()->user();
        
        // If user is not authenticated, let Sanctum middleware handle it (401)
        if (!$user) {
            return $next($request);
        }
        
        // If user doesn't have current_organization_id, deny access
        if (!$user->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => __('Mobile App requires a selected organization. Please set your current organization.')
            ], 403);
        }
        
        // Check if addon exists and is enabled for the organization
        $addon = Addon::where('name', 'Mobile App')->where('status', 1)->where('is_active', 1)->first();
		$mobileAppEnabled = CustomHelper::isModuleEnabled('Mobile App', $user->current_organization_id);
		$mobileAppEnabled = true;
        if(!$addon || !$mobileAppEnabled){
            // Delete the token if it exists
            $token = $request->user()->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }
            return response()->json([
                'success' => false,
                'message' => __('Mobile App is not enabled for your organization. Please contact support.')
            ], 403);
        }
        
        return $next($request);
    }
}
