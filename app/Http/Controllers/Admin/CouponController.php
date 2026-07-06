<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Models\Coupon;
use App\Http\Requests\StoreCoupon;
use App\Http\Resources\CouponResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule; 
use Inertia\Inertia;
use Helper;
use Session;
use Validator;

class CouponController extends BaseController
{
    public function index(Request $request, $id = null){
        return Inertia::render('Admin/Setting/Coupon', [
            'rows' => CouponResource::collection(
                Coupon::where('deleted_at', null)->latest()->paginate(10)
            ),
        ]);
    }

    public function store(StoreCoupon $request)
    {
        $coupon = new Coupon();
        $coupon->name = $request->name;
        $coupon->code = $request->code;
        $coupon->percentage = $request->percentage;
        $coupon->quantity = $request->quantity;
        $coupon->created_at = date('Y-m-d H:i:s');
        $coupon->updated_at = date('Y-m-d H:i:s');
        $coupon->save();

        return redirect('/admin/coupons')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Coupon added successfully')
            ]
        );
    }

    public function show($id)
    {
        $coupon = Coupon::where('id', $id)->first();
        return response()->json(['success' => true, 'item'=> $coupon]);
    }

    public function update(StoreCoupon $request, $id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->name = $request->name;
        $coupon->code = $request->code;
        $coupon->percentage = $request->percentage;
        $coupon->quantity = $request->quantity;
        $coupon->updated_at = date('Y-m-d H:i:s');
        $coupon->save();

        return redirect('/admin/coupons')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Coupon updated successfully')
            ]
        );
    }

    /**
     * Delete coupon.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->status = 'inactive';
        $coupon->deleted_at = date('Y-m-d H:i:s');
        $coupon->save();

        return redirect('/admin/coupons')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Coupon deleted successfully')
            ]
        );
    }
}