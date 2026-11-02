<?php

namespace App\Jobs;

use App\Helpers\DateTimeHelper;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Organization;
use App\Models\Team;
use App\Services\AgentPerformanceService;
use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTicketAssignmentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 5;
    public $backoff = [1, 3, 5, 10, 15];
    public $uniqueFor = 120;

    protected $contactId;
    protected $organizationId;
    protected $isNewChat;

    public function __construct($contactId, $organizationId, $isNewChat = false)
    {
        $this->contactId = $contactId;
        $this->organizationId = $organizationId;
        $this->isNewChat = $isNewChat;
    }

    public function uniqueId(): string
    {
        return 'ticket-assignment:' . $this->contactId;
    }

    public function handle():?int
    {
        try {
            $settings = $this->getOrganizationSettings();

            if (!isset($settings->tickets) || !$settings->tickets->active) {
                return null;
            }

            return DB::transaction(function () use ($settings) {
                $ticket = ChatTicket::where('contact_id', $this->contactId)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if (!$ticket && $this->isNewChat) {
                    $ticket = $this->createTicket($settings);

                    return $ticket->assigned_to;
                }

                if ($ticket && $ticket->status === 'closed') {
                    $ticket = $this->reopenTicket($ticket, $settings);

                    return $ticket->assigned_to;
                }

                return null;
            }, 3);
        } catch (\Throwable $e) {
            Log::error('ProcessTicketAssignmentJob failed', [
                'organization_id' => $this->organizationId,
                'contact_id' => $this->contactId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * ✅ الحصول على إعدادات المنظمة من Cache
     */
    private function getOrganizationSettings()
    {
        return Cache::remember(
            "org_settings_{$this->organizationId}",
            1800, // 30 دقيقة
            function() {
                $org = Organization::find($this->organizationId);
                return json_decode($org->metadata);
            }
        );
    }

    /**
     * ✅ إنشاء تذكرة جديدة
     */
    private function createTicket($settings)
    {
        $assignedTo = $this->resolveAssignedAgent($settings);

        // ✅ إنشاء التذكرة
        // عند الإسناد التلقائي لوكيل محدد نضع assigned_seen = false حتى تظهر
        // للوكيل كمحادثة جديدة تحتاج فتحها.
        $assignedSeen = $assignedTo === null;

        try {
            $ticket = ChatTicket::withoutEvents(function () use ($assignedTo, $assignedSeen) {
                return ChatTicket::create([
                    'contact_id' => $this->contactId,
                    'assigned_to' => $assignedTo,
                    'status' => 'open',
                    'is_latest' => true,
                    'assigned_seen' => $assignedSeen,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // كاتبٌ آخر (صفحة المحادثات في ensureChatTicketsExist) أنشأ تذكرة
            // هذه الجهة قبلنا. القيد الفريد على «الأحدث» رفض الثانية — وهو المقصود:
            // كان يُنتج صفّين بـ is_latest = 1. نعتمد الموجودة بدل أن تسقط معالجة
            // رسالة وصلت فعلاً. MySQL يتراجع عن الجملة وحدها لا عن المعاملة، فنكمل.
            return ChatTicket::where('contact_id', $this->contactId)
                ->orderByDesc('is_latest')
                ->orderByDesc('id')
                ->first();
        }

        ChatTicket::syncLatestFlag($this->contactId, (int) $ticket->id);

        // ✅ Log التذكرة
        $ticketLogId = ChatTicketLog::insertGetId([
            'contact_id' => $this->contactId,
            'description' => 'Conversation was opened',
            'created_at' =>  now()
        ]);

        // ✅ Chat Log
        ChatLog::insert([
            'contact_id' => $this->contactId,
            'entity_type' => 'ticket',
            'entity_id' => $ticketLogId,
            'created_at' =>  now()
        ]);

        return $ticket;
    }

    /**
     * ✅ إعادة فتح تذكرة مغلقة
     */
    private function reopenTicket($ticket, $settings)
    {
        $reassignOnReopen = $settings->tickets->reassign_reopened_chats ?? false;
        $autoAssignment = $settings->tickets->auto_assignment ?? false;

        // ✅ إعادة التعيين إذا كانت مفعلة
        if ($reassignOnReopen) {
            if ($autoAssignment) {
                $ticket->assigned_to = $this->resolveAssignedAgent($settings);
                // إسناد جديد لوكيل عند إعادة الفتح: يظهر كمحادثة جديدة له.
                $ticket->assigned_seen = $ticket->assigned_to === null;
            } else {
                $ticket->assigned_to = null;
                $ticket->assigned_seen = true;
            }
        }

        // ✅ تحديث الحالة
        $ticket->status = 'open';
        $ticket->updated_at = now();
        $ticket->save();

        // ✅ Log إعادة الفتح
        $ticketLogId = ChatTicketLog::insertGetId([
            'contact_id' => $this->contactId,
            'description' => 'Conversation was moved from closed to open',
            'created_at' => now()
        ]);

        // ✅ Chat Log
        ChatLog::insert([
            'contact_id' => $this->contactId,
            'entity_type' => 'ticket',
            'entity_id' => $ticketLogId,
            'created_at' => now()
        ]);

        Log::info('Ticket reopened successfully', [
            'ticket_id' => $ticket->id,
            'contact_id' => $this->contactId,
            'assigned_to' => $ticket->assigned_to
        ]);

        return $ticket;
    }

    /**
     * Resolve assignee based on assignment_mode (auto / round_robin).
     */
    private function resolveAssignedAgent($settings): ?int
    {
        $mode = $settings->tickets->assignment_mode
            ?? (($settings->tickets->auto_assignment ?? false) ? 'auto' : 'manual');

        return match ($mode) {
            'auto' => $this->getLeastBusyAgent(),
            'round_robin' => $this->getNextRoundRobinAgent(),
            default => null,
        };
    }

    /**
     * من يحقّ له استلام محادثة الآن: المتصلون وحدهم.
     *
     * كان الإسناد يوزّع على كل أعضاء الفريق، فتصل المحادثة لموظف غير متصل
     * وتبقى بلا ردّ. إن لم يكن أحد متصلاً تبقى بلا موظف في Unassigned لمن يأخذها.
     *
     * null = الحضور غير مُتتبَّع في هذه المنظمة: النبضة لا تُسجَّل بلا ميزة
     * agent_performance، فلو رشّحنا بالمتصلين لما وصلت محادثة لأحد أبداً.
     *
     * @return list<int>|null
     */
    private function eligibleAgentIds(): ?array
    {
        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId, 'agent_performance')) {
            return null;
        }

        return (new AgentPerformanceService((int) $this->organizationId))->onlineUserIds();
    }

    private function getLeastBusyAgent()
    {
        $eligible = $this->eligibleAgentIds();
        if ($eligible === []) {
            return null;
        }

        // Always recompute so each new ticket is assigned to the currently least-busy agent.
        $agent = Team::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->when($eligible !== null, fn ($query) => $query->whereIn('user_id', $eligible))
            ->withCount(['tickets' => function ($query) {
                $query->where('status', 'open')
                    ->where('is_latest', true);
            }])
            ->orderBy('tickets_count', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        return $agent->user_id ?? null;
    }

    /**
     * Assign the next active, online team member in stable order (teams.id ASC), wrapping around.
     * Offline members are skipped, not dropped from the order, so the turn carries on past them.
     * Persists tickets.round_robin_last_user_id on the organization under a row lock.
     */
    private function getNextRoundRobinAgent(): ?int
    {
        $agentIds = Team::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('id', 'asc')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $eligible = $this->eligibleAgentIds();
        if ($eligible !== null) {
            $eligible = array_flip($eligible);
        }

        $isEligible = fn (int $userId) => $eligible === null || isset($eligible[$userId]);
        $candidates = array_values(array_filter($agentIds, $isEligible));

        // لا أحد متصلاً: نخرج قبل قفل صف المنظمة، ويبقى الدور حيث كان.
        if ($candidates === []) {
            return null;
        }

        $organization = Organization::where('id', $this->organizationId)
            ->lockForUpdate()
            ->first();

        if (!$organization) {
            return $candidates[0];
        }

        $metadata = $organization->metadata
            ? (json_decode($organization->metadata, true) ?: [])
            : [];

        $lastUserId = isset($metadata['tickets']['round_robin_last_user_id'])
            ? (int) $metadata['tickets']['round_robin_last_user_id']
            : null;

        $start = 0;
        if ($lastUserId !== null) {
            $index = array_search($lastUserId, $agentIds, true);
            if ($index !== false) {
                $start = $index + 1;
            }
        }

        $count = count($agentIds);
        $nextUserId = null;
        for ($step = 0; $step < $count; $step++) {
            $candidate = $agentIds[($start + $step) % $count];
            if ($isEligible($candidate)) {
                $nextUserId = $candidate;
                break;
            }
        }

        $metadata['tickets'] = $metadata['tickets'] ?? [];
        $metadata['tickets']['round_robin_last_user_id'] = $nextUserId;
        $organization->metadata = json_encode($metadata);
        $organization->save();

        Cache::forget("org_settings_{$this->organizationId}");

        return $nextUserId;
    }

	public function failed(\Throwable $exception)
    {
        Log::error('ProcessTicketAssignmentJob permanently failed', [
            'organization_id' => $this->organizationId,
            'contact_id' => $this->contactId,
            'error' => $exception->getMessage()
        ]);
    }
	
}
