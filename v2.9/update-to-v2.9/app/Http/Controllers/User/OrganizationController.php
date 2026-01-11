<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreUserOrganization;
use App\Models\Organization;
use App\Models\Team;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationController extends BaseController
{
    private $organizationService;

    /**
     * OrganizationController constructor.
     *
     * @param UserService $organizationService
     */
    public function __construct()
    {
        $this->organizationService = new OrganizationService();
    }
    
    public function index(){
        $data['organizations'] = Team::with('organization')->where('user_id', auth()->user()->id)->get();
        
        return Inertia::render('User/OrganizationSelect', $data);
    }

    public function selectOrganization(Request $request){
        $organization = Organization::where('uuid', $request->uuid)->first();

        if($organization){
            session()->put('current_organization', $organization->id);
        }

        return to_route('dashboard');
    }

    public function store(StoreUserOrganization $request)
    {
        $organization = $this->organizationService->store($request);

        if($organization){
            session()->put('current_organization', $organization->id);

            return to_route('dashboard');
        }
    }
}