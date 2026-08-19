<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\ConversationRating;
use App\Services\ActivityLogger;
use App\Services\ConversationRatingService;
use App\Support\OrganizationRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

/**
 * تقييمات العملاء داخل لوحة النشاط التجاري.
 * العرض للمالك والمدير — الموظّف لا يرى تقييمات زملائه.
 */
class RatingController extends BaseController
{
    private function organizationId(): int
    {
        return (int) session()->get('current_organization');
    }

    private function role(): string
    {
        $role = auth()->user()->getRoleNameForOrganization($this->organizationId());

        return $role !== '' ? $role : OrganizationRole::OWNER;
    }

    private function guard(): void
    {
        if (!OrganizationRole::isPrivileged($this->role())) {
            abort(403, __('You are not allowed to access this page.'));
        }
    }

    public function index(Request $request)
    {
        $this->guard();

        $organizationId = $this->organizationId();

        $query = ConversationRating::where('organization_id', $organizationId)
            ->where('status', ConversationRating::STATUS_SUBMITTED);

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->input('rating'));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('contact_name', 'like', $term)
                  ->orWhere('contact_phone', 'like', $term)
                  ->orWhere('comment', 'like', $term);
            });
        }

        $rows = (clone $query)->orderByDesc('submitted_at')->paginate(25)->withQueryString();

        $rows->getCollection()->transform(fn ($row) => [
            'uuid' => $row->uuid,
            'contact_name' => $row->contact_name,
            'contact_phone' => $row->contact_phone,
            'agent_name' => $row->agent_name,
            'rating' => $row->rating,
            'comment' => $row->comment,
            'submitted_at' => optional($row->submitted_at)->toDateTimeString(),
        ]);

        // الملخّص محسوب على كامل النتائج المُرشَّحة لا على الصفحة المعروضة.
        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        return Inertia::render('User/Ratings', [
            'title' => __('Customer Ratings'),
            'rows' => $rows,
            'filters' => $request->only(['rating', 'search']),
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'average' => $summary && $summary->total ? round((float) $summary->average, 2) : null,
                'pending' => ConversationRating::where('organization_id', $organizationId)
                    ->where('status', ConversationRating::STATUS_PENDING)
                    ->count(),
            ],
            'canDelete' => ConversationRatingService::canDelete($organizationId, $this->role()),
            'deletionAllowedByPlan' => ConversationRatingService::deletionAllowedByPlan($organizationId),
        ]);
    }

    public function destroy(string $uuid)
    {
        $this->guard();

        $organizationId = $this->organizationId();

        if (!ConversationRatingService::deletionAllowedByPlan($organizationId)) {
            abort(403, __('Deleting ratings is not available in your plan.'));
        }

        if (!OrganizationRole::isOwnerOnly($this->role())) {
            abort(403, __('Only the business owner can delete ratings.'));
        }

        $rating = ConversationRating::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        ActivityLogger::log(
            ActivityLogger::RATING_DELETED,
            $rating->contact_name ?: $rating->contact_phone,
            'contact',
            $rating->contact_id,
            ['rating' => $rating->rating]
        );

        $rating->delete();

        return Redirect::back()->with('status', [
            'type' => 'success',
            'message' => __('Rating deleted successfully!'),
        ]);
    }
}
