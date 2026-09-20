<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Services\SubscriptionService;
use App\Support\OrganizationRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits organization "agent" role to approved areas (conversations, contacts, campaigns, templates, support, devices).
 */
class RestrictOrganizationAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'user') {
            return $next($request);
        }

        $organizationId = session('current_organization');
        if (! $organizationId) {
            return $next($request);
        }

        $team = Team::where('organization_id', $organizationId)->where('user_id', $user->id)->first();
        if (! $team || ! OrganizationRole::isAgent($team->role)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if ($this->isAllowedForAgent($path)) {
            return $next($request);
        }

        if ($path === 'dashboard' || str_starts_with($path, 'dashboard/')) {
            return redirect('/chats');
        }

        if ($path === 'settings/m' || str_starts_with($path, 'settings/m/')) {
            return redirect('/settings/devices');
        }

        // اشتراك منتهٍ: الموظّف يُقاد إلى /billing ثم يُمنع منها، فيقرأ «ليس
        // لديك صلاحية» ويظنّ أن حسابه تعطّل — والسبب شيء آخر لا حيلة له فيه.
        //
        // فنقول له ما وقع فعلاً، ومن يستطيع تجديده. رُصد على منشأة انتهى
        // اشتراكها فوقفت موظّفتها أمام رسالة لا تدلّها على شيء.
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->view('errors.subscription-expired', [
                'renewers' => $this->whoCanRenew((int) $organizationId),
            ], 403);
        }

        abort(403, __('You do not have permission to access this section.'));
    }

    /**
     * من يملك تجديد الاشتراك: المالك والمديرون — وهم من تفتح لهم صفحة
     * الفوترة (CheckClientRole يمنع الموظّف وحده).
     *
     * @return array<int, array{name: string, email: string, role: string}>
     */
    private function whoCanRenew(int $organizationId): array
    {
        return Team::where('teams.organization_id', $organizationId)
            ->whereNull('teams.deleted_at')
            ->whereIn('teams.role', OrganizationRole::privilegedRoles())
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->whereNull('users.deleted_at')
            ->orderByRaw("FIELD(teams.role, 'owner', 'manager')")
            ->get(['users.first_name', 'users.last_name', 'users.email', 'teams.role'])
            ->map(fn ($row) => [
                'name' => trim($row->first_name . ' ' . $row->last_name),
                'email' => $row->email,
                'role' => $row->role,
            ])
            ->all();
    }

    private function isAllowedForAgent(string $path): bool
    {
        $allowedPrefixes = [
            'chats',
            'chat',
            'tickets',
            'notes',
            'automation/contact',
            'contacts',
            'contact-groups',
            'contact-categories',
            'campaigns',
            'resend-all-failed-campaigns',
            'templates',
            'support',
            'shortcuts',
            // نبضة النشاط فقط — صفحة تقرير /performance تبقى محجوبة عن الموظف.
            'performance/heartbeat',
            'settings/shortcuts',
            'settings/devices',
            'settings/device',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
            // e.g. /chats-load-more (not under /chats/{uuid})
            if ($prefix === 'chats' && str_starts_with($path, 'chats-')) {
                return true;
            }
        }

        return false;
    }
}
