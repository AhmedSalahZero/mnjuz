<?php

namespace App\Services\Chat;

use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AgentPerformanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * خطوة «تحويل إلى موظف» في الأتمتة: تُسند المحادثة لموظف من قائمة يختارها
 * التاجر (الدعم ← أحمد، المبيعات ← فواز وعلي)، وتقول للتدفّق أيّ مخرج يسلك.
 *
 * القاعدة (قرار المالك):
 *   - المتاح = متصل الآن (نافذة الحضور نفسها التي تعرضها صفحة الأداء).
 *   - من المتاحين: الأقلّ انشغالاً (تعريف الإسناد التلقائي نفسه).
 *   - لا أحد متاحاً: تُسند مع ذلك للأقلّ انشغالاً منهم ليجدها حين يعود،
 *     ويسلك التدفّق مخرج «غير متاح» ليرسل للعميل رسالته.
 *
 * الإسناد يكتب على تذكرة المحادثة الأخيرة. بلا تذاكر مفعّلة (أو بلا تذكرة
 * بعد) لا يُكتب شيء — ولا سطر «أُسندت إلى …» كاذب في المحادثة.
 */
class ConversationHandoff
{
    public const AVAILABLE = 'available';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<int|string>  $userIds  من اختارهم التاجر في الخطوة.
     * @return array{branch: string, assigned_to: ?int, changed: bool}
     */
    public function transfer(Contact $contact, array $userIds): array
    {
        $organizationId = (int) $contact->organization_id;
        $candidates = $this->candidates($organizationId, $userIds);

        // كلّ من اختارهم غادر الفريق أو عُلّق: لا أحد يُسند إليه.
        if ($candidates === []) {
            Log::info('Flow handoff found no active member to assign', [
                'organization_id' => $organizationId,
                'contact_id' => $contact->id,
            ]);

            return ['branch' => self::UNAVAILABLE, 'assigned_to' => null, 'changed' => false];
        }

        $online = array_values(array_intersect(
            $candidates,
            (new AgentPerformanceService($organizationId))->onlineUserIds()
        ));

        $available = $online !== [];
        $pool = $available ? $online : $candidates;
        $branch = $available ? self::AVAILABLE : self::UNAVAILABLE;

        $organization = Organization::find($organizationId);

        // بلا تذاكر لا إسناد أصلاً — كل موظف يرى كل المحادثات. المسار يتبع
        // الحضور كما هو.
        if (!$organization || !$organization->getTicketingActive()) {
            return ['branch' => $branch, 'assigned_to' => null, 'changed' => false];
        }

        [$assignedTo, $changed] = $this->assignWithinPool($contact, $organizationId, $pool);

        if ($changed && $assignedTo !== null) {
            $this->logActivity($contact, $organizationId, $assignedTo);
        }

        return ['branch' => $branch, 'assigned_to' => $assignedTo, 'changed' => $changed];
    }

    /**
     * من اختارهم التاجر وما زالوا أعضاء نشطين في المنشأة. يُعاد الفحص عند كل
     * تشغيل: من غادر بعد حفظ التدفّق لا يُسند إليه.
     *
     * @return list<int>
     */
    private function candidates(int $organizationId, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            fn (int $id) => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        return Team::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereIn('user_id', $ids)
            ->whereHas('user')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * الكتابة تحت قفل صفّ التذكرة: رسالتان سريعتان من العميل تمرّان بالخطوة
     * معاً، والثانية ترى ما كتبته الأولى.
     *
     * من يحمل المحادثة وهو ضمن المرشّحين تبقى معه: الإسناد التلقائي سبق
     * التدفّق إليه، أو مرّت الخطوة قبل لحظة — نقلها عنه بلا سبب ضجيج.
     *
     * @param  list<int>  $pool
     * @return array{0: ?int, 1: bool} [المسند إليه، هل تغيّر]
     */
    private function assignWithinPool(Contact $contact, int $organizationId, array $pool): array
    {
        return DB::transaction(function () use ($contact, $organizationId, $pool) {
            $ticket = ChatTicket::where('contact_id', $contact->id)
                ->where('is_latest', true)
                ->lockForUpdate()
                ->first();

            // التذكرة تُفتح مع الرسالة الواردة؛ غيابها يعني ألّا شيء نُسنده.
            if (!$ticket) {
                return [null, false];
            }

            $current = $ticket->assigned_to !== null ? (int) $ticket->assigned_to : null;

            if ($current !== null && in_array($current, $pool, true)) {
                return [$current, false];
            }

            $assignee = Team::leastBusyUserId($organizationId, $pool);
            $user = $assignee !== null ? User::find($assignee) : null;

            if (!$user) {
                return [null, false];
            }

            // المساعد نفسه الذي يستعمله الإسناد اليدوي (الويب والتطبيق): نفس
            // سطر السجلّ ونفس «جديدة» حتى يفتحها.
            $contact->assignToUserThroughTicket($user);

            return [(int) $user->id, true];
        });
    }

    /** في مهمّة مؤجّلة لا جلسة ولا مستخدم: المنشأة صريحة، والفاعل «الأتمتة». */
    private function logActivity(Contact $contact, int $organizationId, int $assignedTo): void
    {
        $user = User::find($assignedTo);

        ActivityLogger::log(
            ActivityLogger::TICKET_ASSIGNED,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id,
            [
                'assigned_to' => $user ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email) : null,
                'via' => 'flow',
            ],
            $organizationId,
            null,
            __('Automation')
        );
    }
}
