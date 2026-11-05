<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // ملاحظة: الأعمدة المجمّعة تحتاج alias صريحاً مع select() قبل pluck()؛
        // pluck() يقصّ التعبير الخام عند آخر نقطة فيبحث عن عمود غير موجود.
        $messagesSent = DB::table('chats')
            ->where('organization_id', $org)
            ->where('type', 'outbound')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id')
            ->select(['user_id', DB::raw('COUNT(*) as total')])
            ->pluck('total', 'user_id');

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

        $at = AgentPresence::clock();
        $live = AgentPresence::liveMap($org);
        $rows = [];

        foreach ($members as $m) {
            $userId = (int) $m->user_id;
            $lastSeen = self::latestOf($lastActivity[$userId] ?? null, $live[$userId] ?? null);

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
                'online' => isset($live[$userId]),
                'at' => $at,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'agents' => $rows,
        ];
    }

    /** الأحدث من وقتين بصيغة قاعدة البيانات (Y-m-d H:i:s تُقارَن نصّاً). */
    private static function latestOf(?string $a, ?string $b): ?string
    {
        if ($b && (!$a || $b > $a)) {
            return $b;
        }

        return $a;
    }

    /**
     * حالة الاتصال الآنية لأعضاء المنظمة — بلا بقية المقاييس.
     *
     * المتصل من له عميل حيّ ({@see AgentPresence}) — تبويب أو تطبيق ينبض. كانت
     * رسالةٌ صادرة باسمه تُحتسب حضوراً، فظهر منشئ الحملة «متصلاً» طوال إرسالها
     * ولو في منتصف الليل: رسائل الحملة تُسجَّل باسمه.
     *
     * `at` لحظة القراءة (قبلها لا بعدها): حدثٌ لحظي أحدث منها يغلبها عند الدمج.
     *
     * @return list<array{user_id: int, online: bool, last_activity_at: ?string, at: int}>
     */
    public function presence(): array
    {
        $at = AgentPresence::clock();

        $memberIds = DB::table('teams')
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->pluck('user_id');

        $live = AgentPresence::liveMap($this->organizationId);

        // آخر نشاط لمن ليس متصلاً: آخر نبضة (من أمس فصاعداً — الصفحة تحمل الأقدم
        // من المقاييس، والدمج لا يُرجع آخر نشاط إلى الوراء).
        $pings = DB::table('agent_activity')
            ->where('organization_id', $this->organizationId)
            ->where('activity_date', '>=', Carbon::now()->subDay()->toDateString())
            ->groupBy('user_id')
            ->select(['user_id', DB::raw('MAX(last_ping_at) as last_ping_at')])
            ->pluck('last_ping_at', 'user_id');

        $rows = [];
        foreach ($memberIds as $userId) {
            $userId = (int) $userId;

            $rows[] = [
                'user_id' => $userId,
                'online' => isset($live[$userId]),
                'last_activity_at' => self::latestOf($pings[$userId] ?? null, $live[$userId] ?? null),
                'at' => $at,
            ];
        }

        return $rows;
    }

    /**
     * من هو متصل الآن في المنظمة — بالتعريف نفسه الذي تعرضه صفحة الأداء.
     *
     * الإسناد التلقائي يعتمد عليها، فلو اختلف تعريفه عن الصفحة لرأى المدير
     * موظفاً «غير متصل» تصله المحادثات. لا تتحقّق من العضوية: من يُسنَد إليه
     * يُختار من أعضاء الفريق أصلاً.
     *
     * @return list<int>
     */
    public function onlineUserIds(): array
    {
        return array_map('intval', array_keys(AgentPresence::liveMap($this->organizationId)));
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
            // التاريخ المستورد من منصّة أخرى لم يُجب عنه أحد هنا: واردة مستوردة
            // بلا ردّ تليها رسالة حقيقية كانت ستُحسب زمن ردّ بالأشهر.
            ->whereNull('chat_import_id')
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
     * إثبات حضور الموظف في منظمة الآن، من الخادم، دون انتظار نبضة المتصفّح.
     *
     * الحضور كان يُثبَت من النبضة وحدها، وهي تنطلق بعد أن تنتهي صفحة المنظمة
     * الجديدة من التحميل. فمن يدخل أو يبدّل شركته يبقى «غير متصل» في صفحة
     * الأداء طوال هذه الفترة. فنثبّته عند الدخول والتبديل نفسيهما.
     *
     * لا يزيد الوقت النشط (الحضور غير النشاط)، ولا يرمي أبداً: تعذّر التسجيل
     * لا يجوز أن يُفشل تسجيل دخول أو تبديل منظمة.
     *
     * @param  string  $platform  AgentPresence::WEB أو AgentPresence::MOBILE
     * @param  ?string  $clientId  العميل الحقيقي إن عُرف (توكن التطبيق). غيابه
     *   يعني عميلاً مؤقّتاً باسم الدخول ينقضي وحده بعد النافذة.
     * @param  ?string  $sessionKey  جلسة الويب أو التوكن، ليُسقطه الخروج منها.
     */
    public static function markPresent(
        int $organizationId,
        int $userId,
        string $platform = AgentPresence::WEB,
        ?string $clientId = null,
        ?string $sessionKey = null,
    ): void {
        if ($organizationId <= 0 || $userId <= 0) {
            return;
        }

        try {
            // عضو في المنظمة فعلاً: معرّف «آخر منظمة» قد يكون قديماً، ولا نكتب
            // نشاطاً لمن لم يعد منها.
            $isMember = DB::table('teams')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->exists();

            if (!$isMember || !SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'agent_performance')) {
                return;
            }

            self::recordHeartbeat($organizationId, $userId, false);

            // عميل مؤقّت باسم الدخول: يُظهره متصلاً قبل أن تنبض صفحته، وينقضي
            // وحده بعد النافذة — نبضة التبويب أو التطبيق تكون قد تسلّمت.
            if ($sessionKey === null && $platform === AgentPresence::WEB && request()->hasSession()) {
                $sessionKey = request()->session()->getId();
            }
            AgentPresence::touch($organizationId, $userId, $clientId ?? $platform . '-login', $platform, $sessionKey);
        } catch (\Throwable $e) {
            Log::warning('Agent presence could not be recorded', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
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
