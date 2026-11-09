<?php

namespace App\Services;

use App\Events\AgentPresenceChanged;
use App\Jobs\ConfirmAgentOffline;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * من هو متصل الآن، ومتى تغيّر ذلك.
 *
 * كل تبويب وكل جلسة تطبيق «عميل» له صفّ في agent_presence_clients ينعشه
 * بنبضته. الموظف متصل ما دام له عميل واحد حيّ في المنظمة — فإغلاق تبويب من
 * اثنين لا يُظهره غائباً، والخروج من الويب لا يُسقط التطبيق المفتوح.
 *
 * التغيّر يُبثّ للمديرين لحظة حدوثه ({@see AgentPresenceChanged}):
 *   - متصل: أول نبضة بعد غياب.
 *   - غير متصل: إغلاق التبويب أو الخروج أو انتقال التطبيق إلى الخلفية (إشارة
 *     صريحة، تُؤكَّد بعد مهلة قصيرة)، أو انقطاع النبضة (المنظّف).
 *
 * لا ترمي أبداً: الحضور ميزة مساعدة، وتعذّره لا يجوز أن يُفشل نبضةً أو دخولاً
 * أو إرسال رسالة.
 */
class AgentPresence
{
    /** كل كم ثانية ينبض العميل. الواجهة والتطبيق يقرآنها من هنا (الوثيقة). */
    public const HEARTBEAT_SECONDS = 30;

    /**
     * عميلٌ لم ينبض منذ هذه المدّة منقطع. ثلاثة أضعاف النبضة: Chrome يُبطئ
     * مؤقّتات التبويبات الخلفية إلى مرّة في الدقيقة، ونبضة تتأخّر لا يصحّ أن
     * تُظهر الموظف غائباً.
     */
    public const ONLINE_WINDOW_SECONDS = 90;

    /**
     * مهلة تأكيد المغادرة. إعادة تحميل الصفحة تُغلق التبويب وتفتحه في لحظة،
     * فبثّ «غير متصل» فوراً كان سيُومض عند المدير ثم يعود.
     */
    public const OFFLINE_GRACE_SECONDS = 5;

    public const WEB = 'web';
    public const MOBILE = 'mobile';

    private const TABLE = 'agent_presence_clients';

    /**
     * العميل حيّ الآن في هذه المنظمة.
     *
     * العميل في منظمة واحدة في كل لحظة: تبويبٌ بدّل شركته يُغادر الأولى، وإلا
     * بقي «متصلاً» فيها حتى تنقضي النافذة.
     */
    public static function touch(int $organizationId, int $userId, string $clientId, string $platform, ?string $sessionKey = null): void
    {
        if ($organizationId <= 0 || $userId <= 0 || $clientId === '') {
            return;
        }

        try {
            $now = Carbon::now();
            $wasOnline = self::isLive($organizationId, $userId, $now);

            DB::table(self::TABLE)->upsert(
                [[
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'platform' => $platform,
                    'session_key' => $sessionKey,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['organization_id', 'user_id', 'client_id'],
                ['platform', 'session_key', 'last_seen_at', 'updated_at']
            );

            $movedFrom = DB::table(self::TABLE)
                ->where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('organization_id', '!=', $organizationId)
                ->pluck('organization_id');

            if ($movedFrom->isNotEmpty()) {
                DB::table(self::TABLE)
                    ->where('user_id', $userId)
                    ->where('client_id', $clientId)
                    ->whereIn('organization_id', $movedFrom)
                    ->delete();

                foreach ($movedFrom as $previousOrganizationId) {
                    self::confirmOfflineLater((int) $previousOrganizationId, $userId);
                }
            }

            if (!$wasOnline) {
                self::announce($organizationId, $userId, true, $now->toDateTimeString());
            }
        } catch (\Throwable $e) {
            self::warn('touch', $organizationId, $userId, $e);
        }
    }

    /** العميل غادر صراحةً (أُغلق التبويب، أو انتقل التطبيق إلى الخلفية). */
    public static function leave(int $organizationId, int $userId, string $clientId): void
    {
        if ($organizationId <= 0 || $userId <= 0 || $clientId === '') {
            return;
        }

        try {
            $deleted = DB::table(self::TABLE)
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('client_id', $clientId)
                ->delete();

            if ($deleted) {
                self::confirmOfflineLater($organizationId, $userId);
            }
        } catch (\Throwable $e) {
            self::warn('leave', $organizationId, $userId, $e);
        }
    }

    /**
     * الخروج من جلسة: يُسقط عملاءها وحدها — جلسة أخرى في متصفّح آخر، أو
     * التطبيق، تُبقي الموظف متصلاً.
     */
    public static function leaveSession(int $userId, ?string $sessionKey): void
    {
        if ($userId <= 0 || !$sessionKey) {
            return;
        }

        self::forgetWhere($userId, fn ($query) => $query->where('session_key', $sessionKey));
    }

    /** الخروج من التطبيق يحذف كل توكنات المستخدم، فتسقط كل جلساته فيه. */
    public static function leavePlatform(int $userId, string $platform): void
    {
        if ($userId <= 0) {
            return;
        }

        self::forgetWhere($userId, fn ($query) => $query->where('platform', $platform));
    }

    /**
     * يُبثّ «غير متصل» إن لم يبقَ للموظف عميل حيّ. يُنفَّذ بعد المهلة من
     * {@see ConfirmAgentOffline}: إن عاد خلالها (إعادة تحميل) فلا شيء يُبثّ.
     */
    public static function confirmOffline(int $organizationId, int $userId): void
    {
        try {
            if (!self::isLive($organizationId, $userId, Carbon::now())) {
                self::announce($organizationId, $userId, false, null);
            }
        } catch (\Throwable $e) {
            self::warn('confirmOffline', $organizationId, $userId, $e);
        }
    }

    /**
     * يحذف العملاء المنقطعين ويُبثّ «غير متصل» لمن لم يبقَ له غيرهم: تبويبٌ
     * انهار أو انقطعت شبكته، أو تطبيقٌ قُتل، لا يرسل إشارة مغادرة.
     *
     * @return int عدد الصفوف المحذوفة
     */
    public static function sweep(): int
    {
        $total = 0;

        try {
            $now = Carbon::now();
            $cutoff = $now->copy()->subSeconds(self::ONLINE_WINDOW_SECONDS);

            do {
                $stale = DB::table(self::TABLE)
                    ->where('last_seen_at', '<', $cutoff)
                    ->orderBy('id')
                    ->limit(500)
                    ->get(['id', 'organization_id', 'user_id']);

                if ($stale->isEmpty()) {
                    break;
                }

                DB::table(self::TABLE)->whereIn('id', $stale->pluck('id'))->delete();
                $total += $stale->count();

                $pairs = $stale
                    ->map(fn ($row) => [(int) $row->organization_id, (int) $row->user_id])
                    ->unique(fn ($pair) => $pair[0] . ':' . $pair[1]);

                foreach ($pairs as [$organizationId, $userId]) {
                    if (!self::isLive($organizationId, $userId, $now)) {
                        self::announce($organizationId, $userId, false, null);
                    }
                }
            } while ($stale->count() === 500);
        } catch (\Throwable $e) {
            Log::warning('Agent presence sweep failed', ['error' => $e->getMessage()]);
        }

        return $total;
    }

    /**
     * آخر ظهور لكل موظف متصل الآن في المنظمة.
     *
     * @return array<int, string> user_id => آخر نبضة
     */
    public static function liveMap(int $organizationId, ?Carbon $now = null): array
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subSeconds(self::ONLINE_WINDOW_SECONDS);

        return DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('last_seen_at', '>=', $cutoff)
            ->groupBy('user_id')
            ->select(['user_id', DB::raw('MAX(last_seen_at) as last_seen_at')])
            ->pluck('last_seen_at', 'user_id')
            ->mapWithKeys(fn ($seen, $userId) => [(int) $userId => (string) $seen])
            ->all();
    }

    public static function isLive(int $organizationId, int $userId, ?Carbon $now = null): bool
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subSeconds(self::ONLINE_WINDOW_SECONDS);

        return DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('last_seen_at', '>=', $cutoff)
            ->exists();
    }

