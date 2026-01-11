<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentController extends BaseController
{
    private $RoleService;

    /**
     * PaymentController constructor.
     *
     * @param BillingService $billingService
     */
    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function index(Request $request){
        return Inertia::render('Admin/Payment/Index', [
            'title' => __('Billing'),
            'rows' => $this->billingService->get($request), 
            'filters' => $request->all()
        ]);
    }
}