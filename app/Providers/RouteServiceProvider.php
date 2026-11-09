<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->configureWebChatRateLimiting();

        $this->routes(function () {
            Route::middleware('webchat')
                ->prefix('api/webchat/v1')
                ->group(base_path('routes/webchat.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * حدود المحادثة المباشرة. المفتاح للزائر بصمة توكنه (قبل أن يُتحقَّق منه
     * — الحدّ يسبق الحارس)، وللنموذج الـ IP لأن الزائر لا توكن له بعد.
     */
    private function configureWebChatRateLimiting(): void
    {
        $visitor = static fn (Request $request): string => hash('sha256', (string) $request->headers->get('X-Webchat-Token', $request->ip()));
        $widget = static fn (Request $request): string => (string) $request->route('key');

        RateLimiter::for('webchat-config', fn (Request $request) => Limit::perMinute(60)->by('wcc:' . $request->ip()));

        RateLimiter::for('webchat-session', fn (Request $request) => [
            Limit::perMinute(5)->by('wcs:' . $request->ip()),
            Limit::perDay(30)->by('wcsd:' . $request->ip() . ':' . $widget($request)),
        ]);

        RateLimiter::for('webchat-message', fn (Request $request) => [
            Limit::perMinute(20)->by('wcm:' . $visitor($request)),
            Limit::perMinute(120)->by('wcmi:' . $request->ip()),
        ]);

        RateLimiter::for('webchat-upload', fn (Request $request) => Limit::perMinute(5)->by('wcu:' . $visitor($request)));

        RateLimiter::for('webchat-read', fn (Request $request) => Limit::perMinute(60)->by('wcr:' . $visitor($request)));
    }
}
