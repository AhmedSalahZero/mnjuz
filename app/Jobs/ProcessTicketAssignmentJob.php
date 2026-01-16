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

class ProcessTicketAssignmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 2;
    public $backoff = [10, 30];

    protected $contactId;
    protected $organizationId;
    protected $isNewChat;

    public function __construct($contactId, $organizationId, $isNewChat = false)
    {
        $this->contactId = $contactId;
        $this->organizationId = $organizationId;
        $this->isNewChat = $isNewChat;
    }

    public function handle()
    {
        try {
            // ✅ Cache للإعدادات
            $settings = $this->getOrganizationSettings();

            // ✅ التحقق من تفعيل نظام التذاكر
            if(!isset($settings->tickets) || !$settings->tickets->active) {
                return;
            }

            // ✅ البحث عن التذكرة الموجودة
            $ticket = ChatTicket::where('contact_id', $this->contactId)->first();

            // ✅ إنشاء تذكرة جديدة أو إعادة فتح
            DB::transaction(function() use ($ticket, $settings) {
                if(!$ticket && $this->isNewChat) {
                    $this->createTicket($settings);
                } 
                else if($ticket && $ticket->status === 'closed') {
                    $this->reopenTicket($ticket, $settings);
                }
            });

        } catch (\Exception $e) {
            Log::error('ProcessTicketAssignmentJob failed', [
                'organization_id' => $this->organizationId,
                'contact_id' => $this->contactId,
                'error' => $e->getMessage()
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
        $ticket = ChatTicket::create([
            'contact_id' => $this->contactId,
            'assigned_to' => $assignedTo,
            'status' => 'open',
            'created_at' =>  now(),
            'updated_at' =>  now(),
        ]);

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

        Log::info('Ticket created successfully', [
            'ticket_id' => $ticket->id,
            'contact_id' => $this->contactId,
            'assigned_to' => $assignedTo
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
        if($reassignOnReopen) {
            if($autoAssignment) {
                $ticket->assigned_to = $this->getLeastBusyAgent();
            } else {
                $ticket->assigned_to = null;
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
                    ->withCount(['tickets' => function($query) {
                        $query->where('status', 'open');
                    }])
                    ->orderBy('tickets_count', 'asc')
                    ->first();

                if($agent) {
                    Log::info('Least busy agent found', [
                        'agent_id' => $agent->user_id,
                        'tickets_count' => $agent->tickets_count
                    ]);
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
