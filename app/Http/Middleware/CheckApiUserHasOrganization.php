<?php

namespace App\Http\Middleware;

use Closure;


class CheckApiUserHasOrganization
{
    public function handle($request, Closure $next)
    {
		
        if (!auth()->user()->current_mobile_organization_id) {
            return response()->json([
                'success' => false,
                'message' => __('Please select an organization to continue.'),
                // كما في CheckMobileApp: رمزٌ يفتح به التطبيق اختيار المنشأة.
                'code' => 'organization_required',
            ], 403);
        }

        return $next($request);
    }
}
