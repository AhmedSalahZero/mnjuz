<?php

namespace App\Http\Controllers;

use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * رابط الإحالة العام: /r/{code}.
 *
 * يحفظ الرمز ويوجّه إلى التسجيل. الرمز الخاطئ لا يُظهر خطأً — الزائر
 * جاء ليسجّل، فيصل صفحة التسجيل في الحالتين.
 */
class ReferralController extends Controller
{
    public function visit(Request $request, string $code, ReferralService $referrals): RedirectResponse
    {
        $referrals->remember($request, $code);

        return redirect('/signup');
    }
}
