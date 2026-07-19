<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Str;

class CheckAppStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $currentPath = $request->path();

        // Check if the current route is the install route
        if (Str::startsWith($currentPath, 'install')) {
            if (!$this->isInstalled()) {
                return $next($request);
            } else {
                abort(404);
            }
        }

        // Check if the application is installed
        if (!$this->isInstalled()) {
            return redirect()->route('install'); // Redirect to the install page
        }

        // Check if the current route is the update route
        if (Str::startsWith($currentPath, 'update')) {
            if (!$this->isUpdated()) {
                return $next($request);
            } else {
                abort(404);
            }
        }

        // Check if the application is updated
        if(!$this->isUpdated()){
            return redirect()->route('install.update');
        }

        return $next($request);
    }

    /**
     * Checks if the application has been installed.
     *
     * @return bool
     */
    public function isInstalled(): bool
    {
        return file_exists(storage_path('installed'));
    }

    /**
     * Checks if the application has been updated.
     *
     * @return bool
     */
    public function isUpdated(): bool
    {
        $file = json_decode(file_get_contents(storage_path('installed')));

        if(!isset($file->version) || $file->version != Config::get('version.version')){
            return false;
        } else {
            return true;
        }
    }
}
