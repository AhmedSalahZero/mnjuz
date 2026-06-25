<?php

namespace App\Providers;

use App\Models\Chat;
use App\Models\Contact;
use App\Observers\ContactObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
	//	$chat = Chat::with('media')->find(85364);
		
		
        Schema::defaultStringLength(191);

        Contact::observe(ContactObserver::class);
		
        include app_path('Helpers/Helpers.php');
		Gate::define('viewApiDocs', function () {
			return true;
    //    return in_array($user->email, ['admin@app.com']);
    });
	
        if (!\App::environment('local')) {
            \URL::forceScheme('https');
        }
    }
}
