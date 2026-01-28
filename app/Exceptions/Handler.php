<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Convert an authentication exception into a response.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // For API requests, return JSON response
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated'),
                'error' => __('You are not authenticated. Please login first.'),
            ], 401);
        }

        // For web requests, redirect to login
        return redirect()->guest($exception->redirectTo() ?? route('login'));
    }

    function render($request, Throwable $exception)
    {
        // For API requests, return JSON responses instead of HTML views
        if ($request->expectsJson() || $request->is('api/*')) {
            if ($this->isHttpException($exception)) {
                $statusCode = $exception->getStatusCode();
                
                return response()->json([
                    'success' => false,
                    'message' => $this->getErrorMessage($statusCode),
                    'error' => $exception->getMessage() ?: $this->getErrorMessage($statusCode),
                ], $statusCode);
            }
            
            // For other exceptions in API, return JSON
            return response()->json([
                'success' => false,
                'message' => __('An error occurred'),
                'error' => config('app.debug') ? $exception->getMessage() : __('An error occurred'),
            ], 500);
        }
        
        // For web requests, return HTML views
        if ($this->isHttpException($exception)) {
            if ($exception->getStatusCode() == 404) {
                return response()->view('errors.404', [], 404);
            }
            if ($exception->getStatusCode() == 500) {
                return response()->view('errors.500', [], 500);
            }
        }
        return parent::render($request, $exception);
    }
    
    /**
     * Get error message based on status code
     */
    private function getErrorMessage($statusCode)
    {
        $messages = [
            404 => __('Route not found'),
            403 => __('Forbidden'),
            401 => __('Unauthorized'),
            500 => __('Internal server error'),
            422 => __('Validation error'),
        ];
        
        return $messages[$statusCode] ?? __('An error occurred');
    }
}
