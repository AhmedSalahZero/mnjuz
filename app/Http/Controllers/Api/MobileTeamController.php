<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Rules\TeamLimit;
use App\Services\ActivityLogger;
use App\Services\SubscriptionService;
use App\Services\TeamService;
use App\Support\OrganizationRole;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * إدارة الفريق من تطبيق الجوال: الأعضاء، والدعوات، والأدوار.
 *
 * كالويب: المالك والمدير وحدهما (الموظّف ممنوع من /team كلّها في
 * RestrictOrganizationAgent). والدعوة تمرّ بـ TeamService::invite نفسها —
 * بريدٌ برابط /invite/{code} صالح يوماً واحداً، يقبله المدعوّ من المتصفّح.
 *
 * رابط الدعوة لا يُعاد في الردّ عمداً: قبول الدعوة لبريدٍ له حساب قائم
 * يُدخله بلا كلمة مرور، فالرابط في يد غير صاحب البريد مفتاحٌ لحسابه.
 */
class MobileTeamController extends Controller
{
    use MobileApi;

    /** الأدوار التي تُمنح بالدعوة أو التعديل — المالك واحد ولا يُنشأ هنا. */
    private const ASSIGNABLE_ROLES = [OrganizationRole::MANAGER, OrganizationRole::AGENT];

    public function index(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);
        $trashed = $request->boolean('trashed');

