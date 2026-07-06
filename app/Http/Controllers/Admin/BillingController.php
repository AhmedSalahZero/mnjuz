<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreBillingTransaction;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillingController extends BaseController
{
    private $billingService;

    public function __construct()
    {
        $this->billingService = new BillingService();
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Admin/Organization/Index', [
            'title' => __('Billing'),
            'allowCreate' => true,
            'rows' => $this->billingService->get($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBillingTransaction $request)
    {
        $this->billingService->store($request);

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Transaction created successfully!')
            ]
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }
}
