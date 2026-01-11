<?php

namespace App\Services;

use App\Helpers\Email;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\OrganizationService;
use DB;
use Str;
use Propaganistas\LaravelPhone\PhoneNumber;

class UserService
{
    private $organization;
    private $role;

    public function __construct($role)
    {
        $this->organizationService = new OrganizationService();
        $this->role = $role;
    }

    /**
     * Get all roles based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $users = (new User)->listAll($this->role, $request->query('search'));

        return UserResource::collection($users);
    }

    /**
     * Retrieve a user by its UUID.
     *
     * @param string $uuid
     * @return \App\Models\Role
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getByUuid($request, $id = NULL)
    {
        $roles = Role::all();
    
        if ($id === null) {
            $result = ['user' => null];
    
            if ($this->role === 'user') {
                $result['organizations'] = null;
            } else {
                $result['roles'] = $roles;
            }
    
            return $result;
        }

        $query = User::with('role')->where('id', $id);

        if ($this->role === 'user') {
            $query->where('role', '=', 'user');
            $organizations = $this->organizationService->get($request, $query->first()->id);
            $result = ['user' => $query->first(), 'organizations' => $organizations];
        } else {
            $query->where('role', '!=', 'user');
            $result = ['user' => $query->first(), 'roles' => $roles];
        }

        return $result;
    }

    /**
     * Store a new user based on the provided request data.
     *
     * @param Request $request
     */
    public function store(Object $request)
    {
        $role = $this->role === 'user' ? 'user' : $this->getRoleNameFromUuid($request->input('role'));

        return DB::transaction(function () use ($request, $role) {
            $avatarPath = null;

            if ($request->hasFile('avatar')) {
                $avatarPath = $request->file('avatar')->store('public');
            }

            $newUser = User::create([
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'email' => $request->input('email'),
                'avatar' => $avatarPath,
                'role' => $role,
                'phone' => $request->input('phone') ? phone($request->input('phone'))->formatE164() : null,
                'address' => json_encode([
                    'street' => $request->input('street'),
                    'city' => $request->input('city'),
                    'state' => $request->input('state'),
                    'zip' => $request->input('zip'),
                    'country' => $request->input('country'),
                ]),
                'password' => $request->input('password'),
                'language' => app()->getLocale(),
            ]);

            if($this->role === 'user' && $request->has('organization_name')){
                $timestamp = now()->format('YmdHis');
                $randomString = Str::random(4);
                $userId = $newUser->id;
                $creatorId = auth()->check() ? auth()->user()->id : $newUser->id;

                //Create Organization
                $newOrganization = Organization::create([
                    'identifier' => $timestamp . $userId . $randomString,
                    'name' => $request->input('organization_name'),
                    'created_by' => $creatorId
                ]);

                //Create Team
                $team = Team::create([
                    'organization_id' => $newOrganization->id,
                    'user_id' => $userId,
                    'role' => 'owner',
                    'status' => 'active',
                    'created_by' => $creatorId
                ]);

                $config = Setting::where('key', 'trial_period')->first();
                $has_trial = isset($config->value) && $config->value > 0 ? true : false;

                //Create Subscription
                Subscription::create([
                    'organization_id' => $newOrganization->id,
                    'status' => $has_trial ? 'trial' : 'active',
                    'plan_id' => null,
                    'start_date' => now(),
                    'valid_until' => $has_trial ? date('Y-m-d H:i:s', strtotime('+' . $config->value . ' days')) : now(),
                ]);
            }

            //Send new user registration email
            Email::send('Registration', $newUser);

            return $newUser;
        });
    }

    /**
     * Update an existing user.
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\User
     */
    public function update($request, $id)
    {
        // Retrieve the user by UUID
        $user = User::where('id', $id)->firstOrFail();

        // Determine the role based on the input conditions
        $role = ($user->role === 'user' || $user->role === 'admin') ? $user->role : $this->getRoleNameFromUuid($request->input('role'));
        $avatarPath = null;

        // Check if the request has an avatar file
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
        }

        // Update the user with the provided data, including encrypted password if provided
        $user->update([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => $request->input('email'),
            'avatar' => $avatarPath,
            'role' => $role,
            'phone' => $request->input('phone') ? phone($request->input('phone'))->formatE164() : null,
            'address' => json_encode([
                'street' => $request->street,
                'city' => $request->city,
                'state' => $request->state,
                'zip' => $request->zip,
                'country' => $request->country,
            ]),
        ]);

        return $user;
    }

    /**
     * Remove the specified user.
     *
     * @param string $uuid
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function destroy($id)
    {
        $user = User::where('id', $id)->first();

        if ($user) {
            // Retrieve the organization ID of the user being deleted
            $organizationId = $user->teams()->value('organization_id');

            // Check if the user is an owner in the team
            $isOwner = $user->teams()->where('role', 'owner')->exists();
            
            // Soft delete the user
            $user->delete();

            // Confirm the user has been deleted (soft deleted)
            if ($user->trashed()) {
                // Check if the organization ID is valid
                if ($organizationId) {
                    // Reassign owner role if necessary
                    if ($isOwner) {
                        // Find the next user in the team to assign as the new owner
                        $nextUser = User::whereHas('teams', function ($query) use ($organizationId) {
                            $query->where('organization_id', $organizationId);
                        })->where('id', '!=', $user->id)->first();

                        if ($nextUser) {
                            $team = $nextUser->teams()->where('organization_id', $organizationId)->first();
                            $team->role = 'owner';
                            $team->save();
                        }
                    }

                    // Count the number of users associated with the organization excluding the user being deleted
                    $userCount = User::whereHas('teams', function ($query) use ($organizationId) {
                        $query->where('organization_id', $organizationId);
                    })->where('id', '!=', $user->id)->count();

                    // If the user being deleted is the last user associated with the organization, soft delete the organization
                    if ($userCount === 0) {
                        Organization::find($organizationId)->delete();
                    }
                }
            }
        }
    }

    /**
     * Get the role name from the roles table based on the provided UUID.
     *
     * @param string $uuid
     * @return string
     */
    private function getRoleNameFromUuid($uuid)
    {
        $role = Role::where('uuid', $uuid)->value('name');

        if (!$role) {
            return 'user';
        }

        return $role;
    }
}