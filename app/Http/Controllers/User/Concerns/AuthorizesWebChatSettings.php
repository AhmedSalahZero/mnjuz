<?php

namespace App\Http\Controllers\User\Concerns;

use App\Models\Team;
use App\Services\WebChat\WebChatWidgetService;
use App\Support\OrganizationRole;

/**
 * إعدادات المحادثة المباشرة (الـ widget وأسئلته الشائعة) لمالك المنشأة
 * ومديريها: الإضافة في الاشتراك، والموظّف لا يغيّرها (403).
 */
trait AuthorizesWebChatSettings
{
    protected function webChatOrganizationId(): int
    {
        $organizationId = (int) session()->get('current_organization');

        if (!$organizationId || !WebChatWidgetService::moduleEnabled($organizationId)) {
            abort(403);
        }

        $role = Team::where('organization_id', $organizationId)->where('user_id', auth()->id())->value('role');

        if (OrganizationRole::isAgent($role)) {
            abort(403);
        }

        return $organizationId;
    }
}
