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
    /**
     * نافذة اعتبار الموظف متصلاً. كانت دقيقتين والنبضة كل دقيقة، فنبضة
     * واحدة تتأخّر — والمتصفّح يخنق مؤقّتات التبويبات الخلفية — تكفي
     * لإظهاره غير متصل.
     */
    private const ONLINE_WINDOW_MINUTES = 5;

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

        // ملاحظة: الأعمدة المجمّعة تحتاج alias صريحاً مع select() قبل pluck()؛
        // pluck() يقصّ التعبير الخام عند آخر نقطة فيبحث عن عمود غير موجود.
        // نجمع العدّ وآخر إرسال في مرور واحد: الثاني دليل حضورٍ مستقلّ عن النبضة.
        $sentStats = DB::table('chats')
            ->where('organization_id', $org)
            ->where('type', 'outbound')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id')
            ->select([
                'user_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('MAX(created_at) as last_action_at'),
            ])
            ->get()
            ->keyBy('user_id');

        $messagesSent = $sentStats->map(fn ($r) => $r->total);

        $ticketsAssigned = DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->where('contacts.organization_id', $org)
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereBetween('chat_tickets.created_at', [$from, $to])
            ->groupBy('chat_tickets.assigned_to')
            ->select(['chat_tickets.assigned_to as user_id', DB::raw('COUNT(*) as total')])
            ->pluck('total', 'user_id');

        $ticketsClosed = DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->where('contacts.organization_id', $org)
            ->where('chat_tickets.status', 'closed')
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereBetween('chat_tickets.updated_at', [$from, $to])
            ->groupBy('chat_tickets.assigned_to')
            ->select(['chat_tickets.assigned_to as user_id', DB::raw('COUNT(*) as total')])
            ->pluck('total', 'user_id');

        $resolution = DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->where('contacts.organization_id', $org)
            ->where('chat_tickets.status', 'closed')
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereBetween('chat_tickets.updated_at', [$from, $to])
            ->groupBy('chat_tickets.assigned_to')
            ->select([
                'chat_tickets.assigned_to as user_id',
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, chat_tickets.created_at, chat_tickets.updated_at)) as avg_seconds'),
            ])
            ->pluck('avg_seconds', 'user_id');

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
            ->select(['user_id', DB::raw('MAX(last_ping_at) as last_ping_at')])
            ->pluck('last_ping_at', 'user_id');

        $firstResponse = $this->firstResponseAverages($from, $to);

        $now = Carbon::now();
        $rows = [];

        foreach ($members as $m) {
            $userId = (int) $m->user_id;
            $lastPing = $lastActivity[$userId] ?? null;

            // النبضة تأتي من الويب وحده، فمن يعمل من التطبيق أو من تبويب في
            // الخلفية كان يظهر «غير متصل» وهو يرسل الرسائل. فِعلٌ مُثبت في
            // قاعدة البيانات دليلُ حضورٍ لا يقلّ عن النبضة، فنأخذ الأحدث منهما.
            $lastAction = $sentStats[$userId]->last_action_at ?? null;

            $lastSeen = $lastPing;
            if ($lastAction && (!$lastSeen || $lastAction > $lastSeen)) {
                $lastSeen = $lastAction;
            }

            $online = $lastSeen
                ? Carbon::parse($lastSeen)->gt($now->copy()->subMinutes(self::ONLINE_WINDOW_MINUTES))
                : false;

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
                'last_activity_at' => $lastSeen,
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
        // cursor() بدل get() حتى لا تُحمَّل رسائل المنظمة كاملةً في الذاكرة دفعةً واحدة.
        $chats = DB::table('chats')
            ->where('organization_id', $this->organizationId)
            ->whereIn('type', ['inbound', 'outbound'])
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('contact_id')
            ->orderBy('created_at')
            ->select(['contact_id', 'type', 'user_id', 'created_at'])
            ->cursor();

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
    /**
     * @param  bool  $countTime  هل تُحتسب الفترة المنقضية ضمن الوقت النشط؟
     *   النبضة تؤدّي وظيفتين مختلفتين: إثبات الحضور، وقياس الوقت النشط. الموظف
     *   الذي تبويبه مفتوح في الخلفية حاضرٌ لكنه غير نشط، فنُثبت حضوره ولا نزيد
     *   وقته. خلط الوظيفتين هو ما كان يُظهر الموظفين العاملين «غير متصلين».
     */
    public static function recordHeartbeat(int $organizationId, int $userId, bool $countTime = true): void
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
            // القراءة ثم الإدراج سباق: الموظف يفتح المنصة في تبويبين — أو ينبض
            // تبويب بينما آخر لم ينتهِ — فيرى كلاهما «لا صفّ» ويُدرج، فيسقط
            // الثاني على القيد الفريد (organization_id, user_id, activity_date).
            // insertOrIgnore يبتلع التصادم، ثم نُكمل كتحديث للصفّ الذي سبقنا.
            $inserted = DB::table('agent_activity')->insertOrIgnore([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'activity_date' => $today,
                'active_seconds' => 0,
                'last_ping_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted) {
                return;
            }

            $existing = DB::table('agent_activity')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('activity_date', $today)
                ->first();

            if (!$existing) {
                return;
            }
        }

        $increment = 0;
        if ($countTime && $existing->last_ping_at) {
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
