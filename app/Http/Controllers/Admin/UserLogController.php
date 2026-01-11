<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\UserAdmin;
use Hash;
use Helper;
use Session;
use Validator;

class UserLogController extends BaseController
{
    public function notifications(Request $request)
    {
        return Inertia::render('Admin/UserLog/Notification');
    }

    public function emails(Request $request)
    {
        return Inertia::render('Admin/UserLog/Email');
    }
}