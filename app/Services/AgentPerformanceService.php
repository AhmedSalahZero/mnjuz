<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * يحسب مقاييس أداء الموظفين لمنظمة ضمن نطاق زمني محدد.
 * المصادر: chats (رسائل الموظف)، chat_tickets (الإسناد/الإغلاق/زمن الحل)،
 * agent_activity (الوقت النشط وآخر نشاط). أول زمن استجابة يُحسب بمزاوجة كل
 * رسالة واردة مع أول رد صادر من موظف بعدها لنفس جهة الاتصال.
 */
class AgentPerformanceService
{
    private int $organizationId;

    public function __construct(int $organizationId)
    {
        $this->organizationId = $organizationId;
    }

    public function metrics(Carbon $from, Carbon $to): array
    {
        $org = $this->organizationId;

        $members = DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.organization_id', $org)
            ->whereNull('teams.deleted_at')
            ->get([
                'teams.user_id',
                'teams.role',
                'teams.status',
                'users.first_name',
                'users.last_name',
                'users.email',
            ]);

        $messagesSent = DB::table('chats')
            ->where('organization_id', $org)
            ->where('type', 'outbound')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id')
            ->pluck(DB::raw('count(*)'), 'user_id');

        $ticketsAssigned = DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->where('contacts.organization_id', $org)
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereBetween('chat_tickets.created_at', [$from, $to])
            ->groupBy('chat_tickets.assigned_to')
            ->pluck(DB::raw('count(*)'), 'chat_tickets.assigned_to');

        $ticketsClosed = DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->where('contacts.organization_id', $org)
            ->where('chat_tickets.status', 'closed')
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereBetween('chat_tickets.updated_at', [$from, $to])
            ->groupBy('chat_tickets.assigned_to')
            ->pluck(DB::raw('count(*)'), 'chat_tickets.assigned_to');

        $resolution = DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->where('contacts.organization_id', $org)
            ->where('chat_tickets.status', 'closed')
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereBetween('chat_tickets.updated_at', [$from, $to])
            ->groupBy('chat_tickets.assigned_to')
            ->pluck(DB::raw('AVG(TIMESTAMPDIFF(SECOND, chat_tickets.created_at, chat_tickets.updated_at))'), 'chat_tickets.assigned_to');

        $activity = DB::table('agent_activity')
            ->where('organization_id', $org)
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('user_id')
            ->get([
                'user_id',
                DB::raw('SUM(active_seconds) as active_seconds'),
            ])
            ->keyBy('user_id');

        $lastActivity = DB::table('agent_activity')
            ->where('organization_id', $org)
            ->groupBy('user_id')
            ->pluck(DB::raw('MAX(last_ping_at)'), 'user_id');

        $firstResponse = $this->firstResponseAverages($from, $to);

        $now = Carbon::now();
        $rows = [];

        foreach ($members as $m) {
            $userId = (int) $m->user_id;
            $lastPing = $lastActivity[$userId] ?? null;
            $online = $lastPing ? Carbon::parse($lastPing)->gt($now->copy()->subMinutes(2)) : false;

            $rows[] = [
                'user_id' => $userId,
                'name' => trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')) ?: $m->email,
                'email' => $m->email,
                'role' => $m->role,
                'messages_sent' => (int) ($messagesSent[$userId] ?? 0),
                'tickets_assigned' => (int) ($ticketsAssigned[$userId] ?? 0),
                'tickets_closed' => (int) ($ticketsClosed[$userId] ?? 0),
                'avg_first_response_seconds' => isset($firstResponse[$userId]) ? (int) round($firstResponse[$userId]) : null,
                'avg_resolution_seconds' => isset($resolution[$userId]) ? (int) round($resolution[$userId]) : null,
                'active_seconds' => (int) ($activity[$userId]->active_seconds ?? 0),
                'last_activity_at' => $lastPing,
                'online' => $online,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'agents' => $rows,
        ];
    }

    /**
     * متوسط أول زمن استجابة لكل موظف: نزاوج كل رسالة واردة مع أول رد صادر من
     * موظف بعدها لنفس جهة الاتصال ضمن النطاق.
     *
     * @return array<int, float>  user_id => متوسط الثواني
     */
    private function firstResponseAverages(Carbon $from, Carbon $to): array
    {
        $chats = DB::table('chats')
            ->where('organization_id', $this->organizationId)
            ->whereIn('type', ['inbound', 'outbound'])
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('contact_id')
            ->orderBy('created_at')
            ->get(['contact_id', 'type', 'user_id', 'created_at']);

        $sums = [];
        $counts = [];
        $pendingInboundAt = [];

        foreach ($chats as $chat) {
            $contactId = $chat->contact_id;
            if ($chat->type === 'inbound') {
                // نحتفظ بأقدم رسالة واردة غير مُجاب عنها لكل جهة اتصال
                if (!isset($pendingInboundAt[$contactId])) {
                    $pendingInboundAt[$contactId] = $chat->created_at;
                }
                continue;
            }

            // رد صادر من موظف
            if ($chat->user_id && isset($pendingInboundAt[$contactId])) {
                $seconds = Carbon::parse($chat->created_at)->diffInSeconds(Carbon::parse($pendingInboundAt[$contactId]));
                $uid = (int) $chat->user_id;
                $sums[$uid] = ($sums[$uid] ?? 0) + $seconds;
                $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            }
            // أي رد صادر يُنهي انتظار الرسالة الواردة الحالية
            unset($pendingInboundAt[$contactId]);
        }

        $averages = [];
        foreach ($sums as $uid => $sum) {
            $averages[$uid] = $counts[$uid] > 0 ? $sum / $counts[$uid] : 0;
        }

        return $averages;
    }

    /**
     * تسجيل نبضة نشاط للموظف الحالي: يضيف الفترة المنقضية (بحدّ أقصى) إلى
     * الوقت النشط لليوم ويحدّث آخر نبضة.
     */
    public static function recordHeartbeat(int $organizationId, int $userId): void
    {
        $today = Carbon::now()->toDateString();
        $now = Carbon::now();
        $maxIncrement = 120; // حدّ أقصى للفترة المضافة لكل نبضة (ثوانٍ)

        $existing = DB::table('agent_activity')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('activity_date', $today)
            ->first();

        if (!$existing) {
            DB::table('agent_activity')->insert([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'activity_date' => $today,
                'active_seconds' => 0,
                'last_ping_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $increment = 0;
        if ($existing->last_ping_at) {
            $elapsed = $now->diffInSeconds(Carbon::parse($existing->last_ping_at));
            $increment = min($elapsed, $maxIncrement);
        }

        DB::table('agent_activity')
            ->where('id', $existing->id)
            ->update([
                'active_seconds' => $existing->active_seconds + $increment,
                'last_ping_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
