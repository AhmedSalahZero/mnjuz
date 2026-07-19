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
        $gateway = PaymentGateway::whereRaw('LOWER(name) = ?', [strtolower($type)])->first();

        if ($gateway) {
            // Never expose secret metadata (secret keys, webhook secrets, tokens) to the browser.
            $gateway->metadata = PaymentGatewayResource::redactMetadata($gateway->name, $gateway->metadata);
        }

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

            case 'myfatoorah':
                $metadata = [
                    'api_key' => $request->api_key,
                    'webhook_secret' => $request->webhook_secret,
                    'mode' => $request->mode,
                    'country_code' => $request->country_code ?? 'SAU',
                    'currency' => $request->currency ?? 'SAR',
                    'language' => $request->language ?? 'ar',
                    'base_url' => $request->mode === 'production'
                        ? 'https://api-sa.myfatoorah.com'
                        : 'https://apitest.myfatoorah.com',
                ];
                break;
        }

        $gateway = PaymentGateway::whereRaw('LOWER(name) = ?', [strtolower($type)])->first();

        if (!$gateway) {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('Payment gateway not found.'),
                ]
            );
        }

        // Secret fields are no longer sent to the browser, so a blank submission
        // means "keep the currently stored secret" instead of erasing it.
        $existingMetadata = $gateway->metadata ? (json_decode($gateway->metadata, true) ?: []) : [];
        foreach (PaymentGatewayResource::secretFields($type) as $field) {
            if (blank($metadata[$field] ?? null) && filled($existingMetadata[$field] ?? null)) {
                $metadata[$field] = $existingMetadata[$field];
            }
        }

        PaymentGateway::where('id', $gateway->id)->update([
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