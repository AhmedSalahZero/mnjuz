<?php

namespace App\Http\Controllers;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Resolvers\PaymentPlatformResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentController extends BaseController
{
    public function __construct()
    {
        $this->paymentPlatformResolver = new PaymentPlatformResolver();
    }

    public function processPayment(Request $request, $processor)
    {
        $paymentPlatform = $this->paymentPlatformResolver->resolveService($processor);
        session()->put('paymentPlatform', $processor);
        
        if($processor === 'flutterwave'){
            $res = $paymentPlatform->updateSubscription($request->input('transaction_id'));
        }

        return redirect('/billing')->with(
            'status', [
                'type' => $res->success == true ? 'success' : 'error', 
                'message' => $res->success == true ? __('Payment Successful!') : __('Payment Unsuccessful!'), 
            ]
        );
    }
}