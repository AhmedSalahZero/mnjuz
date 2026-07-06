<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StorePage;
use App\Services\PageService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PagesController extends BaseController
{
    private $pageService;

    /**
     * EmailTemplateController constructor.
     *
     * @param PageService $pageService
     */
    public function __construct()
    {
        $this->pageService = new pageService();
    }

    /**
     * Display a listing of email templates.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request){
        return Inertia::render('Admin/Setting/Page/Index', [
            'rows' => $this->pageService->getAll($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Display the specified row.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show(Request $request, $id = NULL)
    {
        $row = $this->pageService->getPageByID($request, $id);

        return Inertia::render('Admin/Setting/Page/Show', [
            'page' => $row,
        ]);
    }

    public function store(StorePage $request)
    {
        $page = $this->pageService->create($request);

        return redirect('/admin/settings/page/' . $page->id)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Page created successfully!')
            ]
        );
    }

    /**
     * Update the specified email template.
     *
     * @param Request $request
     */
    public function update(StorePage $request, $id)
    {
        $this->pageService->update($request, $id);

        return redirect('/admin/settings/pages')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Page updated successfully!')
            ]
        );
    }

    public function delete($id)
    {
        $this->pageService->delete($id);

        return redirect('/admin/settings/pages')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Page deleted successfully!')
            ]
        );
    }
}