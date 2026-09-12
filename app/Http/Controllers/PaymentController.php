<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Resolvers\PaymentPlatformResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PaymentController extends BaseController
{
    protected PaymentPlatformResolver $paymentPlatformResolver;

    public function __construct()
    {
        $this->paymentPlatformResolver = new PaymentPlatformResolver();
    }

    public function processPayment(Request $request, $processor)
    {
        // بوّابة غير مُهيّأة تُرجع العميل إلى الفوترة برسالة مفهومة.
        //
        // كان المُحلِّل يرمي استثناءً فيرى العميل صفحة 500 بعد عودته من صفحة
        // الدفع — لا يدري أدُفع ماله أم لا. والسبب غالباً بوّابة معروضة في
        // الواجهة وإعداداتها ناقصة على الخادم.
        if (!$this->paymentPlatformResolver->isSupported($processor)) {
            Log::warning('Unsupported payment processor requested', [
                'processor' => $processor,
                'organization_id' => session()->get('current_organization'),
            ]);

            return redirect('/billing')->with(
                'status', [
                    'type' => 'error',
                    'message' => __('The selected payment method is not available. Please choose another method or contact support.'),
                ]
            );
        }

        $paymentPlatform = $this->paymentPlatformResolver->resolveService($processor);
        session()->put('paymentPlatform', $processor);

        if ($processor === 'flutterwave') {
            $res = $paymentPlatform->updateSubscription($request->input('transaction_id'));

            return redirect('/billing')->with(
                'status', [
                    'type' => $res->success == true ? 'success' : 'error',
                    'message' => $res->success == true ? __('Payment Successful!') : __('Payment Unsuccessful!'),
                ]
            );
        }

        return redirect('/billing')->with(
            'status', [
                'type' => 'error',
                'message' => __('Unsupported payment processor.'),
            ]
        );
    }

    public function myfatoorahSuccess(Request $request)
    {
        session()->put('paymentPlatform', 'MyFatoorah');

        return $this->handleMyFatoorahCallback($request, false);
    }

    public function myfatoorahError(Request $request)
    {
        session()->put('paymentPlatform', 'MyFatoorah');

        return $this->handleMyFatoorahCallback($request, true);
    }

    private function handleMyFatoorahCallback(Request $request, bool $isErrorRoute)
    {
        $paymentPlatform = $this->paymentPlatformResolver->resolveService('MyFatoorah');
        $paymentId = $request->input('paymentId') ?? $request->input('Id');

        if ($isErrorRoute && empty($paymentId)) {
            return Inertia::render('User/Billing/PaymentFailed', [
                'title' => __('Payment failed'),
                'message' => __('The payment was cancelled or could not be completed.'),
            ]);
        }

        $res = $paymentPlatform->verifyAndProcessPayment($paymentId);

        if ($res->success ?? false) {
            return Inertia::render('User/Billing/PaymentSuccess', [
                'title' => __('Payment successful'),
                'message' => $res->message ?? __('Payment processed successfully!'),
                'payment' => $res->payment ?? null,
            ]);
        }

        return Inertia::render('User/Billing/PaymentFailed', [
            'title' => __('Payment failed'),
            'message' => $res->message ?? __('Payment Unsuccessful!'),
            'paymentId' => $paymentId,
        ]);
    }
}
