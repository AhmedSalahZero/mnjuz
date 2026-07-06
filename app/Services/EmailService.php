<?php

namespace App\Services;

use App\Http\Resources\EmailLogsResource;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Get all emails based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $emails = (new EmailLog)->listAll($request->query('search'));

        return EmailLogsResource::collection($emails);
    }

    /**
     * Get all email templates based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function getTemplates(object $request)
    {
        $templates = (new EmailTemplate)->listAll($request->query('search'));

        return EmailLogsResource::collection($templates);
    }

    /**
     * Retrieve an email template by its ID.
     *
     * @param string $id
     * @return \App\Models\EmailTemplate
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getTemplateByID($request, $id)
    {
        $template = EmailTemplate::where('id', $id)->first();

        return $template;
    }

    /**
     * Update email template.
     *
     * @param Request $request
     * @param number $id
     * @return \App\Models\EmailTemplate
     */
    public function updateTemplate($request, $id)
    {
        $template = EmailTemplate::where('id', $id)->firstOrFail();

        $template->update([
            'subject' => clean($request->input('subject')),
            'body' => clean($request->input('body')),
        ]);

        return $template;
    }
}