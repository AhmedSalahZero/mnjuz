<?php

namespace App\Http\Controllers;

use App\Models\ConversationRating;
use App\Models\Organization;
use App\Services\ConversationRatingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * صفحة التقييم العامة — يفتحها العميل من رابط الواتساب بلا تسجيل دخول.
 *
 * الرمز وحده هو التصريح، ولذلك: عشوائي طويل، ويُستهلك مرّة واحدة، وينتهي
 * بعد أسبوع. ولا نكشف من الصفحة أي بيانات عن العميل أو المحادثة — من يملك
 * الرابط ليس بالضرورة صاحبه.
 */
class RatingController extends Controller
{
    public function show(string $token)
    {
        $rating = ConversationRating::where('token', $token)->first();

        if (!$rating) {
            return Inertia::render('Rating/Form', ['state' => 'invalid']);
        }

        $state = $rating->isSubmitted() ? 'submitted' : ($rating->isExpired() ? 'expired' : 'open');

        return Inertia::render('Rating/Form', [
            'state' => $state,
            'token' => $token,
            'organizationName' => Organization::where('id', $rating->organization_id)->value('name'),
            'stars' => $rating->rating,
        ]);
    }

    public function store(Request $request, string $token)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $rating = ConversationRating::where('token', $token)->first();

        if (!$rating || !$rating->isOpenForSubmission()) {
            return Inertia::render('Rating/Form', [
                'state' => $rating && $rating->isSubmitted() ? 'submitted' : 'expired',
            ]);
        }

        ConversationRatingService::submit(
            $rating,
            (int) $request->input('rating'),
            $request->input('comment'),
            $request->ip()
        );

        return Inertia::render('Rating/Form', [
            'state' => 'submitted',
            'organizationName' => Organization::where('id', $rating->organization_id)->value('name'),
            'stars' => $rating->rating,
        ]);
    }
}
