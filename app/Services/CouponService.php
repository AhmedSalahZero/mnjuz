<?php

namespace App\Services;

use App\Http\Resources\CouponResource;
use App\Models\Coupon;

class CouponService
{
    /**
     * Get all coupons based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $rows = Coupon::where('deleted_at', null)->latest()->paginate(10);

        return CouponResource::collection($rows);
    }

    /**
     * Store Coupon
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\BlogCategory
     */
    public function storeCategory(object $request, $uuid = NULL)
    {
        $category = $uuid === null ? new BlogCategory() : BlogCategory::where('uuid', $uuid)->firstOrFail();

        $category->name = $request->name;
        $category->created_by = auth()->user()->id;
        $category->save();

        return $category;
    }

    /**
     * Delete Coupon
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\BlogPost
     */
    public function deletePost($request, $uuid)
    {
        return BlogPost::where('uuid', $uuid)->update(['deleted' => 1]);
    } 
}