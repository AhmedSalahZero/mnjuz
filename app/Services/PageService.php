<?php

namespace App\Services;

use App\Http\Resources\PageResource;
use App\Models\Page;
use Illuminate\Support\Facades\Log;

class PageService
{
    /**
     * Get all rows based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function getAll(object $request)
    {
        $emails = (new Page)->listAll($request->query('search'));

        return PageResource::collection($emails);
    }

    /**
     * Retrieve row by its ID.
     *
     * @param string $id
     * @return \App\Models\Page
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getPageByID($request, $id)
    {
        $page = Page::where('id', $id)->first();

        return $page;
    }

    /**
     * Create page.
     *
     * @param Request $request
     * @return \App\Models\Page
     */
    public function create($request)
    {
        $page = Page::create([
            'name' => clean($request->input('name')),
        ]);

        return $page;
    }

    /**
     * Update page.
     *
     * @param Request $request
     * @param number $id
     * @return \App\Models\Page
     */
    public function update($request, $id)
    {
        $page = Page::where('id', $id)->firstOrFail();

        $page->update([
            'name' => $request->input('name'),
            'content' => clean($request->input('content')),
        ]);

        return $page;
    }

    public function delete($id)
    {
        $page = Page::where('id', $id)->delete();

        return $page;
    }
}