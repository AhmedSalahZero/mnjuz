<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ConversationRating;
use App\Services\ActivityLogger;
use App\Services\AgentPerformanceService;
use App\Services\ConversationRatingService;
use App\Services\SubscriptionService;
use App\Support\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تقارير التطبيق: أداء الموظفين، وتقييمات العملاء، وسجلّ النشاط.
 *
 * الثلاثة للمالك والمدير وحدهما — الموظّف لا يرى أداء زملائه ولا تقييماتهم.
 * والبوّابات هي نفسها التي يفرضها الويب، فلا تتفرّق الصلاحيات بين الواجهتين.
 */
class MobileReportController extends Controller
{
    use MobileApi;

    /**
     * أداء الموظفين خلال مدّة.
     *
     * الافتراضي آخر ثلاثين يوماً — نفس ما يفتح به الويب.
     */
    public function agentPerformance(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'agent_performance')) {
            return $this->fail(403, __('This feature is not available in your plan.'));
        }

        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        [$from, $to] = $this->dateRange($request);

        return $this->ok([
            'metrics' => (new AgentPerformanceService($organizationId))->metrics($from, $to),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    /**
     * تقييمات العملاء المُرسَلة.
     *
     * الملخّص محسوب على كامل النتائج المُرشَّحة لا على الصفحة المعروضة —
     * وإلّا تغيّر المتوسّط كلّما قلّب المستخدم الصفحات.
     */
    public function ratings(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);

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

        $rows = (clone $query)
            ->with('contact:id,first_name,last_name')
            ->orderByDesc('submitted_at')
            ->paginate($this->perPage($request));

        $rows->getCollection()->transform(fn ($row) => [
            'uuid' => $row->uuid,
            // اللقطة تُحفظ لحظة الطلب لتبقى بعد حذف العميل. لكن عميلاً بلا اسم
            // وقتها قد يكون سُمّي بعدها — فنعرض اسمه الحالي بدل فراغ.
            'contact_name' => $row->contact_name ?: (trim((string) optional($row->contact)->full_name) ?: null),
            'contact_phone' => $row->contact_phone,
            'agent_name' => $row->agent_name,
            'rating' => $row->rating,
            'comment' => $row->comment,
            'submitted_at' => optional($row->submitted_at)->toDateTimeString(),
        ]);

        $summary = (clone $query)->selectRaw('COUNT(*) as total, AVG(rating) as average')->first();

        return $this->ok([
            'items' => $rows->items(),
            'pagination' => [
                'page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'average' => $summary && $summary->total ? round((float) $summary->average, 2) : null,
                'pending' => ConversationRating::where('organization_id', $organizationId)
                    ->where('status', ConversationRating::STATUS_PENDING)
                    ->count(),
            ],
            'can_delete' => ConversationRatingService::canDelete($organizationId, $this->role($request)),
            'deletion_allowed_by_plan' => ConversationRatingService::deletionAllowedByPlan($organizationId),
        ]);
    }

    /** حذف تقييم — للمالك وحده، وبشرط الباقة. */
    public function deleteRating(Request $request, string $uuid): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);

        if (!ConversationRatingService::deletionAllowedByPlan($organizationId)) {
            return $this->fail(403, __('Deleting ratings is not available in your plan.'));
        }

        if (!OrganizationRole::isOwnerOnly($this->role($request))) {
            return $this->fail(403, __('Only the business owner can delete ratings.'));
        }

        $rating = ConversationRating::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$rating) {
            return $this->fail(404, __('Rating not found'));
        }

        // المنشأة تُمرَّر صراحةً: المُسجِّل يقرأها من الجلسة، ولا جلسة هنا.
        ActivityLogger::log(
            ActivityLogger::RATING_DELETED,
            $rating->contact_name ?: $rating->contact_phone,
            'contact',
            $rating->contact_id,
            ['rating' => $rating->rating],
            $organizationId
        );

        $rating->delete();

        return $this->ok(null, __('Rating deleted successfully!'));
    }

    /**
     * سجلّ النشاط.
     *
     * ما يُسجَّل يُحذف تلقائياً بعد RETENTION_DAYS، ويُعلَن ذلك في الردّ كي
     * يعرض التطبيق السبب بدل أن يبدو السجلّ ناقصاً.
     */
    public function activityLog(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if (!ActivityLogger::featureEnabled($organizationId)) {
            return $this->fail(403, __('This feature is not available in your plan.'));
        }

        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $query = ActivityLog::where('organization_id', $organizationId);

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('group')) {
            $events = ActivityLogger::groups()[$request->input('group')] ?? null;
            if ($events) {
                $query->whereIn('event', $events);
            }
        }

        if ($request->filled('event')) {
            $query->where('event', (string) $request->input('event'));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('user_name', 'like', $term)
                    ->orWhere('subject_label', 'like', $term);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('from'))->toDateString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('to'))->toDateString());
        }

        $rows = $query->orderByDesc('id')->paginate($this->perPage($request, 50));

        $rows->getCollection()->transform(fn ($row) => [
            'id' => $row->id,
            'user_name' => $row->user_name,
            'user_id' => $row->user_id,
            'event' => $row->event,
            'description' => ActivityLogger::describe($row->event, $row->subject_label),
            'subject_label' => $row->subject_label,
            'ip' => $row->ip,
            'created_at' => optional($row->created_at)->toDateTimeString(),
        ]);

        return $this->ok([
            'items' => $rows->items(),
            'pagination' => [
                'page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
            'members' => $this->members($organizationId),
            'groups' => array_keys(ActivityLogger::groups()),
            'retention_days' => ActivityLogger::RETENTION_DAYS,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dateRange(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->subDays(29)->startOfDay();

        return [$from, $to];
    }

    /** أعضاء الفريق لتغذية مُرشِّح «المستخدم» في التطبيق. */
    private function members(int $organizationId): array
    {
        return DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.organization_id', $organizationId)
            ->whereNull('teams.deleted_at')
            ->orderBy('users.first_name')
            ->get(['users.id', 'users.first_name', 'users.last_name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => trim($row->first_name . ' ' . $row->last_name),
            ])
            ->all();
    }
}
