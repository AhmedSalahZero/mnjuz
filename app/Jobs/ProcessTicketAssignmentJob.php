<?php

namespace App\Jobs;

use App\Helpers\DateTimeHelper;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        $assignedTo = null;

        // ✅ التعيين التلقائي
        if($settings->tickets->auto_assignment) {
            $assignedTo = $this->getLeastBusyAgent();
        }

        // ✅ إنشاء التذكرة
        // عند الإسناد التلقائي لوكيل محدد نضع assigned_seen = false حتى تظهر
        // للوكيل كمحادثة جديدة تحتاج فتحها.
        $assignedSeen = $assignedTo === null;
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

        // Log::info('Ticket created successfully', [
        //     'ticket_id' => $ticket->id,
        //     'contact_id' => $this->contactId,
        //     'assigned_to' => $assignedTo
        // ]);

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
        if($reassignOnReopen) {
            if($autoAssignment) {
                $ticket->assigned_to = $this->getLeastBusyAgent();
                // إسناد جديد لوكيل عند إعادة الفتح: يظهر كمحادثة جديدة له.
                $ticket->assigned_seen = $ticket->assigned_to === null;
            } else {
                $ticket->assigned_to = null;
                $ticket->assigned_seen = true;
            }
        }

        // ✅ تحديث الحالة
        $ticket->status = 'open';
        $ticket->updated_at =  now();
        $ticket->save();

        // ✅ Log إعادة الفتح
        $ticketLogId = ChatTicketLog::insertGetId([
            'contact_id' => $this->contactId,
            'description' => 'Conversation was moved from closed to open',
            'created_at' =>  now()
        ]);

        // ✅ Chat Log
        ChatLog::insert([
            'contact_id' => $this->contactId,
            'entity_type' => 'ticket',
            'entity_id' => $ticketLogId,
            'created_at' =>  now()
        ]);

        Log::info('Ticket reopened successfully', [
            'ticket_id' => $ticket->id,
            'contact_id' => $this->contactId,
            'assigned_to' => $ticket->assigned_to
        ]);

        return $ticket;
    }
 private function getLeastBusyAgent()
    {
        // ✅ Cache لمدة 5 دقائق
        return Cache::remember(
            "least_busy_agent_{$this->organizationId}",
            300,
            function() {
                $agent = Team::where('organization_id', $this->organizationId)
                    ->whereNull('deleted_at')
                    ->where('status', 'active')
                    ->withCount(['tickets' => function($query) {
                        $query->where('status', 'open')
                              ->where('is_latest', true);
                    }])
                    ->orderBy('tickets_count', 'asc')
                    ->first();

                if($agent) {
                    // Log::info('Least busy agent found', [
                    //     'agent_id' => $agent->user_id,
                    //     'tickets_count' => $agent->tickets_count
                    // ]);
                }

                return $agent->user_id ?? null;
            }
        );
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
