<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «ادعُ صديقاً» من تطبيق الجوال: رابط الإحالة ونصّ المشاركة، ومن سجّل به.
 *
 * لكل الأدوار: الإحالة شخصية لا إدارية، والموظّف يدعو كالمالك.
 */
class MobileReferralController extends Controller
{
    use MobileApi;

    public function __construct(private readonly ReferralService $referrals)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $link = $this->referrals->link($user);

        return $this->ok([
            'code' => $this->referrals->codeFor($user),
            'link' => $link,
            'share_text' => $this->referrals->shareText($link),
            'stats' => $this->referrals->stats($user),
        ]);
    }

    public function referrals(Request $request): JsonResponse
    {
        $rows = Referral::with('referredUser')
            ->where('referrer_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        $subscribed = array_flip($this->referrals->subscribedUserIds(
            $rows->getCollection()->pluck('referred_user_id')->map(fn ($id) => (int) $id)->all()
        ));

        $rows->getCollection()->transform(function (Referral $referral) use ($subscribed) {
            $user = $referral->referredUser;

            return [
                'id' => $referral->id,
                'name' => $user ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: null) : null,
                'email' => ReferralService::maskEmail($user?->email),
                'status' => isset($subscribed[(int) $referral->referred_user_id]) ? 'subscribed' : 'registered',
                'joined_at' => optional($referral->created_at)->toIso8601String(),
            ];
        });

        return $this->paginated($rows);
    }
}
