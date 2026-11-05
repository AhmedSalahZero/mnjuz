<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «ادعُ صديقاً»: رمز إحالة لكل مستخدم، ورابط يحمله، وتسجيلٌ يُنسب لصاحبه.
 *
 * المسار: الصديق يفتح /r/{code} فيُحفظ الرمز في الجلسة وفي كوكي ثلاثين
 * يوماً (قد يعود ليسجّل بعد أيام)، ثم يُوجَّه إلى /signup. وعند إنشاء حسابه
 * — بالنموذج أو بجوجل أو فيسبوك — يُقرأ الرمز ويُكتب صفّ الإحالة.
 *
 * والتسجيل لا يفشل أبداً بسبب الإحالة: أي خطأ هنا يُسجَّل ويُتجاوَز.
 */
class ReferralService
{
    public const SESSION_KEY = 'referral_code';

    public const COOKIE = 'mnjz_ref';

    public const COOKIE_MINUTES = 60 * 24 * 30;

    /** بلا الأحرف المتشابهة (0/O، 1/I/L) — الرمز قد يُملى أو يُكتب يدوياً. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    /** رمز المستخدم، يُنشأ عند أوّل طلب. */
    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $this->generate();

            try {
                // الشرط على null: طلبان متزامنان للمستخدم نفسه لا يكتب ثانيهما فوق الأوّل.
                $updated = DB::table('users')->where('id', $user->id)->whereNull('referral_code')->update(['referral_code' => $code]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    continue; // تصادم نادر مع رمز مستخدم آخر
                }
                throw $e;
            }

            $code = $updated ? $code : (string) DB::table('users')->where('id', $user->id)->value('referral_code');
            $user->forceFill(['referral_code' => $code])->syncOriginalAttribute('referral_code');

            return $code;
        }

        throw new \RuntimeException('Could not generate a unique referral code.');
    }

    public function link(User $user): string
    {
        return rtrim(url('/'), '/') . '/r/' . $this->codeFor($user);
    }

    public function shareText(string $link): string
    {
        return __('Manage all your WhatsApp customer conversations in one place with :app. Sign up with my link: :link', [
            'app' => config('app.name'),
            'link' => $link,
        ]);
    }

    public static function normalize(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z0-9]{4,16}$/', $code) ? $code : null;
    }

    public function referrerByCode(?string $code): ?User
    {
        $code = self::normalize($code);

        return $code ? User::where('referral_code', $code)->first() : null;
    }

    /**
     * يحفظ الرمز من رابط الإحالة في الجلسة والكوكي.
     *
     * @return bool هل كان الرمز صحيحاً؟
     */
    public function remember(Request $request, ?string $code): bool
    {
        $referrer = $this->referrerByCode($code);

        if (!$referrer) {
            return false;
        }

        $request->session()->put(self::SESSION_KEY, $referrer->referral_code);
        Cookie::queue(self::COOKIE, $referrer->referral_code, self::COOKIE_MINUTES);

        return true;
    }

    /**
     * ينسب حساباً أُنشئ الآن لصاحب رابط الإحالة، إن وُجد.
     */
    public function recordSignup(User $user, ?Request $request = null): ?Referral
    {
        $request ??= request();

        try {
            // مخزن الجلسة نفسه لا جلسة الطلب: التسجيل بجوجل وفيسبوك يمرّ
            // بمعاملة وطلب callback، والمخزن واحد في الحالتين.
            $code = session()->get(self::SESSION_KEY) ?? $request->cookie(self::COOKIE);

            $referrer = $this->referrerByCode(is_string($code) ? $code : null);

            session()->forget(self::SESSION_KEY);
            Cookie::queue(Cookie::forget(self::COOKIE));

            // من يحيل نفسه — أو حساباً عاد بعد حذفه — لا يُحسب.
            if (!$referrer || (int) $referrer->id === (int) $user->id) {
                return null;
            }

            DB::table('referrals')->insertOrIgnore([
                'referrer_id' => $referrer->id,
                'referred_user_id' => $user->id,
                'code' => $referrer->referral_code,
                'created_at' => now(),
            ]);

            return Referral::where('referred_user_id', $user->id)->first();
        } catch (Throwable $e) {
            Log::warning('Referral could not be recorded', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * من سجّلوا برابط المستخدم: إجمالاً، ومن اشترك منهم في باقة مدفوعة.
     *
     * @return array{total: int, subscribed: int}
     */
    public function stats(User $user): array
    {
        $ids = Referral::where('referrer_id', $user->id)->pluck('referred_user_id');

        return [
            'total' => $ids->count(),
            'subscribed' => $ids->isEmpty() ? 0 : count(array_intersect($ids->all(), $this->subscribedUserIds($ids->all()))),
        ];
    }

    /**
     * من بين هؤلاء: من يملك منشأة باشتراك مدفوع ساري (باقة لا تجربة).
     *
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    public function subscribedUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('teams')
            ->join('subscriptions', 'subscriptions.organization_id', '=', 'teams.organization_id')
            ->whereIn('teams.user_id', $userIds)
            ->where('teams.role', 'owner')
            ->whereNull('teams.deleted_at')
            ->where('subscriptions.status', 'active')
            ->whereNotNull('subscriptions.plan_id')
            ->where('subscriptions.valid_until', '>=', now())
            ->distinct()
            ->pluck('teams.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** البريد مخفيّاً: صاحب الرابط يعرف صديقه، ولا حاجة لعرض بريده كاملاً. */
    public static function maskEmail(?string $email): ?string
    {
        if (!$email || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }

    private function generate(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
