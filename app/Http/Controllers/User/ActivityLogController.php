<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use App\Support\OrganizationRole;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * سجلّ نشاط أعضاء المنظمة: عرض ومرشِّحات وتصدير.
 * الصلاحية للمالك والمدير — الموظف لا يرى سجلّ زملائه.
 */
class ActivityLogController extends BaseController
{
    private function organizationId(): int
    {
        return (int) session()->get('current_organization');
    }

    private function isManager(): bool
    {
        $role = auth()->user()->getRoleNameForOrganization($this->organizationId());
        if ($role === '') {
            $role = OrganizationRole::OWNER;
        }

        return OrganizationRole::isPrivileged($role);
    }

    private function guard(): void
    {
        if (!$this->isManager()) {
            abort(403, __('You are not allowed to access this page.'));
        }
    }

    /**
     * الاستعلام المُرشَّح المشترك بين العرض والتصدير، حتى يُصدَّر ما يُرى بالضبط.
     */
    private function filteredQuery(Request $request)
    {
        $query = ActivityLog::where('organization_id', $this->organizationId());

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
            $query->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        return $query->orderByDesc('id');
    }

    public function index(Request $request)
    {
        $this->guard();

        $rows = $this->filteredQuery($request)->paginate(50)->withQueryString();

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

        return Inertia::render('User/ActivityLog', [
            'title' => __('Activity Log'),
            'rows' => $rows,
            // نبني الجملة هنا لا في الواجهة: الترجمة والعدد في مكان واحد.
            'retentionNotice' => __(
                'Activity is kept for :days days and then deleted automatically. Export the data if you need to keep it longer.',
                ['days' => ActivityLogger::RETENTION_DAYS]
            ),
            'members' => $this->members(),
            'groups' => array_keys(ActivityLogger::groups()),
            'filters' => $request->only(['user_id', 'group', 'event', 'search', 'from', 'to']),
        ]);
    }

    /**
     * تصدير CSV بنفس المرشِّحات. البثّ صفّاً صفّاً لا تجميعاً في الذاكرة:
     * أسبوع من نشاط منظمة نشِطة قد يبلغ مئات الآلاف من الصفوف.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->guard();

        $query = $this->filteredQuery($request);
        $filename = 'activity-log-' . now()->format('Y-m-d-His') . '.csv';

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM ليفتح إكسل العربية بترميز صحيح بدل الرموز المشوّهة.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [__('Date'), __('Member'), __('Event'), __('Details'), 'IP']);

            $query->chunk(1000, function ($chunk) use ($handle) {
                foreach ($chunk as $row) {
                    fputcsv($handle, [
                        optional($row->created_at)->toDateTimeString(),
                        $row->user_name,
                        $row->event,
                        ActivityLogger::describe($row->event, $row->subject_label),
                        $row->ip,
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** أعضاء المنظمة لمرشِّح «الموظف». */
    private function members(): array
    {
        return \DB::table('teams')
            ->join('users', 'users.id', '=', 'teams.user_id')
            ->where('teams.organization_id', $this->organizationId())
            ->whereNull('teams.deleted_at')
            ->orderBy('users.first_name')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.email'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
            ])
            ->all();
    }
}