    /** الساعة التي تُقارَن بها الأحداث والقراءات عند العميل (مللي ثانية). */
    public static function clock(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * عميل التطبيق: توكن الدخول. ثابت طوال الجلسة، ويختلف بين جهازين.
     *
     * @return array{0: string, 1: ?string} [client_id, session_key]
     */
    public static function mobileClient(Request $request): array
    {
        $user = $request->user();
        $token = $user && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        // TransientToken (طلب بجلسة لا بتوكن) بلا معرّف.
        $tokenId = $token instanceof \Laravel\Sanctum\PersonalAccessToken ? $token->getKey() : null;

        return $tokenId
            ? ['mobile:' . $tokenId, (string) $tokenId]
            : ['mobile', null];
    }

    /** @param callable(\Illuminate\Database\Query\Builder): mixed $scope */
    private static function forgetWhere(int $userId, callable $scope): void
    {
        try {
            $query = DB::table(self::TABLE)->where('user_id', $userId);
            $scope($query);

            $organizationIds = (clone $query)->distinct()->pluck('organization_id');
            if ($organizationIds->isEmpty()) {
                return;
            }

            $query->delete();

            foreach ($organizationIds as $organizationId) {
                self::confirmOfflineLater((int) $organizationId, $userId);
            }
        } catch (\Throwable $e) {
            self::warn('forget', 0, $userId, $e);
        }
    }

    private static function confirmOfflineLater(int $organizationId, int $userId): void
    {
        ConfirmAgentOffline::dispatch($organizationId, $userId)
            ->onQueue('high')
            ->delay(now()->addSeconds(self::OFFLINE_GRACE_SECONDS));
    }

    private static function announce(int $organizationId, int $userId, bool $online, ?string $lastActivityAt): void
    {
        try {
            event(new AgentPresenceChanged($organizationId, $userId, $online, $lastActivityAt, self::clock()));
        } catch (\Throwable $e) {
            // تعذّر البثّ لا يمسّ الحالة المحفوظة: الصفحة تصالحها بالاستعلام.
            self::warn('announce', $organizationId, $userId, $e);
        }
    }

    private static function warn(string $step, int $organizationId, int $userId, \Throwable $e): void
    {
        Log::warning('Agent presence ' . $step . ' failed', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
    }
}
