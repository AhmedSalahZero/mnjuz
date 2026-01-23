<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ScrambleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
	Scramble::routes(function (Route $route) {
            // Exclude the specific route
			$middlewares=$route->middleware();
            if (in_array('ExcludeRouteFromDocs',$middlewares)) {
                return false;
            }
            return Str::startsWith($route->uri, 'api/');
        });
	
	
	
        //
    }
}
