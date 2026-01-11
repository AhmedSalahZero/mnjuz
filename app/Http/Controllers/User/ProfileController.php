<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Hash;
use Validator;

class ProfileController extends BaseController
{

    public function update_password(Request $request){
        $validator = Validator::make($request->all(),[
            'old_password' => 'required',
            'password' => 'required|confirmed',
            'password_confirmation' => 'required',
        ]);

        if ($validator->passes()) {
            $user = User::where('id', auth()->user()->id)->first();

            if (Hash::check($request->old_password, $user->password)) { 
                User::where('id', auth()->user()->id)
                    ->update([
                        'password' => Hash::make($request->password),
                    ]);

                return response()->json(['success' => true, 'message'=>__('Password updated successfully!')]);
            } else {
                $validator->getMessageBag()->add('old_password', __('Incorrect password'));  
                return response()->json([
                    'success' => false, 
                    'message' => __('Please correct the errors indicated below'), 
                    'errors'=>$validator->messages()->get('*')
                ], 200);
            }
        }

        return response()->json(['success' => false,'errors'=>$validator->messages()->get('*')]);
    }
}