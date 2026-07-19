<?php

namespace App\Services;

use App\Http\Resources\FaqResource;
use App\Models\Faq;

class FaqService
{
    /**
     * Get all FAQs based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $rows = (new Faq)->listAll($request->query('search'));

        return FaqResource::collection($rows);
    }

    public function getByUuid($uuid = null)
    {
        return Faq::where('id', $uuid)->first();
    }

    /**
     * Store FAQ
     *
     * @param Request $request
     * @param string $id
     * @return \App\Models\Faq
     */
    public function store(object $request, $id = NULL)
    {
        $faq = $id === null ? new Faq() : Faq::where('id', $id)->firstOrFail();
        $faq->question = clean($request->question);
        $faq->answer = clean($request->answer);
        $faq->status = $request->status;
        $faq->created_at = now();
        $faq->updated_at = now();
        $faq->save();

        return $faq;
    }

    /**
     * Delete FAQ
     *
     * @param Request $request
     * @param string $id
     * @return \App\Models\Faq
     */
    public function delete($id)
    {
        return Faq::where('id', $id)->delete();
    } 
}