<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Addon;
use App\Models\Shortcut;
use App\Services\SubscriptionService;
use App\Support\OrganizationRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class ShortcutController extends BaseController
{
    private function organizationId()
    {
        return session()->get('current_organization');
    }

    private function assertFeatureEnabled(): void
    {
        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId(), 'shortcuts')) {
            abort(403, __('This feature is not available in your plan.'));
        }
    }

    private function canManageCompany(): bool
    {
        $role = auth()->user()->getRoleNameForOrganization($this->organizationId());
        if ($role === '') {
            $role = OrganizationRole::OWNER;
        }

        return OrganizationRole::isPrivileged($role);
    }

    /**
     * صفحة الإعدادات: تعرض اختصارات الموظف الخاصة + اختصارات الشركة.
     */
    public function index(Request $request)
    {
        $this->assertFeatureEnabled();

        $organizationId = $this->organizationId();
        $userId = auth()->id();

        $shortcuts = Shortcut::query()
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($userId) {
                $q->where('scope', 'company')
                    ->orWhere(function ($inner) use ($userId) {
                        $inner->where('scope', 'personal')->where('user_id', $userId);
                    });
            })
            ->orderBy('id')
            ->get(['id', 'command', 'message', 'scope', 'user_id']);

        return Inertia::render('User/Settings/Shortcuts', [
            'title' => __('Settings'),
            'shortcuts' => $shortcuts,
            'canManageCompany' => $this->canManageCompany(),
            'currentUserId' => $userId,
            'modules' => Addon::get(),
        ]);
    }

    /**
     * حفظ الاختصارات: يستبدل ما يملكه المستخدم من اختصارات قابلة للإدارة.
     */
    public function sync(Request $request)
    {
        $this->assertFeatureEnabled();

        $organizationId = $this->organizationId();
        $userId = auth()->id();
        $canManageCompany = $this->canManageCompany();

        $validator = Validator::make($request->all(), [
            'shortcuts' => 'present|array',
            'shortcuts.*.command' => 'required|string|max:120',
            'shortcuts.*.message' => 'required|string|max:5000',
            'shortcuts.*.scope' => 'required|in:personal,company',
            'shortcuts.*.id' => 'nullable|integer',
        ]);
        $validator->validate();

        $incoming = $request->input('shortcuts', []);

        // الاختصارات القابلة للإدارة بواسطة هذا المستخدم
        $manageable = Shortcut::query()
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($userId, $canManageCompany) {
                $q->where(function ($p) use ($userId) {
                    $p->where('scope', 'personal')->where('user_id', $userId);
                });
                if ($canManageCompany) {
                    $q->orWhere('scope', 'company');
                }
            })
            ->get()
            ->keyBy('id');

        $keptIds = [];

        foreach ($incoming as $row) {
            // الوكيل لا يستطيع إنشاء/تعديل اختصارات الشركة؛ نحوّلها لشخصية.
            $scope = ($row['scope'] === 'company' && $canManageCompany) ? 'company' : 'personal';
            $command = ltrim(trim($row['command']), '/');
            if ($command === '') {
                continue;
            }

            $id = $row['id'] ?? null;
            if ($id && $manageable->has($id)) {
                $shortcut = $manageable->get($id);
                $shortcut->update([
                    'command' => $command,
                    'message' => $row['message'],
                    'scope' => $scope,
                ]);
                $keptIds[] = (int) $id;
            } else {
                Shortcut::create([
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'scope' => $scope,
                    'command' => $command,
                    'message' => $row['message'],
                    'created_by' => $userId,
                ]);
            }
        }

        // حذف الاختصارات القابلة للإدارة التي لم تعد موجودة في الطلب
        $toDelete = $manageable->keys()->diff($keptIds);
        if ($toDelete->isNotEmpty()) {
            Shortcut::whereIn('id', $toDelete->all())->delete();
        }

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Settings updated successfully'),
        ]);
    }

    /**
     * قائمة الاختصارات المتاحة للاستخدام داخل صندوق الرد (JSON).
     */
    public function available(Request $request)
    {
        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId(), 'shortcuts')) {
            return response()->json(['shortcuts' => []]);
        }

        $shortcuts = Shortcut::availableFor($this->organizationId(), auth()->id())
            ->orderBy('command')
            ->get(['command', 'message', 'scope']);

        return response()->json(['shortcuts' => $shortcuts]);
    }
}
