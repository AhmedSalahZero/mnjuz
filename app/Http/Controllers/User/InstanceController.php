<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InstanceController extends BaseController
{
    public function index(Request $request){
        return Inertia::render('User/Instance/Index');
    }
}