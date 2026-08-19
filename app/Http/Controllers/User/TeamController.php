<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreTeam;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use App\Services\ActivityLogger;
use App\Support\OrganizationRole;

class TeamController extends BaseController
{
    private $teamService;

    public function __construct(TeamService $teamService)
    {
        $this->teamService = $teamService;
    }

    public function index(Request $request){
        // مبدّل «المحذوفون»: نعرض صفوف teams المحذوفة ناعماً مع تاريخ حذفها.
        // withTrashed على المستخدم أيضاً، وإلا لظهر العضو بلا اسم ولا بريد حين
        // يكون حسابه نفسه محذوفاً (وهو الحال في «حذف الحساب»).
        $showTrashed = $request->boolean('trashed');

        $query = Team::with(['user' => fn ($q) => $q->withTrashed()])
            ->where('organization_id', session()->get('current_organization'));

        $query = $showTrashed ? $query->onlyTrashed() : $query;

        $rows = TeamResource::collection($query->latest()->paginate(10)->withQueryString());

        if($request->expectsJson()){
            $rows = DB::table('users')
                ->join('teams', 'users.id', '=', 'teams.user_id')
                ->where('teams.organization_id', '=', session()->get('current_organization'))
                ->whereNull('teams.deleted_at')
                ->select('users.*')
                ->get();
				
            return response()->json([
                'rows' => $rows
            ]);
        } else {
            return Inertia::render('User/Team/Index', [
                'title' => __('Team'),
                'filters' => $request->all(),
                'rows' => $rows,
                'showTrashed' => $showTrashed,
                'canManageTeam' => $this->canManageTeam(),
            ]);
        }
    }

    public function invite(StoreTeam $request){
        $this->teamService->invite($request);

        ActivityLogger::log(
            ActivityLogger::TEAM_MEMBER_INVITED,
            (string) $request->input('email'),
            'team_invite',
            null,
            ['role' => $request->input('role')]
        );

        //response()->json(['success' => true, 'message'=> __('User invited successfully!'), 'data' => $invite])

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('User invited successfully!')
            ]
        );
    }

    public function update(Request $request, $uuid){
        $this->teamService->update($request, $uuid);

        ActivityLogger::log(
            ActivityLogger::TEAM_MEMBER_ROLE_CHANGED,
            $this->teamMemberLabel($uuid),
            'team',
            null,
            ['role' => $request->input('role')]
        );

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('User account updated successfully!')
            ]
        );
    }

    /** الاستعادة للمالك والمدير وحدهما. */
    private function canManageTeam(): bool
    {
        $role = auth()->user()?->getRoleNameForOrganization(session()->get('current_organization'));

        return OrganizationRole::isPrivileged($role !== '' ? $role : OrganizationRole::OWNER);
    }

    /**
     * استعادة عضو محذوف — هو وحسابه معاً.
     */
    public function restore($uuid)
    {
        if (!$this->canManageTeam()) {
            abort(403, __('You are not allowed to access this page.'));
        }

        $result = $this->teamService->restore($uuid, (int) session()->get('current_organization'));

        if ($result['ok']) {
            $user = $result['user'];
            ActivityLogger::log(
                ActivityLogger::TEAM_MEMBER_RESTORED,
                trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                'team',
                null
            );
        }

        return Redirect::back()->with('status', [
            'type' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
        ]);
    }

    public function delete($uuid)
    {
        $label = $this->teamMemberLabel($uuid);

        $this->teamService->destroy($uuid);

        ActivityLogger::log(ActivityLogger::TEAM_MEMBER_REMOVED, $label, 'team', null);
    }

    /**
     * اسم العضو للسجلّ، يُقرأ قبل الحذف.
     * الـ uuid هنا للصفّ في teams لا للمستخدم — جدول users بلا عمود uuid.
     */
    private function teamMemberLabel($uuid): ?string
    {
        $user = \App\Models\User::whereIn(
            'id',
            \App\Models\Team::where('uuid', $uuid)->select('user_id')
        )->first(['first_name', 'last_name', 'email']);

        if (!$user) {
            return null;
        }

        return trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email;
    }
}
