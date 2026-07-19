<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserAccountDeletionService
{
    /**
     * Soft-delete a dashboard user account: remove all team memberships, revoke tokens, soft-delete the user.
     *
     * @return array{ok: bool, message?: string, status?: int}
     */
    public function softDeleteDashboardUser(User $user): array
    {
        if (($user->role ?? '') !== 'user') {
            return [
                'ok' => false,
                'message' => __('This action is not available for this account type.'),
                'status' => 403,
            ];
        }

        if (Team::query()
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists()) {
            return [
                'ok' => false,
                'message' => __('You cannot delete your account while you are the owner of an organization. Transfer ownership first.'),
                'status' => 403,
            ];
        }

        DB::transaction(function () use ($user) {
            Team::query()
                ->where('user_id', $user->id)
                ->get()
                ->each(fn (Team $team) => $team->delete());

            $user->tokens()->delete();

            $user->forceFill([
                'current_web_organization_id' => null,
                'current_mobile_organization_id' => null,
            ])->save();

            $user->delete();
        });

        return ['ok' => true];
    }
}
