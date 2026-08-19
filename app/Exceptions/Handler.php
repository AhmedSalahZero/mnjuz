<?php

namespace App\Exceptions;

use App\Jobs\SendErrorReportEmailJob;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    protected const ERROR_REPORT_EMAIL = 'asalahdev5@gmail.com';

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
     * Report or log an exception.
     */
    public function report(Throwable $exception)
    {
        if ($this->shouldReport($exception) && app()->bound('sentry') && config('app.env') != 'local') {
            app('sentry')->captureException($exception);
        }

        parent::report($exception);
    }

    /**
     * Convert an authentication exception into a response.
     */
    /**
     * جسم ردّ 401 للتطبيق.
     *
     * الحالة تبقى 401 لأنها الصحيحة — الرمز لم يعد صالحاً — لكن `code` يميّز
     * سبب الخروج: device_replaced تعني «دخلتَ من جهاز آخر» فيعرضها التطبيق
     * ويعود بالمستخدم إلى شاشة الدخول بدل رسالة خطأ غامضة أو شاشة فارغة.
     */
    protected static function unauthenticatedPayload($request): array
    {
        $reason = \App\Support\TokenRevocation::reasonForRequest($request);

        if ($reason !== null) {
            return [
                'success' => false,
                'code' => $reason,
                'action' => 'logout',
                'message' => \App\Support\TokenRevocation::messageFor($reason),
                'error' => \App\Support\TokenRevocation::messageFor($reason),
            ];
        }

        return [
            'success' => false,
            'code' => 'unauthenticated',
            'action' => 'logout',
            'message' => __('Unauthenticated'),
            'error' => __('You are not authenticated. Please login first.'),
        ];
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // For API requests, return JSON response
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(self::unauthenticatedPayload($request), 401);
        }

        // For web requests, redirect to login
        return redirect()->guest($exception->redirectTo() ?? route('login'));
    }

    function render($request, Throwable $exception)
    {
        // For API requests, return JSON responses instead of HTML views
        if ($request->expectsJson() || $request->is('api/*')) {
            // AuthenticationException must be handled before isHttpException check
            // because it is NOT an HttpException and would otherwise fall through to 500
            if ($exception instanceof AuthenticationException) {
                return response()->json(self::unauthenticatedPayload($request), 401);
            }

            if ($this->isHttpException($exception)) {
                $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
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
