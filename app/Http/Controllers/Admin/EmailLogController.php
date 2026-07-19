<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailLogController extends BaseController
{
    private $EmailService;

    /**
     * EmailController constructor.
     *
     * @param EmailService $emailService
     */
    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Display a listing of email logs.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('Admin/UserLog/Email', [
            'rows' => $this->emailService->get($request), 
            'filters' => $request->all()
        ]);
    }
}