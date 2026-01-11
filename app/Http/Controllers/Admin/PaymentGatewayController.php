<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\PaymentGatewayResource;
use App\Http\Requests\StorePaymentGateway;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule; 
use Inertia\Inertia;
use Helper;
use Session;
use Validator;

class PaymentGatewayController extends BaseController
{
    public function index(Request $request){
        $rows = (new PaymentGateway)->listAll();

        return Inertia::render('Admin/Setting/PaymentGateway', ['rows' => PaymentGatewayResource::collection($rows)]);
    }

    public function show($type)
    {
        $gateway = PaymentGateway::where('name', $type)->first();
        return response()->json(['success' => true, 'data'=> $gateway]);
    }

    public function update(StorePaymentGateway $request, $type){
        if (env('APP_ENV') === 'demo') {
            // Return a response indicating that the function is not allowed in demo environment
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Updating settings is not allowed in demo.')
                ]
            );
        }

        $metadata = [];

        switch (strtolower($type)) {
            case 'paypal':
                $metadata = [
                    'client_id' => $request->client_id,
                    'secret' => $request->secret,
                    'mode' => $request->mode,
                    'webhook_id' => $request->webhook_id
                ];
                break;
                
            case 'stripe':
                $metadata = [
                    'publishable_key' => $request->publishable_key,
                    'secret_key' => $request->secret_key,
                    'webhook_secret' => $request->webhook_secret
                ];
                break;
                
            case 'flutterwave':
            case 'paystack':
                $metadata = [
                    'public_key' => $request->public_key,
                    'secret_key' => $request->secret_key,
                ];
                break;
        }

        $method = PaymentGateway::where('name', '=', $type)->update([
            'metadata' => $metadata,
            'is_active' => $request->status
        ]);

        return redirect('/admin/payment-gateways')->with(
            'status', [
                'type' => 'success', 
                'message' => ucfirst($type) . ' updated successfully!'
            ]
        );
    }
}