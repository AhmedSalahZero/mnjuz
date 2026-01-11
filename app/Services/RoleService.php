<?php

namespace App\Services;

use App\Http\Resources\RoleResource;
use App\Models\Module;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use DB;

class RoleService
{
    /**
     * Get all roles based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $roles = (new Role)->listAll($request->query('search'));

        return RoleResource::collection($roles);
    }

    /**
     * Retrieve a role by its UUID.
     *
     * @param string $uuid
     * @return \App\Models\Role
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getByUuid($uuid)
    {
        $role = $uuid ? Role::where('uuid', $uuid)->first() : null;

        $modules = Module::all();
        $permissions = [];

        if ($role) {
            $permissions = RolePermission::where('role_id', $role->id)->get();
        }

        return ['role' => $role, 'modules' => $modules, 'permissions' => $permissions];
    }

    /**
     * Store a new role based on the provided request data.
     *
     * @param Request $request
     */
    public function store(Object $request)
    {
        return DB::transaction(function () use ($request) {
            $newRole = Role::create([
                'name' => $request->input('name'),
            ]);

            // Extract and store permissions in the form permissions[module|action]
            $permissions = $request->input('permissions', []);

            $test = [];
            foreach ($permissions as $module => $actions) {
                // Loop through actions for each module
                foreach ($actions as $action => $value) {
                    // If the value is true, store the role permission in the database
                    if ($value) {
                        RolePermission::create([
                            'role_id' => $newRole->id,
                            'module' => $module,
                            'action' => $action,
                        ]);
                    }
                }
            }

            return $newRole;
        });
    }

    /**
     * Update an existing role and its associated permissions.
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\Role
     */
    public function update($request, $uuid)
    {
        return DB::transaction(function () use ($request, $uuid) {
            $role = Role::where('uuid', $uuid)->firstOrFail();

            $role->update([
                'name' => $request->input('name'),
            ]);

            // Extract and store permissions in the form permissions[module|action]
            $permissions = $request->input('permissions', []);

            // Delete existing permissions
            RolePermission::where('role_id', $role->id)->delete();

            $test = [];
            foreach ($permissions as $module => $actions) {
                // Loop through actions for each module
                foreach ($actions as $action => $value) {
                    // If the value is true, store the role permission in the database
                    if ($value) {
                        RolePermission::create([
                            'role_id' => $role->id,
                            'module' => $module,
                            'action' => $action,
                        ]);
                    }
                }
            }

            $role->touch();

            return $role;
        });
    }

    /**
     * Check if role has users assigned to it.
     *
     * @param string $uuid
     * @return array
     */
    public function checkUsers($uuid)
    {
        $role = Role::where('uuid', $uuid)->firstOrFail();
        $users = User::where('role', $role->name)->get();
        
        return [
            'has_users' => $users->count() > 0,
            'user_count' => $users->count(),
            'users' => $users->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email
                ];
            }),
            'role' => [
                'uuid' => $role->uuid,
                'name' => $role->name
            ]
        ];
    }

    /**
     * Transfer users and delete role.
     *
     * @param string $uuid
     * @param string $newRoleUuid
     * @return void
     */
    public function destroyWithTransfer($uuid, $newRoleUuid)
    {
        $role = Role::where('uuid', $uuid)->firstOrFail();
        $newRole = Role::where('uuid', $newRoleUuid)->firstOrFail();
        $usersToUpdate = User::where('role', $role->name)->get();

        // Update the role for each user
        foreach ($usersToUpdate as $user) {
            $user->update([
                'role' => $newRole->name,
            ]);
        }

        // Delete the role and its associated permissions
        $role->delete();
    }

    /**
     * Directly delete a role without user transfer.
     *
     * @param string $uuid
     * @return void
     */
    public function destroyDirect($uuid)
    {
        $role = Role::where('uuid', $uuid)->firstOrFail();
        
        // Delete the role and its associated permissions
        $role->delete();
    }

    /**
     * Remove the specified role and its associated permissions.
     *
     * @param string $uuid
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function destroy($request, $uuid)
    {
        $role = Role::where('uuid', $uuid)->firstOrFail();
        $usersToUpdate = User::where('role', $role->name)->get();
        $newRole = Role::where('uuid', $request->input('new_role'))->firstOrFail();

        // Update the role for each user
        foreach ($usersToUpdate as $user) {
            $user->update([
                'role' => $newRole->name, // Specify the new role here
            ]);
        }

        // Delete the role and its associated permissions
        $role->delete();

        // Return a response indicating successful deletion
        return response()->json(['message' => 'Role and associated permissions deleted successfully']);
    }
}