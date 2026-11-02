<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Services\AgentPerformanceService;
use App\Services\SubscriptionService;
use App\Support\OrganizationRole;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentPerformanceController extends BaseController
{
    private function organizationId()
    {
        return session()->get('current_organization');
    }

    private function featureEnabled(): bool
    {
        return SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId(), 'agent_performance');
    }

    private function isManager(): bool
    {
        $role = auth()->user()->getRoleNameForOrganization($this->organizationId());
        if ($role === '') {
            $role = OrganizationRole::OWNER;
        }

        return OrganizationRole::isPrivileged($role);
    }

    /** الميزة مفعّلة والمستخدم مدير، وإلا 403 — للصفحة ولنقطة الحالة معاً. */
    private function authorizeManager(): void
    {
        if (!$this->featureEnabled()) {
            abort(403, __('This feature is not available in your plan.'));
        }

        if (!$this->isManager()) {
            abort(403, __('You are not allowed to access this page.'));
        }
    }

    public function index(Request $request)
    {
        $this->authorizeManager();

        // من يفتح الصفحة حاضرٌ بالضرورة. بدونه تُحسب الحالة قبل أن تنطلق نبضته
        // الأولى، فيرى المدير نفسه «غير متصل» في أول تحميل.
        AgentPerformanceService::markPresent((int) $this->organizationId(), (int) auth()->id());

        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : Carbon::now()->endOfDay();
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::now()->subDays(29)->startOfDay();

        $service = new AgentPerformanceService((int) $this->organizationId());

        return Inertia::render('User/Performance', [
            'title' => __('Agent Performance'),
            'metrics' => $service->metrics($from, $to),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    /**
     * حالة الاتصال الآنية فقط، لتحدّث الصفحةُ نفسها دورياً دون إعادة تحميل
     * المقاييس كلّها (وفيها مسح محادثات ثلاثين يوماً).
     */
    public function status()
    {
        $this->authorizeManager();

        $service = new AgentPerformanceService((int) $this->organizationId());

        return response()
            ->json(['agents' => $service->presence()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * نبضة نشاط من واجهة المستخدم لتتبّع الوقت النشط وآخر ظهور.
     */
    public function heartbeat(Request $request)
    {
        if ($this->featureEnabled()) {
            AgentPerformanceService::recordHeartbeat(
                (int) $this->organizationId(),
                (int) auth()->id(),
                $request->boolean('visible', true)
            );
        }

        return response()->json(['success' => true]);
    }
}
