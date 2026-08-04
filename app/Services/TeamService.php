<?php

namespace App\Services;

use App\Helpers\Email;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Services\WazSyncService;
use Auth;
use DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Str;
use Validator;

class TeamService
{
    public function invite(object $request)
    {
        //check if invite already exists
        $invite = TeamInvite::where('email', $request->email)->first();

        if(!$invite){     
            $inviteCode = md5(Carbon::now()->timestamp . session()->get('current_organization') . Str::random(32)); 
            $invite = TeamInvite::create([
                'organization_id' => session()->get('current_organization'),
                'email' => $request->email,
                'code' => $inviteCode,
                'role' => $request->role,
                'invited_by' => auth()->user()->id,
                'expire_at' => now()->addDay(), 
            ]);
        } else  {
            $inviteCode = $invite->code;
            if($invite->expire_at <= now()){
                $inviteCode = md5(Carbon::now()->timestamp . session()->get('current_organization') . Str::random(32));
                $invite->code = $inviteCode;
                $invite->expire_at = now()->addDay();
                $invite->invited_by = auth()->user()->id;
                $invite->save();
            }
        }

        //Send invite email
        $inviter = User::where('id', auth()->user()->id)->first();
        Email::sendInvite('Invite', $request->email, $inviter, url('/invite/' . $inviteCode));

        return $invite;
    }

    public function store(object $request, $inviteCode)
    {
        $invite = TeamInvite::where('code', $inviteCode)
            ->where('expire_at', '>=', now())
            ->first();

        if(!$invite){
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('This invite code has expired!')
                ]
            );
        }

        try{
            $user = User::where('email', $request->email)->first();

            if(!$user){
                $user = User::create([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'email' => $request->email,
                    'password' => bcrypt($request->password),
                    'language' => app()->getLocale(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                //Send new user registration email
                Email::send('Registration', $user);
            }

            $team = Team::updateOrCreate(
                [
                    'organization_id' => $invite->organization_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $invite->role,
                    'status' => 'active',
                    'created_by' => $invite->invited_by,
                    'updated_at' => now()
                ]
            );

            $invite->delete();

            Auth::guard('user')->loginUsingId($user->id);

            session()->put('current_organization', $invite->organization_id);
        } catch (\Exception $e) {
            Log::error('Exception: ' . $e->getMessage());
   
            //return response()->view('errors.custom', [], 500); // Customize the error response as needed
        }
    }

    public function update(object $request, $uuid)
    {
        Team::where('uuid', $uuid)
            ->where('role', '!=', 'owner')
            ->update([
                'role' => $request->role,
                'updated_at' => now()
            ]);
    }

    /**
     * هل ما زال المستخدم عضواً في منظمة أخرى مربوطة بواز؟
     *
     * جهة الاتصال واحدة لكل مستخدم في واز، فحذفها لمجرد مغادرته منظمة يقطع
     * وصوله عن بقية منظماته.
     */
    private function belongsToOtherLinkedOrganization(User $user, $excludeOrganizationId): bool
    {
        return Team::where('user_id', $user->id)
            ->where('organization_id', '!=', $excludeOrganizationId)
            ->whereNull('deleted_at')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('organizations')
                    ->whereColumn('organizations.id', 'teams.organization_id')
                    ->whereNotNull('organizations.waz_company_id');
            })
            ->exists();
    }

    public function destroy($uuid)
    {
        $team = Team::where('uuid', $uuid)->first();

        if($team->role === 'owner'){
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('You can\'t delete the main admin account!')
                ]
            );
        } else {
            $user = User::find($team->user_id);

            Team::where('uuid', $uuid)->where('role', '!=', 'owner')->delete();

            // نحذف جهة اتصاله في واز أعمال أيضاً حتى لا يبقى لمن غادر وصول
            // لفواتير المنشأة وتذاكرها هناك. لا نحذفها إن كان لا يزال عضواً
            // في منظمة أخرى مربوطة، فجهة الاتصال واحدة لكل مستخدم.
            if ($user && $user->waz_contact_id && !$this->belongsToOtherLinkedOrganization($user, $team->organization_id)) {
                app(WazSyncService::class)->removeContact($user);
            }

            return Redirect::back()->with(
                'status', [
                    'type' => 'success',
                    'message' => __('Row deleted successfully!')
                ]
            );
        }
    }
};
