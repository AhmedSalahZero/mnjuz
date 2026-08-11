<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreUserOrganization;
use App\Models\Organization;
use App\Models\Team;
use App\Services\OrganizationContextService;
use App\Services\OrganizationService;
use DB;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\ActivityLogger;

class OrganizationController extends BaseController
{
    private $organizationService;
    private OrganizationContextService $organizationContext;

    public function __construct(OrganizationContextService $organizationContext)
    {
        $this->organizationService = new OrganizationService();
        $this->organizationContext = $organizationContext;
    }

    public function index(){
		
        $data['organizations'] = Team::with('organization')->whereHas('organization',function($q){
			$q->where('is_banned',false);
		})->where('user_id', auth()->user()->id)->get();
	
        return Inertia::render('User/OrganizationSelect', $data);
    }

    public function selectOrganization(Request $request){
        $organization = Organization::where('uuid', $request->uuid)->first();

        // SECURITY: previously this set the session for ANY org by uuid,
        // letting an attacker switch into orgs they don't belong to. The
        // service performs a membership check before persisting.
        if ($organization && $this->organizationContext->setCurrent($request->user(), $organization->id)) {
            ActivityLogger::log(
                ActivityLogger::ORGANIZATION_SWITCHED,
                $organization->name,
                'organization',
                $organization->id,
                [],
                (int) $organization->id
            );

            return to_route('dashboard');
        }

        return to_route('user.organization.index');
    }

    public function store(StoreUserOrganization $request)
    {
        $organization = $this->organizationService->store($request);

        if ($organization) {
            $this->organizationContext->setCurrent($request->user(), $organization->id);

            return to_route('dashboard');
        }
    }
}
