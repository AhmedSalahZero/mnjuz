<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreUserAdmin;
use App\Services\UserService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeamController extends BaseController
{
    private $userService;
    private $role;

    /**
     * TeamController constructor.
     *
     * @param UserService $userService
     * @param string $role
     */
    public function __construct($role = 'admin')
    {
        $this->userService = new UserService($role);
        $this->role = $role;
    }

    /**
     * Display a listing of users.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('Admin/Team/Index', [
            'title' => __('Team'),
            'allowCreate' => true,
            'rows' => $this->userService->get($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Display the details of a specific user.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show(Request $request, $uuid = NULL)
    {
        $res = $this->userService->getByUuid($request, $uuid);

        return Inertia::render('Admin/Team/Show', ['title' => __('Team'), 'user' => $res['user'], 'roles' => $res['roles']]);
    }

    /**
     * Display Form
     *
     * @param $request
     */
    public function create(Request $request)
    {
        $res = $this->userService->getByUuid($request);

        return Inertia::render('Admin/Team/Show', ['title' => __('Create user'), 'user' => $res['user'], 'roles' => $res['roles']]);
    }

    /**
     * Store a newly created user.
     *
     * @param StoreUserAdmin $request
     */
    public function store(StoreUserAdmin $request)
    {
        $this->userService->store($request);

        return redirect('/admin/team/users')->with(
            'status', [
                'type' => 'success', 
                'message' => __('User created successfully!')
            ]
        );
    }

   /**
     * Update the details of a specific user.
     *
     * @param StoreUserAdmin $request
     * @param string $uuid
     */
    public function update(StoreUserAdmin $request, $id)
    {
        $this->userService->update($request, $id);

        return redirect('/admin/team/users')->with(
            'status', [
                'type' => 'success', 
                'message' => __('User updated successfully!')
            ]
        );
    }

    /**
     * Remove a specific user.
     *
     * @param string $uuid
     */
    public function destroy($id)
    {
        $this->userService->destroy($id);
    }
}
