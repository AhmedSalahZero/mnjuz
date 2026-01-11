<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\Team;
use App\Models\User;
use Auth;
use DB;
use Str;

class AuthService
{
    private $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function authenticateSession($request)
    {
        if($this->user->role != 'user'){
            Auth::guard('admin')->attempt(['email' => $request->email, 'password' => $request->password]);
        } else {
            Auth::guard('user')->attempt(['email' => $request->email, 'password' => $request->password]);
            $team = Team::where('user_id', $this->user->id)->first();

            session()->put('current_organization', $team->organization_id);
        }
    }
}