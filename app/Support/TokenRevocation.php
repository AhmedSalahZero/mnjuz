<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * إبطال رموز الجوال مع حفظ السبب، وقراءته لاحقاً عند وصول طلب برمز مُبطَل.
 *
 * الحالة 401 تبقى كما هي لأنها الصحيحة: الرمز لم يعد صالحاً، وأي حالة نجاح
 * ستجعل التطبيق يظنّ نفسه مُصادَقاً. ما نضيفه هو مفتاح `code` في الجسم كي
 * يميّز التطبيق «طُردتَ من جهاز آخر» عن «انتهت جلستك» ويعرض الرسالة المناسبة.
 */
final class TokenRevocation
{
    public const DEVICE_REPLACED = 'device_replaced';

    /**
     * نُبطل ولا نحذف: الصفّ المحذوف لا يُخبر أحداً بسبب اختفائه.
     * expires_at في الماضي كافٍ للرفض — Sanctum\Guard يفحصه قبل السماح.
     */
    public static function revokeAll($user, string $reason = self::DEVICE_REPLACED): int
    {
        return $user->tokens()->update([
            'expires_at' => now()->subSecond(),
            'revoked_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    /**
     * سبب إبطال الرمز المُرسَل في هذا الطلب، أو null إن لم يكن مُبطَلاً بسببٍ نعرفه.
     */
    public static function reasonForRequest(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (!$bearer) {
            return null;
        }

        // شكل الرمز: "{id}|{plain}" والمخزَّن هو sha256(plain)
        $plain = str_contains($bearer, '|') ? explode('|', $bearer, 2)[1] : $bearer;

        $reason = DB::table('personal_access_tokens')
            ->where('token', hash('sha256', $plain))
            ->value('revoked_reason');

        return $reason ?: null;
    }

    /** الرسالة المعروضة للمستخدم مقابل كل سبب. */
    public static function messageFor(string $reason): string
    {
        return match ($reason) {
            self::DEVICE_REPLACED => __('You were signed out because your account was accessed from another device.'),
            default => __('You are not authenticated. Please login first.'),
        };
    }
}
