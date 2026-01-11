<?php

namespace App\Http\Controllers;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreProfile;
use App\Http\Requests\StoreProfilePassword;
use App\Http\Requests\StoreProfileAddress;
use App\Http\Requests\StoreProfileTfa;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Hash;
use Redirect;

class ProfileController extends BaseController
{
    public function update(StoreProfile $request)
    {
        $first_name = $request->first_name;
        $last_name = $request->last_name;
        $email = $request->email;
        $phone = $request->phone;

        $response = User::where('id', auth()->user()->id)->update([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
        ]);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Profile updated successfully!')
            ]
        );
    }

    public function updatePassword(StoreProfilePassword $request)
    {
        $old_password = $request->old_password;
        $password = Hash::make($request->password);

        $response = User::where('id', auth()->user()->id)->update([
            'password' => $password,
        ]);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Profile updated successfully!')
            ]
        );
    }

    public function updateTfa(StoreProfileTfa $request)
    {
        $status = $request->status;
        $token = $request->token;
        $userId = auth()->user()->id;

        if ($status === 0) {
            User::where('id', $userId)->update([
                'tfa' => 0,
            ]);

            return Redirect::back()->with('status', [
                'type' => 'success',
                'message' => __('Two-factor authentication disabled successfully!'),
            ]);
        }

        User::where('id', $userId)->update(['tfa' => true]);

        return Redirect::back()->with('status', [
            'type' => 'success',
            'message' => __('Two-factor authentication enabled successfully!'),
        ]);
    }

    public function updateOrganization(StoreProfileAddress $request)
    {
        $organizationId = session('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        $metadataArray['notifications']['enable_sound'] = $request->input('enable_sound_notification');
        $metadataArray['notifications']['tone'] = $request->input('tone');
        $metadataArray['notifications']['volume'] = $request->input('volume');
        $metadataArray['timezone'] = $request->input('timezone');

        $metadataArray['campaigns']['enable_resend'] = $request->input('enable_campaign_resend');
        $metadataArray['campaigns']['move_failed_contacts_to_group'] = $request->input('move_failed_contacts_to_group');
        $metadataArray['campaigns']['resend_intervals'] = $request->input('resend_intervals');
        $metadataArray['campaigns']['failed_campaign_group'] = $request->input('failed_campaign_group');

        $addressArray['street'] = $request->input('address');
        $addressArray['city'] = $request->input('city');
        $addressArray['state'] = $request->input('state');
        $addressArray['zip'] = $request->input('zip');
        $addressArray['country'] = $request->input('country');

        $organizationConfig->name = $request->input('organization_name');
        $organizationConfig->address = json_encode($addressArray);
        $organizationConfig->metadata = json_encode($metadataArray);

        if($organizationConfig->save()){
            return Redirect::back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Organization updated successfully!')
                ]
            );
        } else {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Something went wrong. Refresh the page and try again')
                ]
            );
        }
    }
}