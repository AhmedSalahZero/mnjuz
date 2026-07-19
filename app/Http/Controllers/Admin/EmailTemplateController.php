<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreEmailTemplate;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailTemplateController extends BaseController
{
    private $emailTemplateService;

    /**
     * EmailTemplateController constructor.
     *
     * @param EmailService $emailService
     */
    public function __construct()
    {
        $this->emailService = new emailService();
    }

    /**
     * Display a listing of email templates.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request){
        return Inertia::render('Admin/Setting/EmailTemplate/Index', [
            'rows' => $this->emailService->getTemplates($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Display the specified email template.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show(Request $request, $id = NULL)
    {
        $template = $this->emailService->getTemplateByID($request, $id);

        return Inertia::render('Admin/Setting/EmailTemplate/Show', [
            'template' => $template,
        ]);
    }

    /**
     * Update the specified email template.
     *
     * @param Request $request
     */
    public function update(StoreEmailTemplate $request, $id)
    {
        $this->emailService->updateTemplate($request, $id);

        return redirect('/admin/settings/email-templates')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Template updated successfully!')
            ]
        );
    }
}