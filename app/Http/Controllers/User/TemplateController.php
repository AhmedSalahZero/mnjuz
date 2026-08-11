<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Validator;
use App\Services\ActivityLogger;

class TemplateController extends BaseController
{
    private function templateService()
    {
        return new TemplateService(session()->get('current_organization'));
    }

    public function index(Request $request, $uuid = null)
    {
        return $this->templateService()->getTemplates($request, $uuid, $request->query('search'));
    }

    public function create(Request $request)
    {
        $response = $this->templateService()->createTemplate($request);

        if ($request->isMethod('post') && $this->wasSuccessful($response)) {
            ActivityLogger::log(ActivityLogger::TEMPLATE_CREATED, (string) $request->input('name'), 'template');
        }

        return $response;
    }

    public function update(Request $request, $uuid)
    {
        return $this->templateService()->updateTemplate($request, $uuid);
    }

    public function delete($uuid)
    {
        $template = \App\Models\Template::where('uuid', $uuid)->first(['id', 'name']);

        $response = $this->templateService()->deleteTemplate($uuid);

        // القالب يُحذف عند واتساب أيضاً، فلا نسجّل إلا عند نجاح العملية.
        if ($this->wasSuccessful($response)) {
            ActivityLogger::log(
                ActivityLogger::TEMPLATE_DELETED,
                $template->name ?? null,
                'template',
                $template->id ?? null
            );
        }

        return $response;
    }

    /** هل انتهت العملية بنجاح؟ الخدمة تُرجع JsonResponse فيها success. */
    private function wasSuccessful($response): bool
    {
        if (!$response instanceof \Illuminate\Http\JsonResponse) {
            return false;
        }

        $data = $response->getData(true);

        return (bool) ($data['success'] ?? false);
    }
}