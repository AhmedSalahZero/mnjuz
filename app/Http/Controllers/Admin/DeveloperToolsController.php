<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use Inertia\Inertia;

class DeveloperToolsController extends BaseController
{
    public function index()
    {
        return Inertia::render('Admin/DeveloperTools/Index', [
            'title' => __('Developer Tools'),
            'apiDocsUrl' => url('/docs/api'),
        ]);
    }
}
