<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreOrganization;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\OrganizationContextService;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OrganizationController extends BaseController
{
    private $organizationService;
    private $role;

    /**
     * OrganizationController constructor.
     *
     * @param UserService $organizationService
     */
    public function __construct()
    {
        $this->organizationService = new OrganizationService();
    }

    /**
     * Display a listing of organizations.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('Admin/Organization/Index', [
            'title' => __('Organizations'),
            'allowCreate' => true,
            'rows' => $this->organizationService->get($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Display the specified organization.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show(Request $request, $uuid = NULL, $mode = NULL)
    {
        $res = $this->organizationService->getByUuid($request, $uuid);
        return Inertia::render('Admin/Organization/Show', [
            'title' => __('Organization'),
            'organization' => $res['organization'], 
            'users' => $res['users'],
            'plans' => $res['plans'], 
            'invoices' => $res['billing'],
            'mode' => $mode,
            'filters' => $request->all()
        ]);
    }

    /**
     * Display Form
     *
     * @param $request
     */
    public function create(Request $request)
    {
        $res = $this->organizationService->getByUuid($request);
        return Inertia::render('Admin/Organization/Show', [
            'title' => __('Create Org.'),
            'organization' => $res['organization'], 
            'users' => $res['users'],
            'plans' => $res['plans'], 
            'invoices' => $res['billing'],
            'filters' => $request->all()
        ]);
    }

    /**
     * Store a newly created organization.
     *
     * @param Request $request
     */
    public function store(StoreOrganization $request)
    {
        $this->organizationService->store($request);

        return redirect('/admin/organizations')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Organization created successfully!')
            ]
        );
    }

    /**
     * Update the specified organization.
     *
     * @param Request $request
     */
    public function update(StoreOrganization $request, $uuid)
    {
        $this->organizationService->update($request, $uuid);

        return redirect('/admin/organizations/'.$uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Organization updated successfully!')
            ]
        );
    }

    /**
     * Remove the specified organization.
     *
     * @param String $uuid
     */
    public function destroy($uuid)
    {
        $query = $this->organizationService->destroy($uuid);

        return back()->with(
            'status', [
                'type' => $query ? 'success' : 'error', 
                'message' => $query ? __('Organization deleted successfully!') : __('This organization does not exist!')
            ]
        );
    }
	public function toggleBanStatus($uuid)
	{
	
		$toggle = !Organization::where('uuid', $uuid)->first()->is_banned;
		Organization::where('uuid', $uuid)->update([
			'is_banned' => (bool)$toggle
		]);
		return response()->json(['status' => 'success']);
	
	}

	/**
	 * Log in as the organization owner (or first team member) to use the customer panel for this workspace.
	 * Session flags allow returning to the admin account without clearing the customer's current organization.
	 */
	public function enterAsManager(Request $request, string $uuid, OrganizationContextService $organizationContext)
	{
		$admin = Auth::guard('admin')->user();
		if (!$admin || $admin->role === 'user') {
			abort(403);
		}

		$organization = Organization::where('uuid', $uuid)->firstOrFail();

		$team = Team::query()
			->with('user')
			->where('organization_id', $organization->id)
			->where('role', 'owner')
			->first()
			?? Team::query()
				->with('user')
				->where('organization_id', $organization->id)
				->orderBy('id')
				->first();

		if (!$team) {
			return redirect()->back()->with('status', [
				'type' => 'error',
				'message' => __('This organization has no team members.'),
			]);
		}

		$targetUser = $team->user;
		if (!$targetUser || $targetUser->role !== 'user') {
			return redirect()->back()->with('status', [
				'type' => 'error',
				'message' => __('No valid customer account found for this organization.'),
			]);
		}

		session()->put('admin_org_impersonation', true);
		session()->put('impersonation_admin_id', $admin->id);
		session()->put('admin_impersonation_org_name', $organization->name);

		Auth::guard('admin')->logout();

		Auth::guard('user')->login($targetUser);
		$request->session()->regenerate();

		if (!$organizationContext->setCurrent($targetUser, $organization->id)) {
			Auth::guard('user')->logout();
			Auth::guard('admin')->login($admin);
			session()->forget(['admin_org_impersonation', 'impersonation_admin_id', 'admin_impersonation_org_name']);

			return redirect()->back()->with('status', [
				'type' => 'error',
				'message' => __('Could not open this organization (inactive, banned, or access denied).'),
			]);
		}

		return redirect()->route('dashboard');
	}

	/**
	 * End customer-panel preview and restore the admin session.
	 */
	public function exitManagerPreview(Request $request)
	{
		if (!session('admin_org_impersonation') || !session('impersonation_admin_id')) {
			abort(403);
		}

		$adminId = (int) session('impersonation_admin_id');
		$admin = User::query()->whereKey($adminId)->first();
		if (!$admin instanceof User || $admin->role === 'user') {
			session()->forget(['admin_org_impersonation', 'impersonation_admin_id', 'admin_impersonation_org_name']);
			abort(403);
		}

		session()->forget(['admin_org_impersonation', 'impersonation_admin_id', 'admin_impersonation_org_name']);
		session()->put('skip_clear_organization_on_logout', true);
		Auth::guard('user')->logout();
		Auth::guard('admin')->login($admin);
		$request->session()->regenerate();

		return redirect('/admin/organizations')->with('status', [
			'type' => 'success',
			'message' => __('Returned to admin panel.'),
		]);
	}
}