        $query = Team::query()
            ->with(['user' => fn ($q) => $q->withTrashed()])
            ->where('organization_id', $organizationId)
            ->when($trashed, fn ($q) => $q->onlyTrashed());

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->whereHas('user', function ($q) use ($term) {
                $q->withTrashed()->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            });
        }

        $rows = $query
            ->orderByRaw("FIELD(role, 'owner', 'manager', 'agent')")
            ->orderBy('id')
            ->paginate($this->perPage($request, 25));

        $me = (int) $request->user()->id;
        $rows->getCollection()->transform(fn (Team $team) => $this->presentMember($team, $me));

        $payload = $this->paginated($rows)->getData(true);
        $payload['data']['limits'] = $this->limits($organizationId);
        $payload['data']['roles'] = self::ASSIGNABLE_ROLES;
        $payload['data']['pending_invites'] = $this->pendingInvites($organizationId);

        return response()->json($payload);
    }

    public function invite(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);

        $validator = Validator::make($request->all(), [
            'email' => [
                'required',
                'email',
                'max:128',
                // عضو نشط بالبريد نفسه في هذه المنشأة.
                Rule::unique('users', 'email')->where(function ($query) use ($organizationId) {
                    $query->whereIn('id', function ($sub) use ($organizationId) {
                        $sub->select('user_id')->from('teams')
                            ->whereNull('deleted_at')
                            ->where('organization_id', $organizationId);
                    });
                }),
                new TeamLimit(null, $organizationId),
            ],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ], [
            'email.unique' => __('User with this email already exists in your team.'),
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $validated['email'] = mb_strtolower(trim($validated['email']));

        $invite = app(TeamService::class)->invite((object) $validated, $organizationId, (int) $request->user()->id);

        ActivityLogger::log(
            ActivityLogger::TEAM_MEMBER_INVITED,
            $validated['email'],
            'team_invite',
            null,
            ['role' => $validated['role'], 'source' => 'mobile'],
            $organizationId
        );

        return $this->ok([
            'invite' => $this->presentInvite($invite->fresh()),
            'email_sent' => $this->emailEnabled(),
        ], __('User invited successfully!'));
    }

    public function resendInvite(Request $request, int $id): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);
        $invite = TeamInvite::where('id', $id)->where('organization_id', $organizationId)->first();

        if (!$invite) {
            return $this->fail(404, __('Data not found'));
        }

        $invite = app(TeamService::class)->invite(
            (object) ['email' => $invite->email, 'role' => $invite->role],
            $organizationId,
            (int) $request->user()->id
        );

        return $this->ok([
            'invite' => $this->presentInvite($invite->fresh()),
            'email_sent' => $this->emailEnabled(),
        ], __('Invitation sent again.'));
    }

    public function cancelInvite(Request $request, int $id): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $deleted = TeamInvite::where('id', $id)
            ->where('organization_id', $this->organizationId($request))
            ->delete();

        return $deleted ? $this->ok(null, __('Invitation cancelled.')) : $this->fail(404, __('Data not found'));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $team = $this->find($request, $uuid);

        if (!$team) {
            return $this->fail(404, __('Data not found'));
        }

        $validator = Validator::make($request->all(), [
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        if ($team->role === OrganizationRole::OWNER) {
            return $this->fail(422, __("The owner's role can't be changed."));
        }

        // تغيير دورك بنفسك قد يُخرجك من الإدارة بلا رجعة من التطبيق.
        if ((int) $team->user_id === (int) $request->user()->id) {
            return $this->fail(422, __("You can't change your own role."));
        }

        $team->role = $validator->validated()['role'];
        $team->updated_at = now();
        $team->save();

        ActivityLogger::log(
            ActivityLogger::TEAM_MEMBER_ROLE_CHANGED,
            $this->memberLabel($team),
            'team',
            $team->id,
            ['role' => $team->role, 'source' => 'mobile'],
            $this->organizationId($request)
        );

        return $this->ok($this->presentMember($team->fresh(['user']), (int) $request->user()->id), __('User account updated successfully!'));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $team = $this->find($request, $uuid);

        if (!$team) {
            return $this->fail(404, __('Data not found'));
        }

        if ($team->role === OrganizationRole::OWNER) {
            return $this->fail(422, __('You can\'t delete the main admin account!'));
        }

        if ((int) $team->user_id === (int) $request->user()->id) {
            return $this->fail(422, __("You can't remove yourself from the team."));
        }

        $label = $this->memberLabel($team);

        app(TeamService::class)->removeMember($team, (int) $request->user()->id);

        ActivityLogger::log(
            ActivityLogger::TEAM_MEMBER_REMOVED,
            $label,
            'team',
            $team->id,
            ['source' => 'mobile'],
            $this->organizationId($request)
        );

        return $this->ok(null, __('Data deleted successfully!'));
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);

        // العضو المستعاد يُحسب في حدّ الفريق كالعضو الجديد.
        if (SubscriptionService::isSubscriptionFeatureLimitReached($organizationId, 'team_limit')) {
            return $this->fail(422, __('You have reached your limit of team members. Please upgrade your account to add more!'));
        }

        $result = app(TeamService::class)->restore($uuid, $organizationId);

        if (!$result['ok']) {
            $exists = Team::withTrashed()->where('uuid', $uuid)->where('organization_id', $organizationId)->exists();

            return $this->fail($exists ? 422 : 404, $exists ? $result['message'] : __('Data not found'));
        }

        $team = Team::with(['user'])->where('uuid', $uuid)->where('organization_id', $organizationId)->first();

        ActivityLogger::log(
            ActivityLogger::TEAM_MEMBER_RESTORED,
            $this->memberLabel($team),
            'team',
            $team->id,
            ['source' => 'mobile'],
            $organizationId
        );

        return $this->ok($this->presentMember($team, (int) $request->user()->id), $result['message']);
    }

    // ------------------------------------------------------------------

    private function find(Request $request, string $uuid): ?Team
    {
        return Team::with(['user' => fn ($q) => $q->withTrashed()])
            ->where('uuid', $uuid)
            ->where('organization_id', $this->organizationId($request))
            ->first();
    }

    /** @return array<string, mixed> */
    private function presentMember(Team $team, int $me): array
    {
        $user = $team->user;
        $isOwner = $team->role === OrganizationRole::OWNER;
        $isMe = (int) $team->user_id === $me;
        $deleted = $team->deleted_at !== null;

        return [
            'uuid' => $team->uuid,
            'user' => $user ? [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ] : null,
            'role' => $team->role,
            'status' => $team->status,
            'is_owner' => $isOwner,
            'is_me' => $isMe,
            'deleted' => $deleted,
            'deleted_at' => optional($team->deleted_at)->toIso8601String(),
            'joined_at' => optional($team->created_at)->toIso8601String(),
            'can_edit' => !$isOwner && !$isMe && !$deleted,
            'can_delete' => !$isOwner && !$isMe && !$deleted,
            'can_restore' => $deleted,
        ];
    }

    /** @return array<string, mixed> */
    private function presentInvite(TeamInvite $invite): array
    {
        $expiresAt = $invite->expire_at ? Carbon::parse($invite->expire_at) : null;

        return [
            'id' => $invite->id,
            'email' => $invite->email,
            'role' => $invite->role,
            'invited_by' => optional(User::find($invite->invited_by))->first_name,
            'expires_at' => optional($expiresAt)->toIso8601String(),
            'expired' => $expiresAt !== null && $expiresAt->isPast(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingInvites(int $organizationId): array
    {
        return TeamInvite::where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (TeamInvite $invite) => $this->presentInvite($invite))
            ->values()
            ->all();
    }

    /** @return array{team_limit: ?int, used: int, remaining: ?int} */
    private function limits(int $organizationId): array
    {
        $limit = SubscriptionService::featureLimit($organizationId, 'team_limit');
        // العدّ كما في isSubscriptionFeatureLimitReached: الأعضاء النشطون.
        $used = Team::where('organization_id', $organizationId)->count();

        return [
            'team_limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    private function memberLabel(?Team $team): ?string
    {
        $user = $team?->user;

        if (!$user) {
            return null;
        }

        return trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email;
    }

    /** هل يُرسل البريد فعلاً؟ بلا SMTP تُحفظ الدعوة ولا يصل شيء — يُقال ذلك للمالك. */
    private function emailEnabled(): bool
    {
        return (string) Setting::where('key', 'smtp_email_active')->value('value') === '1';
    }
}
