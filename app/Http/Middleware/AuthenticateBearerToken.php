<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\OrganizationApiKey;
use Illuminate\Http\Request;

class AuthenticateBearerToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => __('Unauthorized. Bearer Token is missing.')], 401);
        }

        $organizationApiKey = OrganizationApiKey::where('token', $token)->whereNull('deleted_at')->first();

        if (!$organizationApiKey) {
            return response()->json(['error' => __('Unauthorized. Invalid Bearer Token.')], 401);
        }

        // Attach organization to the request
        $request->merge(['organization' => $organizationApiKey->organization_id]);

        return $next($request);
    }
}