<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\PaymentRequest;
use App\Models\Addon;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\Subscription;
use App\Resolvers\PaymentPlatformResolver;
use App\Services\BillingService;
use App\Services\SubscriptionService;
use App\Services\WazSyncService;
use App\Exceptions\WazBusinessException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Redirect;

class BillingController extends BaseController
{
    protected $billingService;
    protected $subscriptionService;
    protected $paymentPlatformResolver;

    public function __construct()
    {
        $this->billingService = new BillingService();
        $this->subscriptionService = new SubscriptionService();
        $this->paymentPlatformResolver = new PaymentPlatformResolver();
    }
    
    public function index(Request $request){
        $organizationId = session()->get('current_organization');
        $organization = Organization::where('id', $organizationId)->first();
        $data['subscription'] = Subscription::with('plan')->where('organization_id', $organizationId)->first();
        $data['subscriptionIsActive'] = SubscriptionService::isSubscriptionActive($organizationId);
        $data['rows'] = $this->billingService->get($request, $organization->uuid);
        // رابط عرض الفاتورة الرسمية بجانب كل صفّ فاتورة.
        $data['invoiceUrls'] = $this->wazInvoiceUrls($organizationId);
        $data['filters'] = $request->all();
        $data['methods'] = $this->paymentMethods();
        $data['subscriptionDetails'] = SubscriptionService::calculateSubscriptionBillingDetails($organizationId, $data['subscription']->plan_id);
        $data['title'] = __('Billing');
        $data['isPaymentLoading'] = false;
        $data['pusherSettings'] = Setting::whereIn('key', [
            'pusher_app_key',
            'pusher_app_cluster',
        ])->pluck('value', 'key')->toArray();
        $data['setting'] = Setting::whereIn('key', ['enable_custom_payment'])->pluck('value', 'key')->toArray();
        $data['organizationId'] = $organizationId;

        if($request->has('paymentId') && $request->has('token')){
            //Check if payment id exists in DB
            $payment = BillingPayment::where('details', $request->paymentId)->first();
            if(!$payment){
                $data['isPaymentLoading'] = true;
            } else {
                return redirect('/billing')->with(
                    'status', [
                        'type' => 'success', 
                        'message' => __('Payment processed successfully!')
                    ]
                );
            }
        } else if($request->has('hostedpage')){
            if (file_exists(base_path('modules/Pabbly/Services/PabblyService.php'))) {
                $data['isPaymentLoading'] = true;

                $pabblyService = new \Modules\Pabbly\Services\PabblyService();
                $response = $pabblyService->subscribeToPlan($request->hostedpage);
                $data = $response->getData();
                
                return redirect('/billing')->with(
                    'status', [
                        'type' => $response->status() === '200' ? 'success' : 'error', 
                        'message' => $data->message
                    ]
                );
            }
        }

        return Inertia::render('User/Billing/Index', $data);
    }

    public function pay(PaymentRequest $request){
        $paymentPlatform = $this->paymentPlatformResolver->resolveService($request->method);
        session()->put('paymentPlatform', $request->method);

        $response = $paymentPlatform->handlePayment($request->amount);

        if ($response->success === true) {
            if ($request->boolean('redirect_json') || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'redirect_url' => $response->data,
                ]);
            }

            return Inertia::location($response->data);
        }

        if ($request->boolean('redirect_json') || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => $response->error ?? __('Could not process your payment successfully!'),
            ], 422);
        }

        return redirect('/billing')->with(
            'status', [
                'type' => 'error',
                'message' => $response->error ?? __('Could not process your payment successfully!'),
            ]
        );
    }

    private function paymentMethods(){
        $mergedData = [];

        // Retrieve active payment methods and add to mergedData
        $paymentMethods = PaymentGateway::where('is_active', 1)->get();
        $mergedData = $paymentMethods->map(function ($method) {
            return ['name' => $method->name];
        })->toArray();

        // Retrieve active addons and check settings
        $activeAddons = Addon::where('category', 'payments')
            ->where('status', 1)
            ->where('is_active', 1)
            ->get()
            ->pluck('name')
            ->toArray();

        // Add active addons to mergedData
        foreach ($activeAddons as $addonName) {
            $mergedData[] = ['name' => $addonName];
        }

        return (new PaymentPlatformResolver())->filterSupportedMethods($mergedData);
    }

    /**
     * روابط عرض الفواتير الرسمية، مفهرسة بمعرّف حركة الفوترة المحلية.
     *
     * الجدول يعرض حركات (`billing_transactions`) لا فواتير، فنربط كل حركة من
     * نوع invoice بفاتورتها ثم بنظائرها في واز. الفاتورة الواحدة عندنا تقابل
     * فاتورتين هناك — رسوم التأسيس والاشتراك منفصلتان بشرط المنصة — فنُرجعهما
     * معاً. كان يُرجَع رابط واحد فقط، فلا يجد العميل رسوم تأسيسه إطلاقاً.
     *
     * تعذّر الوصول للمنصة لا يُفشل صفحة الفوترة — تظهر بلا أزرار عرض فقط.
     *
     * @return array<int, array<int, array{kind: string, url: string}>>
     */
    private function wazInvoiceUrls($organizationId): array
    {
        $waz = app(WazSyncService::class);

        try {
            $urls = $waz->invoiceUrlsFor((int) $organizationId);
        } catch (WazBusinessException $e) {
            Log::warning('Waz: could not load invoice links for the billing page', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (!$urls) {
            return [];
        }

        $transactions = DB::table('billing_transactions')
            ->where('organization_id', $organizationId)
            ->where('entity_type', 'invoice')
            ->pluck('entity_id', 'id');

        if ($transactions->isEmpty()) {
            return [];
        }

        $invoices = DB::table('billing_invoices')
            ->whereIn('id', $transactions->values())
            ->get(['id', 'waz_invoice_id', 'waz_setup_invoice_id'])
            ->keyBy('id');

        $result = [];
        foreach ($transactions as $transactionId => $invoiceId) {
            $invoice = $invoices->get($invoiceId);
            if (!$invoice) {
                continue;
            }

            // الاشتراك أولاً لأنه المبلغ الأساسي، ثم التأسيس.
            $links = [];
            foreach (['plan' => $invoice->waz_invoice_id, 'setup' => $invoice->waz_setup_invoice_id] as $kind => $wazId) {
                if ($wazId && isset($urls[(int) $wazId])) {
                    $links[] = ['kind' => $kind, 'url' => $urls[(int) $wazId]];
                }
            }

            if ($links) {
                $result[(int) $transactionId] = $links;
            }
        }

        return $result;
    }
}