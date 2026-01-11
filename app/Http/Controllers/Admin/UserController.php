<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreUser;
use App\Services\UserService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends BaseController
{
    private $userService;
    private $role;

    /**
     * UserController constructor.
     *
     * @param UserService $userService
     */
    public function __construct($role = 'user')
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
        if ($request->wantsJson()) {
            return response()->json([
                'title' => __('Users'),
                'allowCreate' => true,
                'rows' => $this->userService->get($request), 
                'filters' => $request->all()
            ]);
        } else {
            return Inertia::render('Admin/Customer/Index', [
                'title' => __('Users'),
                'allowCreate' => true,
                'rows' => $this->userService->get($request), 
                'filters' => $request->all()
            ]);
        }
    }

    /**
     * Display the specified user.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show(Request $request, $uuid = NULL)
    {
        $res = $this->userService->getByUuid($request, $uuid);
        return Inertia::render('Admin/Customer/Show', [
            'title' => __('View user'),
            'user' => $res['user'], 
            'organizations' => $res['organizations'], 
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
        $res = $this->userService->getByUuid($request);
        return Inertia::render('Admin/Customer/Show', [
            'title' => __('Create user'),
            'user' => $res['user'], 
            'organizations' => $res['organizations'], 
            'filters' => $request->all()
        ]);
    }

    /**
     * Store a newly created user.
     *
     * @param Request $request
     */
    public function store(StoreUser $request)
    {
        $this->userService->store($request);

        return redirect('/admin/users')->with(
            'status', [
                'type' => 'success', 
                'message' => __('User created successfully!')
            ]
        );
    }

    /**
     * Update the specified user.
     *
     * @param Request $request
     */
    public function update(StoreUser $request, $uuid)
    {
        $this->userService->update($request, $uuid);

        return redirect('/admin/users')->with(
            'status', [
                'type' => 'success', 
                'message' => __('User updated successfully!')
            ]
        );
    }

    /**
     * Remove the specified user.
     *
     * @param String $uuid
     */
    public function destroy($uuid)
    {
        $this->userService->destroy($uuid);
    }
}