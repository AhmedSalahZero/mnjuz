<?php

namespace App\Http\Middleware;

use App\Support\RequestLookupCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يفتح RequestLookupCache طوال الطلب — بما فيه بناء props الصفحة، فـ Inertia
 * تحلّ الـ closures داخل $next — ويغلقها بعده مهما حدث.
 */
class CacheLookupsForRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestLookupCache::open();

        try {
            return $next($request);
        } finally {
            RequestLookupCache::close();
        }
    }
}
