<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Team;
use App\Services\AgentPerformanceService;
use App\Services\AgentPresence;
use App\Services\SubscriptionService;
use App\Support\OrganizationRole;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
     * نبضة التبويب: تُثبت حضوره وتقيس الوقت النشط.
     *
     * المنظمة من التبويب لا من الجلسة: الجلسة تحمل آخر شركة اختيرت، فمن فتح
     * شركتين في تبويبين كان يظهر «غير متصل» في إحداهما وهو يعمل فيها.
     */
    public function heartbeat(Request $request)
    {
        if ($invalid = $this->invalidClientContext($request)) {
            return $invalid;
        }

        [$organizationId, $clientId] = $this->clientContext($request);

        if (!$organizationId) {
            return response()->json(['success' => false], 403);
        }

        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'agent_performance')) {
            return response()->json(['success' => true]);
        }

        AgentPerformanceService::recordHeartbeat(
            $organizationId,
            (int) auth()->id(),
            $request->boolean('visible', true)
        );

        // واجهة قديمة مخبّأة (قبل النشر) لا ترسل معرّف تبويب: عميل واحد باسم
        // الويب، فتبقى صحيحة حتى تُحدَّث الصفحة.
        AgentPresence::touch(
            $organizationId,
            (int) auth()->id(),
            $clientId ?? AgentPresence::WEB,
            AgentPresence::WEB,
            $request->session()->getId()
        );

        return response()->json(['success' => true, 'interval' => AgentPresence::HEARTBEAT_SECONDS]);
    }

    /**
     * التبويب يُغلق (pagehide عبر sendBeacon). يُبثّ «غير متصل» بعد مهلة قصيرة
     * إن لم يبقَ للموظف تبويب أو تطبيق حيّ.
     */
    public function offline(Request $request)
    {
        if ($invalid = $this->invalidClientContext($request)) {
            return $invalid;
        }

        [$organizationId, $clientId] = $this->clientContext($request);

        if ($organizationId && $clientId) {
            AgentPresence::leave($organizationId, (int) auth()->id(), $clientId);
        }

        return response()->noContent();
    }

    /**
     * المنظمة والتبويب من الطلب. المنظمة المرسَلة تُقبل إن كانت منظمة الجلسة
     * (تحقّق منها الوسيط) أو كان المستخدم عضواً فيها.
     *
     * @return array{0: int, 1: ?string} [organization_id أو 0 إن رُفضت, client_id]
     */
    private function clientContext(Request $request): array
    {
        $sessionOrganizationId = (int) $this->organizationId();
        $organizationId = (int) ($request->input('organization_id') ?: $sessionOrganizationId);
        $clientId = $request->input('client_id') ?: null;

        if ($organizationId && $organizationId !== $sessionOrganizationId) {
            $isMember = Team::where('organization_id', $organizationId)
                ->where('user_id', auth()->id())
                ->whereNull('deleted_at')
                ->exists();

            if (!$isMember) {
                $organizationId = 0;
            }
        }

        return [$organizationId, $clientId];
    }

    /**
     * 422 بالأخطاء إن كان الطلب غير سليم. التحقّق يدويّ لا validate(): معالج
     * الأخطاء يحوّل ValidationException في طلبات JSON إلى 500.
     */
    private function invalidClientContext(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return $validator->fails()
            ? response()->json(['success' => false, 'errors' => $validator->errors()], 422)
            : null;
    }
}
