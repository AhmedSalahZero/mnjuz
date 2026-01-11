<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreSubscriptionPlan;
use App\Models\Addon;
use App\Models\Setting;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SubscriptionPlanController extends BaseController
{
    private $SubscriptionPlanService;

    /**
     * SubscriptionController constructor.
     *
     * @param SubscriptionPlanService $subscriptionPlanService
     */
    public function __construct(SubscriptionPlanService $subscriptionPlanService)
    {
        $this->subscriptionPlanService = $subscriptionPlanService;
    }

    /**
     * Display a listing of subscription plans.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('Admin/SubscriptionPlan/Index', [
            'title' => __('Plans'),
            'allowCreate' => true,
            'rows' => $this->subscriptionPlanService->get($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Display the specified subscription plan.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show($uuid = NULL)
    {
        $plan = $this->subscriptionPlanService->getByUuid($uuid);

        return Inertia::render('Admin/SubscriptionPlan/Show', [
            'title' => __('Subscription plans'), 
            'plan' => $plan,
            'addons' => Addon::where('status', 1)->where('is_plan_restricted', 1)->pluck('name'),
            'enable_ai_billing' => Setting::where('key', 'enable_ai_billing')->value('value') ?? 0,
        ]);
    }

    /**
     * Display Form
     *
     * @param $request
     */
    public function create(Request $request)
    {
        $plan = $this->subscriptionPlanService->getByUuid(NULL);

        return Inertia::render('Admin/SubscriptionPlan/Show', [
            'title' => __('Subscription plans'), 
            'plan' => $plan,
            'addons' => Addon::where('status', 1)->where('is_plan_restricted', 1)->pluck('name'),
            'enable_ai_billing' => Setting::where('key', 'enable_ai_billing')->value('value') ?? 0,
        ]);
    }

    /**
     * Store a newly created subscription plan.
     *
     * @param Request $request
     */
    public function store(StoreSubscriptionPlan $request)
    {
        $this->subscriptionPlanService->store($request);

        return redirect('/admin/plans')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Plan created successfully!')
            ]
        );
    }

    /**
     * Update the specified subscription plan.
     *
     * @param Request $request
     */
    public function update(StoreSubscriptionPlan $request, $uuid)
    {
        $this->subscriptionPlanService->update($request, $uuid);

        return redirect('/admin/plans')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Plan updated successfully!')
            ]
        );
    }

    /**
     * Remove the specified subscription plan.
     *
     * @param String $uuid
     */
    public function destroy($uuid)
    {
        $this->subscriptionPlanService->destroy($uuid);
    }
}
