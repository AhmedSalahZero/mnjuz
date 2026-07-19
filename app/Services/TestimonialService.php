<?php

namespace App\Services;

use App\Http\Resources\ReviewResource;
use App\Models\Review;

class TestimonialService
{
    /**
     * Get all Reviews based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $rows = (new Review)->listAll($request->query('search'));

        return ReviewResource::collection($rows);
    }

    /**
     * Store Review
     *
     * @param Request $request
     * @param string $id
     * @return \App\Models\Review
     */
    public function store(object $request, $id = NULL)
    {
        $review = $id === null ? new Review() : Review::where('id', $id)->firstOrFail();
        $review->name = $request->name;
        $review->position = $request->position;
        $review->review = $request->review;
        $review->rating = $request->rating;
        $review->status = $request->status;
        $review->updated_at = now();
        $review->save();

        return $review;
    }

    /**
     * Delete Review
     *
     * @param Request $request
     * @param string $id
     * @return \App\Models\Review
     */
    public function delete($id)
    {
        return Review::where('id', $id)->delete();
    } 
}